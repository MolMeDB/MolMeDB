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

            // Delete substances without interactions
            if($time->is_minute(5) && $time->is_hour(23))
            {
                echo "Deleting empty substances. \n";
                $this->delete_empty_substances();
            }

            // ---- Molecules data autofill ---- //
            if($time->is_minute(1) && ($time->get_hour() > 20 || $time->get_hour() < 3)) // Run only once per hour and only at night
            {
                echo "Subtance validations is running. \n";
                $this->substance_autofill_missing_data($time->get_hour() % 2);
            }

            // Checks interactions datasets
            if($time->is_hour(4) && $time->is_minute(1))
            {
                echo "Checking interaction datasets.\n";
                $this->check_interaction_datasets();
            }

            // Checks transporter datasets - NOW INACTIVATED
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
     * Delets substances without interactions
     */
    public function delete_empty_substances()
    {
        $subst_model = new Substances();
        $empty = $subst_model->get_empty_substances();
        $total = 0;
        $molecules = array(['Deleted molecules']);

        foreach($empty as $s_id)
        {
            $total++;
            $substance = new Substances($s_id);

            if(!$substance->id)
            {
                continue;
            }

            $molecules[] = [$substance->name];
            $substance->delete();
        }

        // Save report and send to the admins
        if($total)
        {
            try
            {
                $report = new File();
                $file_path = MEDIA_ROOT . 'files/schedulerReports/subs_delete_' . $report->generateName() . '.csv';
                $report->create($file_path);
                $report->writeCSV($molecules);

                // Send emails
                $this->send_email_to_admins('A total of ' . $total . ' molecules were deleted.', 'Scheduler autoremove report', $file_path);

                // Add info about log file
                $scheduler_log = new Log_scheduler();

                $scheduler_log->error_count = 0;
                $scheduler_log->success_count = $total;
                $scheduler_log->report_path = $file_path;
                $scheduler_log->type = Log_scheduler::T_SUBSTANCE_AUTOREMOVE;

                $scheduler_log->save();
            }
            catch(Exception $e)
            {
                echo $e->getMessage();
                echo 'Cannot make `substance_delete` log file.';
            }

            echo('Total ' . $total . ' substances were deleted.' . "\n");
        }
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
        $joioned = 0;

        // Join the same datasets
        // Same -> membrane/method/secondary reference/author + the same upload day
        $duplcs = $dataset_model->get_duplicites();
        $joioned = count($duplcs);
        
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

        if($removed > 0 || $joioned > 0)
        {
            // Save report and send to the admins
            try
            {
                $csv = array
                (
                    ['Empty interaction datasets deleted:', $removed],
                    ['Interaction datasets joined:', $joioned],
                );

                $report = new File();
                $file_path = MEDIA_ROOT . 'files/schedulerReports/inter_ds_check_' . $report->generateName() . '.csv';
                $report->create($file_path);
                $report->writeCSV($csv);

                $text = "Some interaction datasets were deleted/joined. <br/>"
                    .   " - Total deleted: " . $removed . '<br/>'
                    .   " - Total joined: " . $joioned;

                // Send emails
                $this->send_email_to_admins($text, 'Scheduler dataset checker report');

                // Add info about log file
                $scheduler_log = new Log_scheduler();

                $scheduler_log->error_count = 0;
                $scheduler_log->success_count = $joioned + $removed;
                $scheduler_log->report_path = $file_path;
                $scheduler_log->type = Log_scheduler::T_INT_CHECK_DATASET;

                $scheduler_log->save();
            }
            catch(Exception $e)
            {
                echo $e->getMessage();
                echo 'Cannot make `interaction_delete/join` log file.';
            }
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
        $scheduler_log->type = Log_scheduler::T_SUBSTANCE_FILL;
        $scheduler_log->error_count = 0;
        $scheduler_log->success_count = 0;
        $scheduler_log->save();

        if(!$only_not_validated)
        {
            $substances = $substance_model->where(array
                (
                    '(',
                    'prev_validation_state IS NULL',
                    'waiting IS NULL',
                    ')',
                    'OR',
                    '(',
                    'waiting IS NULL',
                    'validated != ' => Validator::VALIDATED,
                    ")",
                    'OR',
                    '(',
                    'invalid_structure_flag' => Substances::INVALID_STRUCTURE,
                    ')'
                ))
                ->limit(1000)
                ->get_all();
        }
        else
        {
            $substances = $substance_model->where(array
                (
                    'waiting IS NULL',
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
            try
            {
                if($s->validated == Validator::VALIDATED)
                {
                    $s->prev_validation_state = Validator::VALIDATED;
                    $s->save();

                    continue;
                }

                // Change first letter of name to uppercase
                $s->name = ucfirst($s->name);
                $s->save();

                $ignore_dupl_ids = $s->get_non_duplicities();

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
                        $s->SMILES = $this->find_smiles($s, $ignore_dupl_ids);
                    }

                    // Get RDKIT data
                    $data = $rdkit->get_general_info($s->SMILES);

                    if(!$data)
                    {
                        // If error, try to find new SMILES
                        $s->SMILES = $this->find_smiles($s, $ignore_dupl_ids);
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
                            ->in('id NOT', $ignore_dupl_ids)
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
                            ->in('id NOT', $ignore_dupl_ids)
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
                if($s->invalid_structure_flag || !$fileModel->structure_file_exists($s->identifier))
                {
                    $content = $this->find_3d_structure($s);

                    if($content)
                    {
                        # Save new structure
                        $fileModel->save_structure_file($s->identifier, $content);
                        $s->invalid_structure_flag = NULL;

                        $s->save();
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
                            $filled = false;

                            // Check duplicities
                            if($pubchem = $s->pubchem ? $s->pubchem : $identifiers->pubchem)
                            {
                                $filled = $pubchem !== $s->pubchem ? true : $filled;

                                // Add info about new found identifier
                                if($pubchem !== $s->pubchem)
                                {
                                    $error_record = new Scheduler_errors();
                                    $error_record->add($s->id, 'Found pubchem_id = ' . $pubchem . ' on remote server.');

                                    if(self::check_identifiers_waiting_state($identifiers, $s))
                                    {
                                        $s->waiting = 1;
                                        $s->pubchem = $pubchem;
                                        $s->save();
                                        throw new Exception("Found pubchem_id on unichem. Waiting for validation.");
                                    }
                                }
                                else if($s->pubchem && $identifiers->pubchem && $s->pubchem !== $identifiers->pubchem)
                                {
                                    $error_record = new Scheduler_errors();
                                    $error_record->add($s->id, 'Found different pubchem_id = ' . $identifiers->pubchem . ' [saved = ' . $s->pubchem . '] on remote server.');
                                }

                                $exists = $substance_model->where(array
                                    (
                                    'pubchem' => $pubchem,
                                    'id !=' => $s->id
                                    ))
                                    ->in('id NOT', $ignore_dupl_ids)
                                    ->get_one();

                                if($exists->id)
                                {
                                    $err_text = "Found possible duplicity. Found [$exists->identifier] with the same pubchem_id = $pubchem.";
                                    $s->add_duplicity($exists->id, $err_text);

                                    throw new Exception($err_text);
                                }
                            }
                            if($drugbank = $s->drugbank ? $s->drugbank : $identifiers->drugbank)
                            {
                                $filled = $drugbank !== $s->drugbank ? true : $filled;

                                // Add info about new found identifier
                                if($drugbank !== $s->drugbank)
                                {
                                    $error_record = new Scheduler_errors();
                                    $error_record->add($s->id, 'Found drugbank_id = ' . $drugbank . ' on remote server.');

                                    if(self::check_identifiers_waiting_state($identifiers, $s))
                                    {
                                        $s->waiting = 1;
                                        $s->drugbank = $drugbank;
                                        $s->save();
                                        throw new Exception("Found drugbank_id on unichem. Waiting for validation.");
                                    }
                                }
                                else if($s->drugbank && $identifiers->drugbank && $s->drugbank !== $identifiers->drugbank)
                                {
                                    $error_record = new Scheduler_errors();
                                    $error_record->add($s->id, 'Found different drugbank_id = ' . $identifiers->drugbank . ' [saved = ' . $s->drugbank . '] on remote server.');
                                }

                                $exists = $substance_model->where(array
                                    (
                                    'drugbank' => $drugbank,
                                    'id !=' => $s->id
                                    ))
                                    ->in('id NOT', $ignore_dupl_ids)
                                    ->get_one();

                                if($exists->id)
                                {
                                    $err_text = "Found possible duplicity. Found [$exists->identifier] with the same drugbank_id = $drugbank.";
                                    $s->add_duplicity($exists->id, $err_text);

                                    throw new Exception($err_text);
                                }
                            }
                            if($chebi = $s->chEBI ? $s->chEBI : $identifiers->chebi)
                            {
                                $filled = $chebi !== $s->chEBI ? true : $filled;

                                // Add info about new found identifier
                                if($chebi !== $s->chEBI)
                                {
                                    $error_record = new Scheduler_errors();
                                    $error_record->add($s->id, 'Found chebi = ' . $chebi . ' on remote server.');

                                    if(self::check_identifiers_waiting_state($identifiers, $s))
                                    {
                                        $s->waiting = 1;
                                        $s->chEBI = $chebi;
                                        $s->save();
                                        throw new Exception("Found chebi_id on unichem. Waiting for validation.");
                                    }
                                }
                                else if($s->chEBI && $identifiers->chebi && $s->chEBI !== $identifiers->chebi)
                                {
                                    $error_record = new Scheduler_errors();
                                    $error_record->add($s->id, 'Found different chebi_id = ' . $identifiers->chebi . ' [saved = ' . $s->chEBI . '] on remote server.');
                                }

                                $exists = $substance_model->where(array
                                    (
                                    'chEBI' => $chebi,
                                    'id !=' => $s->id
                                    ))
                                    ->in('id NOT', $ignore_dupl_ids)
                                    ->get_one();

                                if($exists->id)
                                {
                                    $err_text = "Found possible duplicity. Found [$exists->identifier] with the same chebi_id = $chebi.";
                                    $s->add_duplicity($exists->id, $err_text);

                                    throw new Exception($err_text);
                                }
                            }
                            if($chembl = $s->chEMBL ? $s->chEMBL : $identifiers->chembl)
                            {
                                $filled = $chembl !== $s->chEMBL ? true : $filled;

                                // Add info about new found identifier
                                if($chembl !== $s->chEMBL)
                                {
                                    $error_record = new Scheduler_errors();
                                    $error_record->add($s->id, 'Found chembl_id = ' . $chembl . ' on remote server.');

                                    if(self::check_identifiers_waiting_state($identifiers, $s))
                                    {
                                        $s->waiting = 1;
                                        $s->chEMBL = $chembl;
                                        $s->save();
                                        throw new Exception("Found chembl_id on unichem. Waiting for validation.");
                                    }
                                }
                                else if($s->chEMBL && $identifiers->chembl && $s->chEMBL !== $identifiers->chembl)
                                {
                                    $error_record = new Scheduler_errors();
                                    $error_record->add($s->id, 'Found different chembl_id = ' . $identifiers->chembl . ' [saved = ' . $s->chEMBL . '] on remote server.');
                                }

                                $exists = $substance_model->where(array
                                    (
                                    'chEMBL' => $chembl,
                                    'id !=' => $s->id
                                    ))
                                    ->in('id NOT', $ignore_dupl_ids)
                                    ->get_one();

                                if($exists->id)
                                {
                                    $err_text = "Found possible duplicity. Found [$exists->identifier] with the same chembl_id = $chembl.";
                                    $s->add_duplicity($exists->id, $err_text);

                                    throw new Exception($err_text);
                                }
                            }
                            if($pdb = $s->pdb ? $s->pdb : $identifiers->pdb)
                            {
                                $filled = $pdb !== $s->pdb ? true : $filled;

                                // Add info about new found identifier
                                if($pdb !== $s->pdb)
                                {
                                    $error_record = new Scheduler_errors();
                                    $error_record->add($s->id, 'Found pdb_id = ' . $pdb . ' on remote server.');

                                    if(self::check_identifiers_waiting_state($identifiers, $s))
                                    {
                                        $s->waiting = 1;
                                        $s->pdb = $pdb;
                                        $s->save();
                                        throw new Exception("Found pdb_id on unichem. Waiting for validation.");
                                    }
                                }
                                else if($s->pdb && $identifiers->pdb && $s->pdb !== $identifiers->pdb)
                                {
                                    $error_record = new Scheduler_errors();
                                    $error_record->add($s->id, 'Found different pdb_id = ' . $identifiers->pdb . ' [saved = ' . $s->pdb . '] on remote server.');
                                }

                                $exists = $substance_model->where(array
                                    (
                                    'pdb' => $pdb,
                                    'id !=' => $s->id
                                    ))
                                    ->in('id NOT', $ignore_dupl_ids)
                                    ->get_one();

                                if($exists->id)
                                {
                                    $err_text = "Found possible duplicity. Found [$exists->identifier] with the same pdb_id = $pdb.";
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
                            $text = "Cannot find identifiers. ";

                            $s->prev_validation_state = $s->validated;
                            if($s->SMILES && $s->SMILES != '')
                            {
                                $s->validated = Validator::IDENTIFIERS_FILLED;
                                $text .= 'Will be ignored in next run.';
                            }
                            else
                            {
                                $s->validated = Validator::SUBSTANCE_FILLED;
                            }

                            $s->save();

                            throw new Exception($text);
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
                $s->waiting = NULL;
                $s->save();

                $success++;
            }
            catch(Exception $e)
            {
                if($s->validated !== $s->prev_validation_state)
                {
                    $report->add($s->identifier, $e->getMessage());
                }

                $error_record = new Scheduler_errors();
                $error_record->add($s->id, $e->getMessage());
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
     * Checks, if downloaded data are valid
     * 
     * @param object $obj
     * @param Substances $substance
     * 
     * @return boolean
     */
    private static function check_identifiers_waiting_state($obj, $substance)
    {
        return !(($substance->pubchem && $substance->pubchem == $obj->pubchem) ||
            ($substance->pdb && $substance->pdb == $obj->pdb) ||
            ($substance->drugbank && $substance->drugbank == $obj->drugbank) ||
            ($substance->chEMBL && $substance->chEMBL == $obj->chembl) ||
            ($substance->chEBI && $substance->chEBI == $obj->chebi));
    }

    /**
     * Tries to find 3D structure for given substance
     * 
     * @param Substances $substance
     * 
     * @return string
     */
    public function find_3d_structure($substance)
    {
        $pdb = new PDB();
        $drugbank = new Drugbank();
        $rdkit = new Rdkit();
        $pubchem = new Pubchem();
        $report = new Scheduler_errors();

        // Persist priority!
        $sources = array
        (
            'pubchem' => $pubchem,
            'drugbank' => $drugbank,
            'rdkit' => $rdkit,
            'pdb' => $pdb,
        );

        $structure = NULL;

        foreach($sources as $type => $source)
        {
            if(!$source->is_connected())
            {
                continue;
            }

            $structure = $source->get_3d_structure($substance);

            if($structure)
            {
                $report->add($substance->id, 'Found 3D structure on ' . $type . ' server.');
                break;
            }
        }

        return $structure;
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
        $log = new Scheduler_errors();

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

                        $log->add($s->id, 'Filled Pubchem_LogP value.');
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
    private function find_smiles($substance, $ignore = array())
    {
        $pubchem = new Pubchem();
        $chembl = new Chembl();
        $substance_model = new Substances();
        $log = new Scheduler_errors();

        if(!$pubchem->is_connected() && !$chembl->is_connected())
        {
            throw new Exception('Cannot connect to the remote servers.');
        }

        // If missing identifiers, try to find by name on remote servers
        if(!$substance->pubchem && $pubchem->is_connected())
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
                $log->add($substance->id, 'Found pubchem_id = ' . $remote_data->pubchem_id . ' on remote server by inchikey/name.');

                $exists = $substance_model->where(array
                    (
                    'pubchem' => $remote_data->pubchem_id,
                    'id !=' => $substance->id
                    ))
                    ->in('id NOT', $ignore)
                    ->get_one();

                if($exists->id)
                {
                    $err_text = "Found possible duplicity. Found [$exists->identifier] with the same pubchem_id = $remote_data->pubchem_id.";
                    $substance->add_duplicity($exists->id, $err_text);

                    throw new Exception($err_text);
                }

                $substance->pubchem = $remote_data->pubchem_id;
                $substance->waiting = 1;
                $substance->save();

                throw new Exception("Added pubchem_id. Waiting for validation.");
            }
        }

        // try to find on pubchem
        if($pubchem->is_connected() && $substance->pubchem)
        {
            $data = $pubchem->get_data($substance->pubchem);

            if($data->SMILES && $data->SMILES !== '')
            {
                $log->add($substance->id, 'Found SMILES = ' . $data->SMILES . ' on pubchem server.');
                return $data->SMILES;
            }
        }

        // try to find on chembl
        if($chembl->is_connected() && $substance->chEMBL)
        {
            $data = $chembl->get_molecule_data($substance->chEMBL);
            
            if($data && $data->SMILES && $data->SMILES !== '')
            {
                $log->add($substance->id, 'Found SMILES = ' . $data->SMILES . ' on chembl server.');
                return $data->SMILES;
            }
        }

        $substance->prev_validation_state = $substance->validated;
        $substance->save();

        throw new Exception("Cannot find SMILES.");
    }
}