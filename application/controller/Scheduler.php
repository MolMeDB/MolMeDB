<?php

/**
 * Scheduler controller for cron service
 * 
 * Should be accessed only from local machine
 * 
 * @author Jakub Juračka
 */
class SchedulerController extends Controller
{

    /**
     * Constructor
     */
    function __construct()
    {
        parent::__construct();
    }

    /**
     * Should be an only public function!
     * 
     * // ADD STATS
     */
    public function run()
    {
        // Can be access only from local machine
        $substance_model = new Substances();
        $rdkit = new Rdkit();
        $fileModel = new File();
        $unichem = new UniChem();
        
        try
        {
            // ---- Molecules data autofill ----
            // In one run, check max 100 molecules
            $substances = $substance_model->where(array
                (
                    'validated' => Validator::NOT_VALIDATED
                ))
                ->limit(2)
                ->get_all();

            // For each substance, get data and fill missing
            foreach($substances as $s)
            {
                try
                {
                    // If not smiles, then continue
                    if(!$s->SMILES || $s->SMILES == '')
                    {
                        // If not exists SMILES, try to find in another DBs
                        $smiles = $this->find_smiles($s);
                        $s->SMILES = $smiles;
                    }

                    $data = $rdkit->get_general_info($s->SMILES);

                    if(!$data)
                    {
                        throw new Exception('INVALID RESPONSE WHILE GETTING RDKIT GENERAL INFO.');
                    }

                    $canonized = $data->canonized_smiles;
                    $inchikey = $data->inchi;
                    $MW = $data->MW;
                    $logP = $data->LogP;

                    // Check duplicities
                    $exists_smiles = $substance_model->where(array
                        (
                            'SMILES' => $canonized,
                            'id !=' => $s->id
                        ))
                        ->get_one();
                    $exists_inchikey = $substance_model->where(array
                        (
                            'inchikey' => $inchikey,
                            'id !=' => $s->id
                        ))
                        ->get_one();

                    if($canonized && $exists_smiles->id)
                    {
                        throw new Exception("Found possible duplicity. Found [$exists_smiles->identifier] with the same SMILES = $s->SMILES.");
                    }

                    if($inchikey && $exists_inchikey->id)
                    {
                        throw new Exception("Found possible duplicity. Found [$exists_inchikey->identifier] with the same inchikey = $s->inchikey.");
                    }

                    // Fill missing values
                    $s->SMILES = $canonized ? $canonized : $s->SMILES;
                    $s->inchikey = $inchikey ? $inchikey : $s->inchikey;
                    $s->MW = $s->MW ? $s->MW : $MW;
                    $s->LogP = $s->LogP ? $s->LogP : $logP;

                    $s->save();

                    // Generate 3d structure file if missing
                    if(!$fileModel->structure_file_exists($s->identifier))
                    {
                        $content = $rdkit->get_3d_structure($s->SMILES);

                        if($content)
                        {
                            # Save new structure
                            $fileModel->save_structure_file($s->identifier, $content);
                        }
                    }

                    if($s->inchikey)
                    {
                        $identifiers = $unichem->get_identifiers($s->inchikey);

                        if($identifiers)
                        {
                            // Check duplicities
                            if($identifiers->pubchem)
                            {
                                $exists_pubchem = $substance_model->where(array(
                                    'pubchem' => $identifiers->pubchem,
                                    'id !=' => $s->id
                                    ))
                                    ->get_one();

                                if($exists_pubchem->id)
                                {
                                    throw new Exception("Found possible duplicity. Found [$exists_pubchem->identifier] with the same pubchem_id = $identifiers->pubchem.");
                                }
                            }
                            if($identifiers->drugbank)
                            {
                                $exists = $substance_model->where(array(
                                    'drugbank' => $identifiers->drugbank,
                                    'id !=' => $s->id
                                    ))
                                    ->get_one();

                                if($exists->id)
                                {
                                    throw new Exception("Found possible duplicity. Found [$exists->identifier] with the same drugbank_id = $identifiers->drugbank.");
                                }
                            }
                            if($identifiers->chebi)
                            {
                                $exists = $substance_model->where(array(
                                    'chEBI' => $identifiers->chebi,
                                    'id !=' => $s->id
                                    ))
                                    ->get_one();

                                if($exists->id)
                                {
                                    throw new Exception("Found possible duplicity. Found [$exists->identifier] with the same chebi_id = $identifiers->chebi.");
                                }
                            }
                            if($identifiers->chembl)
                            {
                                $exists = $substance_model->where(array(
                                    'chEMBL' => $identifiers->chembl,
                                    'id !=' => $s->id
                                    ))
                                    ->get_one();

                                if($exists->id)
                                {
                                    throw new Exception("Found possible duplicity. Found [$exists->identifier] with the same chembl_id = $identifiers->chembl.");
                                }
                            }
                            if($identifiers->pdb)
                            {
                                $exists = $substance_model->where(array(
                                    'pdb' => $identifiers->pdb,
                                    'id !=' => $s->id
                                    ))
                                    ->get_one();

                                if($exists->id)
                                {
                                    throw new Exception("Found possible duplicity. Found [$exists->identifier] with the same pdb_id = $identifiers->pdb.");
                                }
                            }


                            $s->pubchem = $s->pubchem ? $s->pubchem : $identifiers->pubchem;
                            $s->drugbank = $s->drugbank ? $s->drugbank : $identifiers->drugbank;
                            $s->chEBI = $s->chEBI ? $s->chEBI : $identifiers->chebi;
                            $s->chEMBL = $s->chEMBL ? $s->chEMBL : $identifiers->chembl;
                            $s->pdb = $s->pdb ? $s->pdb : $identifiers->pdb;

                            $s->save();
                        }
                        else
                        {
                            throw new Exception("Cannot find identifiers.");
                        }
                    }
                    else
                    {
                        throw new Exception("Invalid inchikey.");
                    }


                }
                catch(Exception $e)
                {
                    print_r("Error occured while proccessing molecule [$s->identifier]. " . $e->getMessage());
                    die;
                }
            }
        }
        catch(Exception $e)
        {
            print_r($e);
        }
        
    }

    /**
     * Finds smiles in another DBs for given substance IDs
     * 
     * @param Substances $substance
     * 
     * @return string
     */
    private function find_smiles($substance)
    {
        $pubchem = new Pubchem();

        if(!$pubchem->is_connected())
        {
            throw new Exception('Cannot connect to the pubchem server.');
        }

        if($substance->pubchem)
        {
            $data = $pubchem->get_data($substance->pubchem);
            
            if($data->CanonicalSMILES && $data->CanonicalSMILES !== '')
            {
                return $data->CanonicalSMILES;
            }

            if($data->IsomericSMILES && $data->IsomericSMILES !== '')
            {
                return $data->IsomericSMILES;
            }

            throw new Exception('Cannot find SMILES on PUBCHEM server.');
        }
    }
}