<?php

namespace App\Services\Structures;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Rdkit\Rdkit;
use RuntimeException;

/**
 * Converts the stored canonical SMILES of a table (structures, or the
 * structures of the predictions database) to the form the RDKit service
 * writes now, so that a plain equality on canonical_smiles keeps telling
 * whether a molecule is already stored.
 *
 * scan() reads every record and asks the service for the form of its stored
 * SMILES into a temporary table of the connection; it changes nothing.
 * Records whose forms would be equal to each other (two records of one
 * molecule, one of them written by an older RDKit, say) are marked and never
 * converted: which of them stays is the user's decision
 * (structures:join-records). apply() then writes the forms in chunks, each
 * after a check that no record written meanwhile holds the form already.
 */
class CanonicalSmilesConversion
{
    public const TARGET_STRUCTURES = 'structures';

    public const TARGET_PREDICTIONS = 'predictions';

    public const BINGO_INDEX = 'structures_canonical_smiles_bingo_index';

    private const WORK_TABLE = 'canonical_smiles_conversion';

    /** How many records a report lists at most */
    private const LISTED = 200;

    private ?Connection $db = null;

    private ?string $target = null;

    public function __construct(private readonly Rdkit $rdkit) {}

    /**
     * Reads the records and computes their forms; writes nothing but the
     * temporary table of the connection.
     *
     * @param  Closure(int $read, int $total): void|null  $progress
     */
    public function scan(string $target, int $chunk = 1000, ?Closure $progress = null): CanonicalSmilesConversionPlan
    {
        $this->open($target);

        if (! $this->rdkit->is_connected()) {
            throw new RuntimeException('The RDKit service is out of reach.');
        }

        $chunk = max(1, min($chunk, Rdkit::CANONIZE_BATCH_MAX));
        $total = $this->records()->whereNotNull('canonical_smiles')->count();
        $read = 0;

        $this->records()
            ->select(['id', 'canonical_smiles'])
            ->whereNotNull('canonical_smiles')
            ->chunkById($chunk, function ($rows) use (&$read, $total, $progress): void {
                $stored = $rows->map(fn (object $row): string => (string) $row->canonical_smiles)->values();
                $forms = $this->rdkit->canonize_smiles_batch($stored->map(fn (string $smiles): string => trim($smiles))->all());

                if ($forms === null) {
                    throw new RuntimeException('The RDKit service did not canonize a batch of SMILES; nothing was changed.');
                }

                $this->db->table(self::WORK_TABLE)->insert($rows->values()->map(fn (object $row, int $i): array => [
                    'id' => (int) $row->id,
                    'old_smiles' => $stored[$i],
                    'new_smiles' => $forms[$i],
                    'collision' => false,
                ])->all());

                $read += $rows->count();

                if ($progress) {
                    $progress($read, $total);
                }
            }, 'id');

        // Temporary tables are never analyzed on their own; without it the
        // planner guesses the size of this one.
        if ($this->db->getDriverName() === 'pgsql') {
            $this->db->statement('ANALYZE '.self::WORK_TABLE);
        }

        $this->markCollisions();

        return $this->plan();
    }

    /**
     * Writes the forms of the scanned records that do not collide, in
     * chunks. A record changed since the scan is left as it is, and so is
     * one whose form another record has taken meanwhile.
     *
     * @param  Closure(int $done, int $total): void|null  $progress
     * @return array{converted: int, changed_meanwhile: int, collided_meanwhile: int}
     */
    public function apply(int $chunk = 1000, ?Closure $progress = null): array
    {
        $this->assertScanned();

        $converted = 0;
        $changedMeanwhile = 0;
        $collidedMeanwhile = 0;
        $done = 0;
        $total = $this->convertible()->count();
        $lastId = 0;

        while (true) {
            $ids = $this->convertible()
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(max(1, $chunk))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($ids === []) {
                break;
            }

            $lastId = end($ids);

            $this->db->transaction(function () use ($ids, &$converted, &$changedMeanwhile, &$collidedMeanwhile): void {
                $this->lockStructuresIndex();

                // A record written since the scan may hold the form already
                // (removed records collide with nothing, as in the scan).
                $taken = $this->db->table(self::WORK_TABLE.' as c')
                    ->join($this->table().' as s', function ($join): void {
                        $join->on('s.canonical_smiles', '=', 'c.new_smiles')->whereColumn('s.id', '<>', 'c.id');
                    })
                    ->when($this->target === self::TARGET_STRUCTURES, fn ($query) => $query
                        ->join($this->table().' as own', 'own.id', '=', 'c.id')
                        ->whereNull('own.deleted_at')
                        ->whereNull('s.deleted_at'))
                    ->whereIn('c.id', $ids)
                    ->distinct()
                    ->pluck('c.id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                if ($taken !== []) {
                    $this->db->table(self::WORK_TABLE)->whereIn('id', $taken)->update(['collision' => true]);
                    $collidedMeanwhile += count($taken);
                }

                $free = array_values(array_diff($ids, $taken));

                if ($free === []) {
                    return;
                }

                $placeholders = implode(',', array_fill(0, count($free), '?'));
                $updated = $this->db->update(
                    'UPDATE '.$this->table().' AS s SET canonical_smiles = c.new_smiles'
                    .' FROM '.self::WORK_TABLE.' AS c'
                    .' WHERE c.id = s.id AND s.canonical_smiles = c.old_smiles AND c.id IN ('.$placeholders.')',
                    $free,
                );

                $converted += $updated;
                $changedMeanwhile += count($free) - $updated;
            });

            $done += count($ids);

            if ($progress) {
                $progress($done, $total);
            }
        }

        return [
            'converted' => $converted,
            'changed_meanwhile' => $changedMeanwhile,
            'collided_meanwhile' => $collidedMeanwhile,
        ];
    }

