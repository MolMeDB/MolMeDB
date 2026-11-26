<?php

namespace App\Console\Commands;

use App\Models\Structure;
use Illuminate\Console\Command;
use Modules\Rdkit\Rdkit;

class JoinStructureRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'structures:join-records {id1} {id2}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Takes two structure IDs and joins the second into the first one, reassigning all related data.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking structures...');

        $substance_1 = Structure::where('id', $this->argument('id1'))->first();
        $substance_2 = Structure::where('id', $this->argument('id2'))->first();

        if (! $substance_1 || ! $substance_2) {
            $this->error('One of the structures does not exist.');

            return;
        }

        $rdkit = new Rdkit;

        if (! $rdkit->is_connected()) {
            $this->error('Rdkit is not connected. Stopping...');

            return;
        }

        $smiles_1 = $rdkit->canonize_smiles($substance_1->canonical_smiles);
        $smiles_2 = $rdkit->canonize_smiles($substance_2->canonical_smiles);

        if (! $smiles_1 || ! $smiles_2) {
            $this->error('Could not canonize one of the structures. Stopping...');

            return;
        }

        if ($smiles_1 != $smiles_2) {
            $this->error('The structures have different SMILES.');

            return;
        }

        Structure::join_structures($substance_1, $substance_2);

        // Two records of one molecule may differ in the stored form (one
        // written by an older RDKit, say); the record that stays gets the
        // current one, so that a lookup by canonical SMILES finds it.
        if ($substance_1->canonical_smiles !== $smiles_1) {
            $substance_1->forceFill(['canonical_smiles' => $smiles_1])->save();
        }

        $this->info('Done.');
    }
}
