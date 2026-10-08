<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Author;
use App\Models\Category;
use App\Models\Dataset;
use App\Models\File;
use App\Models\Filesystem;
use App\Models\Identifier;
use App\Models\ProteinIdentifier;
use Illuminate\Support\Facades\Storage;

/**
 * A small but complete MolMeDB world for contract tests: every field an
 * endpoint can return is filled, so the recorded contracts describe the full
 * response shape. Values are fixed to keep the contracts stable.
 *
 * @return array{structure: \App\Models\Structure, form: \App\Models\Structure, membrane: \App\Models\Membrane, method: \App\Models\Method, protein: \App\Models\Protein, publication: \App\Models\Publication, passiveDataset: Dataset, activeDataset: Dataset, passive: \App\Models\InteractionPassive, active: \App\Models\InteractionActive}
 */
function seedApiContractWorld(): array
{
    $membraneRoot = createApiRootCategory(Category::TYPE_MEMBRANE, 'Membranes');
    $methodRoot = createApiRootCategory(Category::TYPE_METHOD, 'Methods');
    $proteinRoot = createApiRootCategory(Category::TYPE_PROTEIN, 'Proteins');

    $membrane = createApiMembrane(['name' => 'Egg PC mixture', 'abbreviation' => 'EggPC'], createApiChildCategory($membraneRoot, 'Phospholipids'));
    $method = createApiMethod(['name' => 'Parallel Artificial Membrane Permeation Assay', 'abbreviation' => 'PAMPA'], createApiChildCategory($methodRoot, 'Experimental'));
    $protein = createApiProtein(['uniprot_id' => 'O15244'], createApiChildCategory($proteinRoot, 'SLC transporters'));

    ProteinIdentifier::create([
        'protein_id' => $protein->id,
        'value' => 'SLC22A2',
        'type' => ProteinIdentifier::TYPE_NAME,
        'state' => ProteinIdentifier::STATE_VALIDATED,
    ]);

    $publication = createApiPublication([
        'citation' => 'Doe J., Roe R.: Permeability of caffeine. J Pharm Sci, Volume 21 (4), 429-441, 2004',
        'title' => 'Permeability of caffeine',
        'doi' => '10.1016/j.ejps.2003.10.009',
        'identifier' => '14998573',
        'identifier_source' => 'MED',
        'journal' => 'J Pharm Sci',
        'volume' => '21',
        'issue' => '4',
        'page' => '429-441',
        'year' => 2004,
    ]);

    foreach (['Doe J', 'Roe R'] as $name) {
        $publication->authors()->attach(Author::create(['first_name' => $name, 'last_name' => null])->id);
    }

    $structure = createApiStructure([
        'identifier' => 'MM00040',
        'canonical_smiles' => 'CN1C=NC2=C1C(=O)N(C(=O)N2C)C',
        'inchi' => 'InChI=1S/C8H10N4O2/c1-10-4-9-6-5(10)7(13)12(3)8(14)11(6)2/h4H,1-3H3',
        'inchikey' => 'RYYVLZVUVIJVGH-UHFFFAOYSA-N',
        'molecular_weight' => 194.19,
        'logp' => -0.07,
    ]);

    foreach ([
        [Identifier::TYPE_NAME, 'Caffeine'],
        [Identifier::TYPE_PUBCHEM, '2519'],
        [Identifier::TYPE_CHEBI, 'CHEBI:27732'],
        [Identifier::TYPE_DRUGBANK, 'DB00201'],
    ] as [$type, $value]) {
        Identifier::create([
            'structure_id' => $structure->id,
            'type' => $type,
            'value' => $value,
            'state' => Identifier::STATE_VALIDATED,
        ]);
    }

    $form = createApiStructure([
        'identifier' => 'MM00040.1',
        'parent_id' => $structure->id,
        'canonical_smiles' => 'CN1C=[NH+]C2=C1C(=O)N(C(=O)N2C)C',
        'charge' => 1,
        'molecular_weight' => 195.2,
        'logp' => -0.5,
    ]);

    $passiveDataset = createApiDataset([
        'type' => Dataset::TYPE_PASSIVE,
        'membrane' => $membrane,
        'method' => $method,
        'publication' => $publication,
    ]);

    $activeDataset = createApiDataset([
        'type' => Dataset::TYPE_ACTIVE,
        'membrane' => $membrane,
        'method' => $method,
        'publication' => $publication,
    ]);

    $passive = createApiPassiveInteraction([
        'structure' => $structure,
        'dataset' => $passiveDataset,
        'publication' => $publication,
    ]);

    $active = createApiActiveInteraction([
        'structure' => $structure,
        'dataset' => $activeDataset,
        'publication' => $publication,
        'protein' => $protein,
    ]);

    // createApiExportFile() fakes the export disk on every call, so only the
    // first file is created through it and the others are put on the same disk.
    createApiExportFile($membrane, File::TYPE_EXPORT_INTERACTIONS_MEMBRANE, 'membrane export');
    $exportDisk = Storage::disk(Filesystem::where('type', Filesystem::TYPE_EXPORTS)->firstOrFail()->systemName);

    foreach ([
        [$method, File::TYPE_EXPORT_INTERACTIONS_METHOD],
        [$publication, File::TYPE_EXPORT_INTERACTIONS_PASSIVE_PUBLICATION],
        [$publication, File::TYPE_EXPORT_INTERACTIONS_ACTIVE_PUBLICATION],
    ] as [$owner, $type]) {
        $path = "exports/contract-{$type}.zip";
        $exportDisk->put($path, 'export');

        $file = File::create(['type' => $type, 'path' => $path, 'mime' => 'application/zip', 'hash' => md5('export'), 'name' => 'export']);
        $owner->files()->attach($file->id, ['model_type' => $owner::class]);
    }

    return compact('structure', 'form', 'membrane', 'method', 'protein', 'publication', 'passiveDataset', 'activeDataset', 'passive', 'active');
}