    /**
     * Rebuilds the Bingo index of structures, so that it holds the converted
     * records without the entries the updates left behind. False when there
     * is no index to rebuild (another database than PostgreSQL with Bingo).
     */
    public function rebuildIndex(): bool
    {
        $db = DB::connection();

        if ($db->getDriverName() !== 'pgsql') {
            return false;
        }

        $exists = (bool) $db->scalar(
            'SELECT EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?)',
            [self::BINGO_INDEX],
        );

        if ($exists) {
            $db->statement('REINDEX INDEX '.self::BINGO_INDEX);
        }

        return $exists;
    }

    /**
     * How many scanned records Bingo rejects as they are stored and how many
     * it would still reject after the conversion (with the first of them),
     * or null when the connection has no Bingo.
     *
     * @return array{rejected_now: int, rejected_after: int, still_rejected: array<int, array{id: int, smiles: string, error: string}>}|null
     */
    public function bingoReadability(): ?array
    {
        $this->assertScanned();

        if (! $this->hasBingo()) {
            return null;
        }

        // Bingo checks each stored form once, and the new form only where it
        // differs; only the rejected records come back.
        $after = 'CASE WHEN collision OR new_smiles IS NULL THEN old_smiles ELSE new_smiles END';
        // (OFFSET 0 keeps the planner from inlining the subqueries, which
        // would call Bingo again for every reference to their columns.)
        $rejected = collect($this->db->select(
            'SELECT id, smiles, error_now, error_after FROM ('
            .' SELECT id, smiles, error_now, CASE WHEN smiles = old_smiles THEN error_now ELSE bingo.checkmolecule(smiles::text) END AS error_after'
            .' FROM (SELECT id, old_smiles, '.$after.' AS smiles, bingo.checkmolecule(old_smiles::text) AS error_now FROM '.self::WORK_TABLE.' OFFSET 0) stored'
            .' OFFSET 0) checked'
            .' WHERE error_now IS NOT NULL OR error_after IS NOT NULL'
            .' ORDER BY id',
        ));
        $stillRejected = $rejected->filter(fn (object $row): bool => $row->error_after !== null);

        return [
            'rejected_now' => $rejected->filter(fn (object $row): bool => $row->error_now !== null)->count(),
            'rejected_after' => $stillRejected->count(),
            'still_rejected' => $stillRejected->take(self::LISTED)->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'smiles' => (string) $row->smiles,
                'error' => (string) $row->error_after,
            ])->values()->all(),
        ];
    }

    private function open(string $target): void
    {
        $connection = match ($target) {
            self::TARGET_STRUCTURES => DB::getDefaultConnection(),
            self::TARGET_PREDICTIONS => (string) config('database.default_predictions'),
            default => throw new InvalidArgumentException("Unknown target [{$target}]."),
        };

        $this->target = $target;
        $this->db = DB::connection($connection);

        $schema = $this->db->getSchemaBuilder();
        $schema->dropIfExists(self::WORK_TABLE);
        $schema->create(self::WORK_TABLE, function (Blueprint $table): void {
            $table->temporary();
            $table->unsignedBigInteger('id')->primary();
            $table->text('old_smiles');
            $table->text('new_smiles')->nullable();
            $table->boolean('collision')->default(false);
        });
    }

    /**
     * Marks every scanned record whose form another record of the table that
     * is not removed would hold too: another scanned one, or one written
     * since the scan.
     */
    private function markCollisions(): void
    {
        $ids = collect($this->db->select($this->collidingSql().' SELECT t.id FROM target t JOIN colliding k ON k.form = t.form'))
            ->map(fn (object $row): int => (int) $row->id);

        foreach ($ids->chunk(1000) as $chunk) {
            $this->db->table(self::WORK_TABLE)->whereIn('id', $chunk->values()->all())->update(['collision' => true]);
        }
    }

    /**
     * The form each record of the table would hold after the conversion:
     * its new form when it is scanned and unchanged since, else what it holds.
     */
    private function collidingSql(): string
    {
        // A removed structure sharing the form of a record that stays is what
        // structures:join-records leaves behind; it collides with nothing. The
        // prediction structures are never removed by the application.
        $active = $this->target === self::TARGET_STRUCTURES
            ? 'CASE WHEN s.deleted_at IS NULL THEN 1 ELSE 0 END'
            : '1';

        return 'WITH target AS ('
            .' SELECT s.id, CASE'
            .' WHEN c.id IS NULL OR s.canonical_smiles <> c.old_smiles OR c.new_smiles IS NULL THEN s.canonical_smiles'
            .' ELSE c.new_smiles END AS form, '.$active.' AS active'
            .' FROM '.$this->table().' s LEFT JOIN '.self::WORK_TABLE.' c ON c.id = s.id'
            .' WHERE s.canonical_smiles IS NOT NULL'
            .' ), colliding AS (SELECT form FROM target GROUP BY form HAVING SUM(active) > 1)';
    }

    private function plan(): CanonicalSmilesConversionPlan
    {
        $work = fn () => $this->db->table(self::WORK_TABLE);

        $collisions = collect($this->db->select(
            $this->collidingSql()
            .' SELECT t.id, t.form, s.canonical_smiles AS stored'
            .($this->target === self::TARGET_STRUCTURES ? ', s.identifier, s.deleted_at' : ', NULL AS identifier, NULL AS deleted_at')
            .' FROM target t JOIN colliding k ON k.form = t.form JOIN '.$this->table().' s ON s.id = t.id'
            .' ORDER BY t.form, t.id',
        ))
            ->groupBy('form')
            ->map(fn ($rows, string $form): array => [
                'form' => $form,
                'records' => $rows->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'identifier' => $row->identifier,
                    'stored' => (string) $row->stored,
                    'deleted' => $row->deleted_at !== null,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return new CanonicalSmilesConversionPlan(
            target: (string) $this->target,
            scanned: $work()->count(),
            unchanged: $work()->whereColumn('new_smiles', 'old_smiles')->count(),
            convertible: $this->convertible()->count(),
            unreadable: $work()->whereNull('new_smiles')->orderBy('id')->limit(self::LISTED)
                ->get(['id', 'old_smiles'])
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'stored' => (string) $row->old_smiles])
                ->all(),
            unreadableCount: $work()->whereNull('new_smiles')->count(),
            collisions: $collisions,
        );
    }

    private function convertible(): Builder
    {
        return $this->db->table(self::WORK_TABLE)
            ->where('collision', false)
            ->whereNotNull('new_smiles')
            ->whereColumn('new_smiles', '<>', 'old_smiles');
    }

    private function records(): Builder
    {
        return $this->db->table($this->table());
    }

    private function table(): string
    {
        return 'structures';
    }

    /**
     * Takes the lock every writer of structures takes (the trigger of
     * 2026_11_22_124947), so that no structure is written between the check
     * of a chunk and its update.
     */
    private function lockStructuresIndex(): void
    {
        if ($this->target === self::TARGET_STRUCTURES && $this->db->getDriverName() === 'pgsql') {
            $this->db->statement('SELECT pg_advisory_xact_lock(hashtext(?))', [self::BINGO_INDEX]);
        }
    }

    private function hasBingo(): bool
    {
        if ($this->db->getDriverName() !== 'pgsql') {
            return false;
        }

        return (bool) $this->db->scalar(
            "SELECT EXISTS (SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'bingo' AND p.proname = 'checkmolecule')",
        );
    }

    private function assertScanned(): void
    {
        if ($this->db === null) {
            throw new RuntimeException('scan() has to run first.');
        }
    }
}
