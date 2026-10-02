<?php

namespace App\Libraries\Export;

use App\Models\Dataset;
use App\Models\Filesystem;
use App\Models\Identifier;
use App\Models\Publication;
use App\Models\Structure;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\References\EuropePMC\Enums\Sources;
use stdClass;
use Throwable;
use ZipArchive;

class ExportToFile
{
    const PREFIX = '';

    const CONTEXT_MEMBRANE = 'membrane';

    const CONTEXT_METHOD = 'method';

    const CONTEXT_PUBLICATION = 'publication';

    const CONTEXT_DOWNLOADER = 'downloader';

    const TYPE_CSV = 'csv';

    private ?string $fullFilePath = null;

    private $fileHandler = null;

    private $header = null;

    private $isHeaderWritten = false;

    protected static $storage;

    public function __construct(
        private string $context,
        public ?string $filename = null,
        private ?string $folder = null,
        private string $filetype = self::TYPE_CSV,
        private ?Filesystem $filesystem = null
    ) {
        self::$storage = Storage::disk($filesystem->systemName);
        if (! $filename) {
            $this->filename = date('Y-m-d');
        }
    }

    public function getTargetFolder()
    {
        return self::PREFIX
            .trim($this->context, '/')
            .'/'
            .trim($this->folder, '/');
        // return public_path(
        //     self::PREFIX
        //     . trim($this->context, '/')
        //     . '/'
        //     . trim($this->folder, '/')
        // );
    }

    public function getFileName()
    {
        $this->fullFilePath =
            $this->getTargetFolder()
            .'/'
            .ltrim($this->filename, '/')
            .$this->getSuffix();

        return $this->fullFilePath;
    }

    public function getSuffix()
    {
        return match ($this->filetype) {
            self::TYPE_CSV => '.csv'
        };
    }

    public function initFile($mode = 'w')
    {
        if (! $this->fullFilePath) {
            $this->getFileName();
        }

        // if(!is_dir(dirname($this->fullFilePath)))
        // {
        //     mkdir(dirname($this->fullFilePath), 0777, true);
        // }

        // $this->fileHandler = fopen($this->fullFilePath, $mode);

        self::$storage->put($this->fullFilePath, '');
        $this->fileHandler = fopen(self::$storage->path($this->fullFilePath), $mode);

        if ($this->fileHandler === false) {
            throw new Exception('Could not open file: '.$this->fullFilePath);
        }

        return $this;
    }

    public function setHeader(ExportFileHeader $header)
    {
        $this->header = $header;
        $this->isHeaderWritten = false;

        return $this;
    }

    public function writeHeader()
    {
        if (! $this->header) {
            return false;
        }

        if ($this->fileHandler === null) {
            $this->initFile();
        }

        if (! $this->isHeaderWritten) {
            $this->writeRow($this->header->as_array());
            $this->isHeaderWritten = true;
        }

        return $this;
    }

    public function writeRow(Model|stdClass|array|string $row, $separator = ';')
    {
        if ($this->fileHandler === null) {
            $this->initFile();
        }

        if (is_array($row)) {
            fputcsv($this->fileHandler, $row, $separator, '"', '\\');
        } elseif (is_string($row)) {
            fwrite($this->fileHandler, $row.PHP_EOL);
        } else {
            if (! $this->header || ! $this->isHeaderWritten) {
                throw new Exception('Header is not written to the target file.');
            }

            $toWrite = [];
            foreach ($this->header->items as $column) {
                $toWrite[] = $column->getValue($row);
            }

            fputcsv($this->fileHandler, $toWrite, $separator, '"', '\\');
        }

        return $this;
    }

    public function closeFile()
    {
        if ($this->fileHandler !== null) {
            try {
                if (! fclose($this->fileHandler)) {
                    // Nepodařilo se zavřít soubor, log nebo další akce
                    trigger_error('Failed to close the file.', E_USER_WARNING);
                }
            } catch (Throwable $e) {
                // Ošetření neočekávané chyby
                trigger_error('Error closing file: '.$e->getMessage(), E_USER_WARNING);
            }
            $this->fileHandler = null;
        }

        return $this;
    }

    public function getZipFilePath()
    {
        return $this->getTargetFolder().'/'.$this->filename.'.zip';
    }

    /**
     * @deprecated
     */
    public function getRelativeZipFilePath()
    {
        return $this->getZipFilePath();
        // return ltrim(str_replace(public_path(), '', $this->getZipFilePath()), '');
    }

