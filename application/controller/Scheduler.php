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
     * Main scheduler funtion
     * 
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

        // Holds info about run time
        $time = $this->time = new Time();

        echo '--- RUN ' . $time->get_string() . " ---\n";
        
        try
        {
            if(!$this->config->get(Configs::S_ACTIVE))
            {
                echo 'Scheduler is not active.';
                return;
            }

            // Send all unsent emails
            $this->send_emails();
            echo "Emails sent. \n";

            // ---- Molecules data autofill ---- //
            if($time->is_minute(1) && ($time->get_hour() > 20 || $time->get_hour() < 3)) // Run only once per hour and only at night
            {
                $this->substance_autofill_missing_data($time->get_hour() % 2);
                echo "Subtance validations done. \n";
            }

            // Checks interactions datasets
            if($time->is_hour(4) && $time->is_minute(1))
            {
                $this->check_interaction_datasets();
                echo "Interaction datasets checked.\n";
            }

            // Checks interactions datasets - NOW INACTIVATED
            if(FALSE && $time->is_hour(5) && $time->is_minute(1))
            {
                $this->check_transporter_datasets();
                echo "Transporter datasets checked.\n";
            }

            echo "DONE \n";
        }
        catch(Exception $e)
        {
            $text = "General error occured while scheduler was running.<br/><br/>" . $e->getMessage();
            echo $text . ' in ' . $time->get_string();

            try
            {
                $report = new File();
                $filePath = MEDIA_ROOT . 'files/schedulerReports/general_' . $report->generateName() . '.log';
                $report->create($filePath);
                $report->writeLine($e->getMessage());
            }
            catch(Exception $e)
            {
                echo 'Cannot make report file - ' . $e->getMessage();
                $this->send_email_to_admins($text, 'Scheduler error');
                return;
            }
            
            $this->send_email_to_admins($text, 'Scheduler error', $filePath);
        }

        echo '-------------------------';
    }

    /**
     * Checks interactions datasets
     * 
     * @author Jakub Juracka      
     */
    private function check_interaction_datasets()
    {
        $dataset_model = new Datasets();
        $interaction_model = new Interactions();
        $removed = 0;

        // Join the same datasets
        // Same -> membrane/method/secondary reference/author + the same upload day
        $duplcs = $dataset_model->get_duplicites();
        
        foreach($duplcs as $ids)
        {
            $final_id = array_shift($ids)['id'];

            // For each dataset, change interaction dataset id
            foreach($ids as $dataset_id)
            {
                $interaction_model->change_dataset_id($dataset_id['id'], $final_id);
            }
        }

        if(count($duplcs))
        {
            echo 'Total ' . count($duplcs) . " datasets were joined. \n";
        }

        // Remove empty datasets
        $empty_datasets = $dataset_model->get_empty_datasets();

        foreach($empty_datasets as $dataset)
        {
            $total_int = $dataset->get_count_interactions();
            
            if($total_int > 0)
            {
                continue;
            }

            $dataset->delete();
            $removed++;
        }

        if($removed > 0)
        {
            echo 'Total ' . $removed . " empty datasets were removed. \n";
        }
    }

    /**
     * Checks transporter datasets 
     * 
     * @NOT USED NOW
     * 
     * @author Jakub Juracka      
     */
    private function check_transporter_datasets()
    {
        return;
        $dataset_model = new Transporter_datasets();
        $interaction_model = new Transporters();
        $removed = 0;

        // Join the same datasets
        // Same -> membrane/method/secondary reference/author + the same upload day
        // $duplcs = $dataset_model->get_duplicites();
        
        // foreach($duplcs as $ids)
        // {
        //     $final_id = array_shift($ids)['id'];

        //     // For each dataset, change interaction dataset id
        //     foreach($ids as $dataset_id)
        //     {
        //         $interaction_model->change_dataset_id($dataset_id['id'], $final_id);
        //     }
        // }

        // if(count($duplcs))
        // {
        //     echo 'Total ' . count($duplcs) . " datasets were joined. \n";
        // }

        // // Remove empty datasets
        // $empty_datasets = $dataset_model->get_empty_datasets();

        // foreach($empty_datasets as $dataset)
        // {
        //     $total_int = $dataset->get_count_interactions();
            
        //     if($total_int > 0)
        //     {
        //         continue;
        //     }

        //     $dataset->delete();
        //     $removed++;
        // }

        // if($removed > 0)
        // {
        //     echo 'Total ' . $removed . " empty datasets were removed. \n";
        // }
    }


     /**
     * Sends unsent emails
     */
    private function send_emails()
    {
        $email_model = new Email_queue();
        $sender = new Email();

        $emails = $email_model->where(array
            (
                'status !=' => Email_queue::SENT
            ))
            ->get_all();

        foreach($emails as $e)
        {
            $record = new Email_queue($e->id);

            if(!$record->id)
            {
                continue;
            }

            try
            {
                $email_model->beginTransaction();
                // Send email
                $sender->send([$record->recipient_email], $record->subject, $record->text, $record->file_path);

                // Change status
                $record->status = Email_queue::SENT;
                $record->last_attemp_date = date('Y-m-d H:i:s');
                $record->save();

                $email_model->commitTransaction();
            }
            catch(Exception $e)
            {
                $email_model->rollbackTransaction();
                $record->status = Email_queue::ERROR;
                $record->error_text = $e->getMessage();
                $record->last_attemp_date = date('Y-m-d H:i:s');

                $record->save();
            }
        }
    }

    /**
     * Autofill molecules missing info
     * 
     * @author Jakub Juračka
     */
    private function substance_autofill_missing_data($only_not_validated = False)
    {
        $substance_model = new Substances();
        $rdkit = new Rdkit();
        $fileModel = new File();
        $unichem = new UniChem();

        // Init log
        $scheduler_log = new Log_scheduler();
        $scheduler_log->error_count = 0;
        $scheduler_log->success_count = 0;
        $scheduler_log->save();

        if(!$only_not_validated)
        {
            $substances = $substance_model->where(array
                (
                    'prev_validation_state IS NULL',
                    'OR',
                    '(',
                    'validated != ' => Validator::VALIDATED,
                    'validated !=' => Validator::POSSIBLE_DUPLICITY,
                    ")"
                ))
                ->limit(1000)
                ->get_all();
        }
        else
        {
            $substances = $substance_model->where(array
                (
                    'validated' => Validator::NOT_VALIDATED,
                ))
                ->limit(1000)
                ->get_all();
        }

        $total_substances = count($substances);
        $success = 0;

        // Hold report with errors
        $report = new Scheduler_report(Scheduler_report::T_SUBSTANCE);

        // For each substance, get data and fill missing
        foreach($substances as $s)
        {
            if($s->validated == Validator::VALIDATED)
            {
                $s->prev_validation_state = Validator::VALIDATED;
                $s->save();

                continue;
            }

            try
            {
                // Change first letter of name to uppercase
                $s->name = ucfirst($s->name);
                $s->save();

                // Fill substance info if missing
                if($s->validated < Validator::SUBSTANCE_FILLED)
                {
                    // Fill identifier, if missing
                    if(!$s->identifier)
                    {
                        $s->identifier = Identifiers::generate_substance_identifier($s->id);
                    }

                    // If not smiles
                    if(!$s->SMILES || $s->SMILES == '')
                    {
                        // If not exists SMILES, try to find in another DBs
                        $s->SMILES = $this->find_smiles($s);
                    }

                    // Get RDKIT data
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
                            $err_text = "Found possible duplicity. Found [$exists_smiles->identifier] with the same CANONICAL SMILES = $canonized.";
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
                            $err_text = "Found possible duplicity. Found [$exists_inchikey->identifier] with the same inchikey = $inchikey.";
                            $s->add_duplicity($exists_inchikey->id, $err_text);

                            throw new Exception($err_text);
                        }
                    }

                    // Fill missing values
                    $s->SMILES = $canonized ? $canonized : $s->SMILES;
                    $s->inchikey = $inchikey ? $inchikey : $s->inchikey;
                    $s->MW = $s->MW ? $s->MW : $MW;
                    $s->LogP = $logP ? $logP : $s->LogP;
                    // Set as filled_data state
                    $s->prev_validation_state = $s->validated;
                    $s->validated = Validator::SUBSTANCE_FILLED;

                    $s->save();
                } 
                // END OF FILLING MISSING DATA 

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

                // FILL IDENTIFIERS
                if($s->validated < Validator::IDENTIFIERS_FILLED)
                {
                    // Find identifiers by inchikey in unichem
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
                            $s->prev_validation_state = $s->validated;
                            $s->validated = Validator::IDENTIFIERS_FILLED;

                            $s->save();
                        }
                        else if(!$s->pubchem && !$s->chEMBL)
                        {
                            $s->prev_validation_state = $s->validated;
                            $s->validated = Validator::IDENTIFIERS_FILLED;
                            $s->save();

                            throw new Exception("Cannot find identifiers. Will be ignored in next run.");
                        }
                    }
                    else
                    {
                        $s->prev_validation_state = $s->validated;
                        $s->save();

                        throw new Exception("Invalid inchikey. Cannot find identifiers.");
                    }
                } 
                /// END OF FINDING IDENTIFIERS

                // Try to find LogPs from another DBs
                if($s->validated < Validator::LogP_FILLED)
                {
                    // With filled in identifiers, try to fill LogPs
                    $this->fill_logP($s);
                }

                $s->prev_validation_state = $s->validated;
                $s->validated = Validator::VALIDATED;
                $s->save();

                $success++;
            }
            catch(Exception $e)
            {
                if($s->validated !== $s->prev_validation_state)
                {
                    $report->add('Substance: ' . $s->identifier, $e->getMessage());
                }

                $error_record = new Scheduler_errors();
                //exists?
                $log = $error_record->where(array
                    (
                        'id_substance'  => $s->id,
                        'error_text'    => $e->getMessage() 
                    ))
                    ->get_one();

                if($log->id)
                {
                    $log->count = $log->count + 1;
                    $log->save();
                }
                else // Make new record
                {
                    $error_record->id_substance = $s->id;
                    $error_record->error_text = $e->getMessage();
                    $error_record->save();
                }
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
            $text = 'A new error report was currently generated on the MolMeDB server while the scheduler was running.<br/>
                    <br/> Total checked substances: ' . $total_substances . "<br/>
                    - Error: " . ($total_substances-$success) . "<br/>
                    - Success: $success<br/><br/>
                    Check the attachment for more info.";

            $this->send_email_to_admins($text, 'Scheduler run report', $scheduler_log->report_path);
        }
    }

    /**
     * Sends emails to the admins
     * 
     * @param string $message
     */
    private function send_email_to_admins($message, $subject = 'Scheduler run report', $file_path = NULL)
    {
        $email_addresses = explode(';', $this->config->get(Configs::EMAIL_ADMIN_EMAILS));

        if(!count($email_addresses))
        {
            return;
        }

        foreach($email_addresses as $e)
        {
            if(!Email::check_email_validity($e))
            {
                continue;
            }

            $email_queue = new Email_queue();

            $email_queue->recipient_email = $e;
            $email_queue->subject = $subject;
            $email_queue->text = $message;
            $email_queue->file_path = $file_path;
            $email_queue->status = Email_queue::SENDING;

            $email_queue->save();
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
                        $s->prev_validation_state = $s->validated;
                        $s->save();
                        throw new Exception('Cannot fill Pubchem LogP. Pubchem default membrane/method/dataset/publication was not found!');
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
        if($s->chEMBL && FALSE) // Temporary not required
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
                        $s->prev_validation_state = $s->validated;
                        $s->save();
                        throw new Exception('Cannot fill LogP. ChEMBL default membrane/method/dataset/publication was not found!');
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
        $substance_model = new Substances();

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
                $exists = $substance_model->where(array
                    (
                    'pubchem' => $remote_data->pubchem_id,
                    'id !=' => $substance->id
                    ))
                    ->get_one();

                if($exists->id)
                {
                    $err_text = "Found possible duplicity. Found [$exists->identifier] with the same pubchem_id = $remote_data->pubchem_id.";
                    $substance->add_duplicity($exists->id, $err_text);

                    throw new Exception($err_text);
                }

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

        $substance->prev_validation_state = $substance->validated;
        $substance->save();

        throw new Exception("Cannot find SMILES.");
    }
}