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
        if (server::remote_addr() != server::server_addr() &&
			server::remote_addr() != "127.0.0.1")
		{
			echo 'access denied';
			die();
		}
        
        try
        {
            // ---- Molecules data autofill ---- //
            $this->substance_autofill_missing_data();
        }
        catch(Exception $e)
        {
            // Save to the file!
            print_r($e);
        }
        
    }


    /**
     * Autofill molecules missing info
     * 
     * @author Jakub Juračka
     */
    private function substance_autofill_missing_data()
    {
        $substance_model = new Substances();
        $rdkit = new Rdkit();
        $fileModel = new File();
        $unichem = new UniChem();

        $scheduler_log = new Log_scheduler();
        
        // In one run, check max 1000 molecules
        $substances = $substance_model->where(array
            (
                'validated != ' => Validator::VALIDATED,
                'validated !=' => Validator::POSSIBLE_DUPLICITY,
            ))
            ->limit(1000)
            ->get_all();

        $total_substances = count($substances);
        $success = 0;

        // Hold report with errors
        $report = new Scheduler_report(Scheduler_report::T_SUBSTANCE);

        // For each substance, get data and fill missing
        foreach($substances as $s)
        {
            try
            {
                // Fill substance info if missing
                if($s->validated < Validator::SUBSTANCE_FILLED)
                {
                    // Fill identifier, if missing
                    if(!$s->identifier)
                    {
                        $s->identifier = Identifiers::generate_substance_identifier($s->id);
                    }

                    // If not smiles, then continue
                    if(!$s->SMILES || $s->SMILES == '')
                    {
                        // If not exists SMILES, try to find in another DBs
                        $s->SMILES = $this->find_smiles($s);
                    }

                    $data = $rdkit->get_general_info($s->SMILES);

                    if(!$data)
                    {
                        // If error, try to find new SMILES
                        $s->SMILES = $this->find_smiles($s);
                        $data = $rdkit->get_general_info($s->SMILES);

                        if(!$data)
                        {
                            throw new Exception('INVALID RESPONSE WHILE GETTING RDKIT GENERAL INFO.');
                        }
                    }

                    $canonized = $data->canonized_smiles;
                    $inchikey = $data->inchi;
                    $MW = $data->MW;
                    $logP = $data->LogP;

                    // Check duplicities
                    if($canonized)
                    {
                        $exists_smiles = $substance_model->where(array
                            (
                                'SMILES' => $canonized,
                                'id !=' => $s->id
                            ))
                            ->get_one();
                        
                        if($exists_smiles->id)
                        {
                            $err_text = "Found possible duplicity. Found [$exists_smiles->identifier] with the same SMILES = $s->SMILES.";
                            $s->add_duplicity($exists_smiles->id, $err_text);

                            throw new Exception($err_text);
                        }
                    }
                    if($inchikey)
                    {
                        $exists_inchikey = $substance_model->where(array
                            (
                                'inchikey' => $inchikey,
                                'id !=' => $s->id
                            ))
                            ->get_one();

                        if($exists_inchikey->id)
                        {
                            $err_text = "Found possible duplicity. Found [$exists_inchikey->identifier] with the same inchikey = $s->inchikey.";
                            $s->add_duplicity($exists_inchikey->id, $err_text);

                            throw new Exception($err_text);
                        }
                    }

                    // Fill missing values
                    $s->SMILES = $canonized ? $canonized : $s->SMILES;
                    $s->inchikey = $inchikey ? $inchikey : $s->inchikey;
                    $s->MW = $s->MW ? $s->MW : $MW;
                    $s->LogP = $s->LogP ? $s->LogP : $logP;
                    // Set as filled_data state
                    $s->validated = Validator::SUBSTANCE_FILLED;

                    $s->save();
                }

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

                if($s->validated < Validator::IDENTIFIERS_FILLED)
                {
                    // Find identifiers by inchikey
                    if($s->inchikey && $unichem->is_connected())
                    {
                        // Get identifiers by Unichem service
                        $identifiers = $unichem->get_identifiers($s->inchikey);

                        if($identifiers)
                        {
                            // Check duplicities
                            if($identifiers->pubchem)
                            {
                                $exists = $substance_model->where(array
                                    (
                                    'pubchem' => $identifiers->pubchem,
                                    'id !=' => $s->id
                                    ))
                                    ->get_one();

                                if($exists->id)
                                {
                                    $err_text = "Found possible duplicity. Found [$exists->identifier] with the same pubchem_id = $identifiers->pubchem.";
                                    $s->add_duplicity($exists->id, $err_text);

                                    throw new Exception($err_text);
                                }
                            }
                            if($identifiers->drugbank)
                            {
                                $exists = $substance_model->where(array
                                    (
                                    'drugbank' => $identifiers->drugbank,
                                    'id !=' => $s->id
                                    ))
                                    ->get_one();

                                if($exists->id)
                                {
                                    $err_text = "Found possible duplicity. Found [$exists->identifier] with the same drugbank_id = $identifiers->drugbank.";
                                    $s->add_duplicity($exists->id, $err_text);

                                    throw new Exception($err_text);
                                }
                            }
                            if($identifiers->chebi)
                            {
                                $exists = $substance_model->where(array
                                    (
                                    'chEBI' => $identifiers->chebi,
                                    'id !=' => $s->id
                                    ))
                                    ->get_one();

                                if($exists->id)
                                {
                                    $err_text = "Found possible duplicity. Found [$exists->identifier] with the same chebi_id = $identifiers->chebi.";
                                    $s->add_duplicity($exists->id, $err_text);

                                    throw new Exception($err_text);
                                }
                            }
                            if($identifiers->chembl)
                            {
                                $exists = $substance_model->where(array
                                    (
                                    'chEMBL' => $identifiers->chembl,
                                    'id !=' => $s->id
                                    ))
                                    ->get_one();

                                if($exists->id)
                                {
                                    $err_text = "Found possible duplicity. Found [$exists->identifier] with the same chembl_id = $identifiers->chembl.";
                                    $s->add_duplicity($exists->id, $err_text);

                                    throw new Exception($err_text);
                                }
                            }
                            if($identifiers->pdb)
                            {
                                $exists = $substance_model->where(array
                                    (
                                    'pdb' => $identifiers->pdb,
                                    'id !=' => $s->id
                                    ))
                                    ->get_one();

                                if($exists->id)
                                {
                                    $err_text = "Found possible duplicity. Found [$exists->identifier] with the same pdb_id = $identifiers->pdb.";
                                    $s->add_duplicity($exists->id, $err_text);

                                    throw new Exception($err_text);
                                }
                            }

                            $s->pubchem = $s->pubchem ? $s->pubchem : $identifiers->pubchem;
                            $s->drugbank = $s->drugbank ? $s->drugbank : $identifiers->drugbank;
                            $s->chEBI = $s->chEBI ? $s->chEBI : $identifiers->chebi;
                            $s->chEMBL = $s->chEMBL ? $s->chEMBL : $identifiers->chembl;
                            $s->pdb = $s->pdb ? $s->pdb : $identifiers->pdb;
                            $s->validated = Validator::IDENTIFIERS_FILLED;

                            $s->save();
                        }
                        else if(!$s->pubchem && !$s->chEMBL)
                        {
                            $s->validated = Validator::IDENTIFIERS_FILLED;
                            $s->save();

                            throw new Exception("Cannot find identifiers. Will be ignored in next run.");
                        }
                    }

                    else
                    {
                        throw new Exception("Invalid inchikey. Cannot find identifiers.");
                    }
                }

                if($s->validated < Validator::LogP_FILLED)
                {
                    // With filled in identifiers, try to fill LogPs
                    $this->fill_logP($s);
                }

                $s->validated = Validator::VALIDATED;
                $s->save();

                $success++;
            }
            catch(Exception $e)
            {
                $report->add('Substance: ' . $s->identifier, $e->getMessage());
            }
        }

        // Make new record about scheduler run
        $scheduler_log->error_count = $total_substances - $success;
        $scheduler_log->success_count = $success;
        $scheduler_log->save();

        if(!$report->is_empty())
        {
            $report->save_report();
            $scheduler_log->report_path = $report->get_file_path();
            $scheduler_log->save();

            // Send email with report
        }
    }

    /**
     * Fills logPs for given substance
     * 
     * @param Substances $s
     */
    private function fill_logP($s)
    {
        $interaction_model = new Interactions();

        // Try to find LogPs in another DBs
        if($s->pubchem)
        {
            $pubchem = new Pubchem();

            if($pubchem->is_connected())
            {
                $data = $pubchem->get_data($s->pubchem);

                if($data && $data->logP && $data->logP != '')
                {
                    $membrane = $pubchem->get_logP_membrane();
                    $method = $pubchem->get_logP_method();
                    $dataset = $pubchem->get_dataset();
                    $publication = $pubchem->get_publication();

                    if(!$membrane || !$membrane->id || !$method || !$method->id ||
                        !$dataset || !$dataset->id || !$publication || !$publication->id)
                    {
                        throw new Exception('Pubchem default membrane/method/dataset/publication was not found!');
                    }

                    // Exists yet in DB?
                    $exists = $interaction_model->where(
                        array
                        (
                            'id_substance'  => $s->id,
                            'id_membrane'   => $membrane->id,
                            'id_method'     => $method->id,
                            'id_reference'  => $publication->id,
                            'id_dataset'    => $dataset->id
                        ))
                        ->get_one();

                    if($exists->id)
                    {
                        $exists->LogK = $data->logP;
                        $exists->save();
                    }
                    else
                    {
                        $interaction = new Interactions();

                        $interaction->id_substance = $s->id;
                        $interaction->id_membrane = $membrane->id;
                        $interaction->id_method = $method->id;
                        $interaction->id_dataset = $dataset->id;
                        $interaction->id_reference = $publication->id;
                        $interaction->LogK = $data->logP;
                        $interaction->temperature = NULL;
                        $interaction->charge = NULL;
                        $interaction->user_id = NULL;
                        $interaction->validated = Validator::VALIDATED;
                        $interaction->visibility = Interactions::VISIBLE;

                        $interaction->save();
                    }
                }
            }
        }
        if($s->chEMBL)
        {
            $chembl = new Chembl();

            if($chembl->is_connected())
            {
                $data = $chembl->get_molecule_data($s->chEMBL);

                if($data && $data->LogP && $data->LogP != '')
                {
                    $membrane = $chembl->get_logP_membrane();
                    $method = $chembl->get_logP_method();
                    $dataset = $chembl->get_dataset();
                    $publication = $chembl->get_publication();

                    if(!$membrane || !$membrane->id || !$method || !$method->id ||
                        !$dataset || !$dataset->id || !$publication || !$publication->id)
                    {
                        throw new Exception('ChEMBL default membrane/method/dataset/publication was not found!');
                    }

                    // Exists yet in DB?
                    $exists = $interaction_model->where(
                        array
                        (
                            'id_substance'  => $s->id,
                            'id_membrane'   => $membrane->id,
                            'id_method'     => $method->id,
                            'id_reference'  => $publication->id,
                            'id_dataset'    => $dataset->id
                        ))
                        ->get_one();

                    if($exists->id)
                    {
                        $exists->LogK = $data->LogP;
                        $exists->save();
                    }
                    else
                    {
                        $interaction = new Interactions();

                        $interaction->id_substance = $s->id;
                        $interaction->id_membrane = $membrane->id;
                        $interaction->id_method = $method->id;
                        $interaction->id_dataset = $dataset->id;
                        $interaction->id_reference = $publication->id;
                        $interaction->LogK = $data->LogP;
                        $interaction->temperature = NULL;
                        $interaction->charge = NULL;
                        $interaction->user_id = NULL;
                        $interaction->validated = Validator::VALIDATED;
                        $interaction->visibility = Interactions::VISIBLE;

                        $interaction->save();
                    }
                }
            }
        }

        $s->validated = Validator::LogP_FILLED;
        $s->save();
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
        $chembl = new Chembl();

        if(!$pubchem->is_connected() && !$chembl->is_connected())
        {
            throw new Exception('Cannot connect to the remote servers.');
        }

        // If missing identifiers, try to find by name on remote servers
        if(!$substance->pubchem)
        {
            $remote_data = NULL;

            if($substance->inchikey)
            {
                $remote_data = $pubchem->search($substance->inchikey, Pubchem::INCHIKEY);
            }

            if(!$remote_data && !Identifiers::is_valid($substance->name))
            {
                $remote_data = $pubchem->search($substance->name, Pubchem::NAME);
            }

            if($remote_data && $remote_data->pubchem_id)
            {
                $substance->pubchem = $remote_data->pubchem_id;
                $substance->save();
            }
        }

        // try to find on pubchem
        if($pubchem->is_connected() && $substance->pubchem)
        {
            $data = $pubchem->get_data($substance->pubchem);

            if($data->SMILES && $data->SMILES !== '')
            {
                return $data->SMILES;
            }
        }

        // try to find on chembl
        if($chembl->is_connected() && $substance->chEMBL)
        {
            $data = $chembl->get_molecule_data($substance->chEMBL);
            
            if($data && $data->SMILES && $data->SMILES !== '')
            {
                return $data->SMILES;
            }
        }

        throw new Exception("Cannot find SMILES.");
    }
}