    public function checkIfFileIsNonempty()
    {
        if (! $this->fullFilePath
            || ! self::$storage->exists($this->fullFilePath)) {
            trigger_error('File is not initialized or does not exist.', E_USER_WARNING);

            return false;
        }

        $lineCount = 0;

        $handle = fopen(self::$storage->path($this->fullFilePath), 'r');
        if ($handle) {
            while (! feof($handle)) {
                $content = fgets($handle);

                if (strlen(trim($content)) <= 1) {
                    return false;
                }

                $lineCount++;
                if ($lineCount > 1) {
                    break;
                }
            }
            fclose($handle);
        }

        return $lineCount > 1;
    }

    public function zip($filename = null)
    {
        if (! $this->checkIfFileIsNonempty()) {
            self::$storage->delete($this->fullFilePath);

            return null;
        }

        if (! $this->fullFilePath || ! self::$storage->exists($this->fullFilePath)) {
            trigger_error('File is not initialized or does not exist.', E_USER_WARNING);

            return null;
        }

        // $filename = $filename ?? $this->filename;
        $target = $this->getZipFilePath();
        try {
            $zip = new ZipArchive;
            if ($zip->open(self::$storage->path($target), ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                if (! $zip->addFile(self::$storage->path($this->fullFilePath), basename($this->fullFilePath))) {
                    trigger_error('Failed to add file to zip.', E_USER_WARNING);
                }
                $zip->close();
            } else {
                trigger_error('Failed to create zip archive.', E_USER_WARNING);
            }
        } catch (Throwable $e) {
            trigger_error('Error creating zip: '.$e->getMessage(), E_USER_WARNING);
        }

        return $this;
    }

    public function keepLastChanged()
    {
        // Compare with the last dump and check hash. If the same, remove the old one.
        $current = self::$storage->path($this->getZipFilePath());

        if (! file_exists($current)) {
            return $this;
        }

        $existing = glob($this->getTargetFolder().'/*.zip');

        if (empty($existing)) {
            return $this;
        }

        rsort($existing);

        foreach ($existing as $zip) {
            if ($zip === $current) {
                continue;
            }

            $zipExisting = new ZipArchive;
            if ($zipExisting->open($zip) === true) {
                $lastContent = $zipExisting->getFromIndex(0);
                $zipExisting->close();

                if (md5($lastContent) === md5_file($this->fullFilePath)) {
                    unlink($zip);
                }
            }

            break;
        }

        return $this;
    }

    public function deleteFile()
    {
        if ($this->fullFilePath && self::$storage->exists($this->fullFilePath)) {
            try {
                self::$storage->delete($this->fullFilePath);
            } catch (Throwable $e) {
                trigger_error('Error deleting file: '.$e->getMessage(), E_USER_WARNING);
            }
        }

        return $this;
    }

    public static function streamCsvDownload(string $filename, ExportFileHeader $header, iterable $rows, string $separator = ';')
    {
        return response()->streamDownload(function () use ($header, $rows, $separator): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new Exception('Could not open output stream.');
            }

            fputcsv($output, $header->as_array(), $separator, '"', '\\');

            foreach ($rows as $row) {
                $toWrite = [];
                foreach ($header->items as $column) {
                    $toWrite[] = $column->getValue($row);
                }

                fputcsv($output, $toWrite, $separator, '"', '\\');
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Citation, DOI and PubMed ID select columns for a joined publications alias.
     *
     * The PubMed ID is only filled in when the publication is identified by a
     * PubMed/Medline identifier, mirroring Publication::getPmidAttribute().
     *
     * @return array<int, string|\Illuminate\Contracts\Database\Query\Expression>
     */
    public static function publicationColumns(string $alias, string $prefix): array
    {
        return [
            $alias.'.citation as '.$prefix.'_citation',
            $alias.'.doi as '.$prefix.'_doi',
            DB::raw(
                "CASE WHEN {$alias}.identifier_source = '".Sources::MED->value."'"
                ." THEN {$alias}.identifier END as {$prefix}_pubmed_id"
            ),
        ];
    }

    /**
     * Citation, DOI and PubMed ID row values for an already loaded publication.
     *
     * @return array<string, mixed>
     */
    public static function publicationFields(string $prefix, ?Publication $publication): array
    {
        return [
            $prefix.'_citation' => $publication?->citation,
            $prefix.'_doi' => $publication?->doi,
            $prefix.'_pubmed_id' => $publication?->pmid,
        ];
    }

    public static function prepareIdentifierQuery($identifier = Identifier::TYPE_NAME): Builder
    {
        return DB::table(
            DB::raw('(SELECT value, structure_id, ROW_NUMBER() OVER (PARTITION BY structure_id ORDER BY state DESC, id ASC) AS rn
                            FROM identifiers
                            WHERE type = '.$identifier.' AND state != '.Identifier::STATE_INVALID.') as t')
        )
            ->where('rn', 1)
            ->select('value', 'structure_id');
    }

    // public static function structurePassiveInteractionsQuery(Structure $record): Builder
    // {
    //     return DB::query()
    //         ->from('interactions_passive')
    //         ->join('datasets', 'datasets.id', '=', 'interactions_passive.dataset_id')
    //         ->join('methods as met', 'met.id', '=', 'datasets.method_id')
    //         ->join('membranes as mem', 'mem.id', '=', 'datasets.membrane_id')
    //         ->leftJoin('model_has_publications as mhp', function ($join): void {
    //             $join->on('mhp.model_id', '=', 'datasets.id')
    //                 ->where('mhp.model_type', Dataset::class);
    //         })
    //         ->leftJoin('publications as pub2', 'pub2.id', '=', 'mhp.publication_id')
    //         ->leftJoin('publications as pub', 'pub.id', '=', 'interactions_passive.publication_id')
    //         ->join('structures as s', 's.id', '=', 'interactions_passive.structure_id')
    //         ->leftJoinSub(self::prepareIdentifierQuery(Identifier::TYPE_NAME), 'name', function ($join): void {
    //             $join->on('s.id', '=', 'name.structure_id');
    //         })
    //         ->leftJoinSub(self::prepareIdentifierQuery(Identifier::TYPE_PDB), 'pdb', function ($join): void {
    //             $join->on('s.id', '=', 'pdb.structure_id');
    //         })
    //         ->leftJoinSub(self::prepareIdentifierQuery(Identifier::TYPE_PUBCHEM), 'pubchem', function ($join): void {
    //             $join->on('s.id', '=', 'pubchem.structure_id');
    //         })
    //         ->leftJoinSub(self::prepareIdentifierQuery(Identifier::TYPE_DRUGBANK), 'drugbank', function ($join): void {
    //             $join->on('s.id', '=', 'drugbank.structure_id');
    //         })
    //         ->where('interactions_passive.structure_id', $record->id)
    //         ->orderBy('interactions_passive.id')
    //         ->select(
    //             'interactions_passive.*',
    //             's.identifier',
    //             's.canonical_smiles',
    //             's.logp',
    //             's.molecular_weight as mw',
    //             's.inchikey',
    //             'mem.abbreviation as membrane',
    //             'met.abbreviation as method',
    //             'pdb.value as pdb',
    //             'pubchem.value as pubchem',
    //             'drugbank.value as drugbank',
    //             'name.value as name',
    //             'pub.citation as primary_citation',
    //             'pub2.citation as secondary_citation',
    //         );
    // }

    public static function structureActiveInteractionsQuery(Structure $record): Builder
    {
        return DB::query()
            ->from('interactions_active')
            ->join('datasets', 'datasets.id', '=', 'interactions_active.dataset_id')
            ->join('proteins as p', 'p.id', '=', 'interactions_active.protein_id')
            ->leftJoin('model_has_publications as mhp', function ($join): void {
                $join->on('mhp.model_id', '=', 'datasets.id')
                    ->where('mhp.model_type', Dataset::class);
            })
            ->leftJoin('publications as pub2', 'pub2.id', '=', 'mhp.publication_id')
            ->leftJoin('publications as pub', 'pub.id', '=', 'interactions_active.publication_id')
            ->join('structures as s', 's.id', '=', 'interactions_active.structure_id')
            ->leftJoinSub(self::prepareIdentifierQuery(Identifier::TYPE_NAME), 'name', function ($join): void {
                $join->on('s.id', '=', 'name.structure_id');
            })
            ->leftJoinSub(self::prepareIdentifierQuery(Identifier::TYPE_PDB), 'pdb', function ($join): void {
                $join->on('s.id', '=', 'pdb.structure_id');
            })
            ->leftJoinSub(self::prepareIdentifierQuery(Identifier::TYPE_PUBCHEM), 'pubchem', function ($join): void {
                $join->on('s.id', '=', 'pubchem.structure_id');
            })
            ->leftJoinSub(self::prepareIdentifierQuery(Identifier::TYPE_DRUGBANK), 'drugbank', function ($join): void {
                $join->on('s.id', '=', 'drugbank.structure_id');
            })
            ->where('interactions_active.structure_id', $record->id)
            ->orderBy('interactions_active.id')
            ->select(
                'interactions_active.*',
                'p.uniprot_id as protein',
                's.identifier',
                's.canonical_smiles',
                's.logp',
                's.molecular_weight as mw',
                's.inchikey',
                'pdb.value as pdb',
                'pubchem.value as pubchem',
                'drugbank.value as drugbank',
                'name.value as name',
                ...self::publicationColumns('pub', 'primary'),
                ...self::publicationColumns('pub2', 'secondary'),
            );
    }
}
