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

    private $debug_DES = FALSE && DEBUG;
    private $debug_CID = FALSE && DEBUG;
    private $debug_CTD = FALSE && DEBUG;
    private $debug_FMD = FALSE && DEBUG;

    /**
     * Specifies publicly accessible functions
     */
    static $accessible = array
    (
        'run',
        // 'revalidate_3d_structures'
    );


    /**
     * Checks time
     * 
     * @param string $time
     * 
     * @return boolean
     */
    public function check_time($time)
    {
        if(!$this->time)
        {
            $this->time = new Time();
        }

        $times = explode('|', $time);

        foreach($times as $time)
        {
            if(!$time || strpos($time, ":") === FALSE)
            {
                continue;
            }

            $time = trim($time);

            $time = explode(':', $time);
            $h = $time[0];
            $m = $time[1];

            if(!is_numeric($h) && !is_numeric($m))
            {
                if(strtolower($h) == 'xx' && strtolower($m) == 'xx')
                {
                    return true;
                };

                continue;
            }
            
            if(!is_numeric($h))
            {
                $m = intval($m);
                if($this->time->is_minute($m))
                {
                    return true;
                }

                continue;
            }

            if(!is_numeric($m))
            {
                $h = intval($h);
                if($this->time->is_hour($h))
                {
                    return true;
                }

                continue;
            }

            $m = intval($m);
            $h = intval($h);

            if($this->time->is_minute($m) && $this->time->is_hour($h))
            {
                return TRUE;
            }
        }

        return FALSE;
    }


    /**
     * Main scheduler funtion
     * 
     */
    public function run()
    {
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
            if($this->config->get(Configs::EMAIL_ENABLED))
            {
                $this->send_emails();
                echo "Emails sent. \n";
            }

            // Delete substances without interactions
            if($this->debug_DES || 
                ($this->check_time($this->config->get(Configs::S_DELETE_EMPTY_SUBSTANCES_TIME)) && 
                $this->config->get(Configs::S_DELETE_EMPTY_SUBSTANCES)))
            {
                echo "Deleting empty substances. \n";
                // $this->delete_empty_substances();
            }

            // Checks interactions datasets
            if($this->debug_CID || 
                ($this->check_time($this->config->get(Configs::S_VALIDATE_PASSIVE_INT_TIME)) && 
                $this->config->get(Configs::S_VALIDATE_PASSIVE_INT)))
            {
                echo "Checking interaction datasets.\n";
                // $this->check_interaction_datasets();
            }

            // Checks transporter datasets - NOW INACTIVATED
            if($this->debug_CTD ||
                ($this->check_time($this->config->get(Configs::S_VALIDATE_ACTIVE_INT_TIME)) && 
                $this->config->get(Configs::S_VALIDATE_ACTIVE_INT)))
            {
                // $this->check_transporter_datasets();
                echo "Transporter datasets checked.\n";
            }

            // ---- Molecules data autofill ---- //
            if($this->debug_FMD || 
                ($this->check_time($this->config->get(Configs::S_VALIDATE_SUBSTANCES_TIME)) && 
                $this->config->get(Configs::S_VALIDATE_SUBSTANCES))) // Run only once per hour and only at night
            {
                echo "Subtance validations is running. \n";
                // $this->substance_validation($time->get_hour() % 2);
            }

            // Once per day, update stats data
            if(($this->check_time($this->config->get(Configs::S_UPDATE_STATS_TIME)) && 
                $this->config->get(Configs::S_UPDATE_STATS)))
            {
                echo "Updating stats data. \n";
                // $this->update_stats();
            }

            // Once per day, update stats data
            if(($this->check_time($this->config->get(Configs::S_CHECK_PUBLICATIONS_TIME)) && 
                $this->config->get(Configs::S_CHECK_PUBLICATIONS)))
            {
                echo "Updating publications data. \n";
                // $this->update_publications();
                echo "Deleting empty publications. \n";
                // $this->delete_empty_publications();
            }

            // Delete empty membranes and methods
            if(($this->check_time($this->config->get(Configs::S_CHECK_MEMBRANES_METHODS_TIME)) && 
                $this->config->get(Configs::S_CHECK_MEMBRANES_METHODS)))
            {
                echo "Deleting empty membranes/methods. \n";
                // $this->delete_empty_membranes_methods();
            }

            // Revalidate 3D structures
            if($this->config->get(Configs::S_CHECK_REVALIDATE_3D_STRUCTURES))
            {
                echo "Revalidating 3D structures. \n";
                // $this->revalidate_3d_structures();
            }

            // Once per day, try to find identifiers
            if($this->debug_FMD || ($time->is_minute(1) && $time->is_hour(5))) // Run only once per hour and only at night
            {
                echo "`Filling missing identifiers` is running. \n";
                // $this->fill_substance_identifiers();
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
     * Updates stats data
     */
    private function update_stats()
    {
        $stats_model = new Statistics();
        $stats_model->update_all();
    }

    /**
     * Updates publications
     */
    private function update_publications()
    {
        $publication_model = new Publications();
        $all_publications = $publication_model->get_all();

        foreach($all_publications as $p)
        {
            $p->update_ref_stats();
        }
    }

    /**
     * Deletes empty publications
     */
    private function delete_empty_publications()
    {
        $publication_model = new Publications();
        $empty_publications = $publication_model->get_empty_publications();

        foreach($empty_publications as $p)
        {
            $p->delete();
        }
    }

    /**
     * Deletes old membranes and methods
     * 
     * @author Jakub Juracka
     */
    public function delete_empty_membranes_methods()
    {
        $membrane_model = new Membranes();
        $method_model = new Methods();

        $empty_membranes = $membrane_model->get_empty_membranes();
        $mepty_methods = $method_model->get_empty_methods();

        $del_membranes = $del_methods = 0;

        foreach($empty_membranes as $membrane)
        {
            try
            {
                $membrane->delete();
                $del_membranes++;
            }
            catch(Exception $e)
            {
                echo "Error occured while deleting membrane [" . $membrane->name . "].\n";
                echo $e->getMessage() . "\n";
            }
        }

        foreach($mepty_methods as $method)
        {
            try
            {
                $method->delete();
                $del_methods++;
            }
            catch(Exception $e)
            {
                echo "Error occured while deleting method [" . $method->name . "].\n";
                echo $e->getMessage() . "\n";
            }
        }

        if($del_membranes)
        {
            echo "Total $del_membranes membranes deleted.\n";
        }

        if($del_methods)
        {
            echo "Total $del_methods methods deleted.\n";
        }
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
            $final_id = array_shift($ids);

            // For each dataset, change interaction dataset id
            foreach($ids as $dataset_id)
            {
                $interaction_model->change_dataset_id($dataset_id, $final_id);
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
     * Fills missing identifiers
     * 
     * @author Jakub Juracka
     */
    private function fill_substance_identifiers()
    {
        $substance_model = new Substances();
        $unichem = new UniChem();

        if(!$unichem->is_connected())
        {
            echo "Cannot connect to Unichem server.\n";
            return;
        }

        $substances = $substance_model->where(
            array
            (
                'pubchem IS NULL',
                'drugbank IS NULL',
                'chEMBL IS NULL',
                'pdb IS NULL',
                'chEBI IS NULL',
                'validated' => Validator::VALIDATED
            ))
            ->get_all();

        if(count($substances))
        {
            echo "Processing " . count($substances) . " substances.\n";
        }

        foreach($substances as $s)
        {
            try
            {
                $ignore_dupl_ids = $s->get_non_duplicities();

                // Find identifiers by inchikey in unichem
                if($s->inchikey)
                {
                    // Get identifiers by Unichem service
                    $identifiers = $unichem->get_identifiers($s->inchikey);

                    if($identifiers)
                    {
                        $filled = false;

                        // Check duplicities
                        if($pubchem = $s->pubchem ? $s->pubchem : Upload_validator::get_attr_val(Upload_validator::PUBCHEM, $identifiers->pubchem))
                        {
                            $filled = $pubchem !== $s->pubchem ? true : $filled;

                            // Add info about new found identifier
                            if($pubchem !== $s->pubchem)
                            {
                                $error_record = new Scheduler_errors();
                                $error_record->add($s->id, 'Found pubchem_id = ' . $pubchem . ' on remote server.');

                                if(self::check_identifiers_waiting_state($identifiers, $s))
                                {
                                    $s->validated = Validator::IDENTIFIERS_FILLED;
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
                        if($drugbank = $s->drugbank ? $s->drugbank : Upload_validator::get_attr_val(Upload_validator::DRUGBANK, $identifiers->drugbank))
                        {
                            $filled = $drugbank !== $s->drugbank ? true : $filled;

                            // Add info about new found identifier
                            if($drugbank !== $s->drugbank)
                            {
                                $error_record = new Scheduler_errors();
                                $error_record->add($s->id, 'Found drugbank_id = ' . $drugbank . ' on remote server.');

                                if(self::check_identifiers_waiting_state($identifiers, $s))
                                {
                                    $s->validated = Validator::IDENTIFIERS_FILLED;
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
                        if($chebi = $s->chEBI ? $s->chEBI : Upload_validator::get_attr_val(Upload_validator::CHEBI_ID, $identifiers->chebi))
                        {
                            $filled = $chebi !== $s->chEBI ? true : $filled;

                            // Add info about new found identifier
                            if($chebi !== $s->chEBI)
                            {
                                $error_record = new Scheduler_errors();
                                $error_record->add($s->id, 'Found chebi = ' . $chebi . ' on remote server.');

                                if(self::check_identifiers_waiting_state($identifiers, $s))
                                {
                                    $s->validated = Validator::IDENTIFIERS_FILLED;
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
                        if($chembl = $s->chEMBL ? $s->chEMBL : Upload_validator::get_attr_val(Upload_validator::CHEMBL_ID, $identifiers->chembl))
                        {
                            $filled = $chembl !== $s->chEMBL ? true : $filled;

                            // Add info about new found identifier
                            if($chembl !== $s->chEMBL)
                            {
                                $error_record = new Scheduler_errors();
                                $error_record->add($s->id, 'Found chembl_id = ' . $chembl . ' on remote server.');

                                if(self::check_identifiers_waiting_state($identifiers, $s))
                                {
                                    $s->validated = Validator::IDENTIFIERS_FILLED;
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
                        if($pdb = $s->pdb ? $s->pdb : Upload_validator::get_attr_val(Upload_validator::PDB, $identifiers->pdb))
                        {
                            $filled = $pdb !== $s->pdb ? true : $filled;

                            // Add info about new found identifier
                            if($pdb !== $s->pdb)
                            {
                                $error_record = new Scheduler_errors();
                                $error_record->add($s->id, 'Found pdb_id = ' . $pdb . ' on remote server.');

                                if(self::check_identifiers_waiting_state($identifiers, $s))
                                {
                                    $s->validated = Validator::IDENTIFIERS_FILLED;
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
            catch(Exception $e)
            {
                $error_record = new Scheduler_errors();
                $error_record->add($s->id, $e->getMessage());
            }
        }
    }

    /**
     * Restructure validator
     * 
     */
    private function restructure_validator()
    {
        $s = new Substances(1);

        $logs = Scheduler_errors::instance()->where('id_substance', $s->id);

        foreach($logs as $log)
        {
            
        }
    }

    /**
     * Auto validator
     * 
     * @author Jakub Juracka
     */
    private function auto_validation()
    {
        // Get info about last run
        $is_running = Config::get('scheduler_autovalidation_running'); // Co kdyby to spadlo?
        $last_substance_id = Config::get('scheduler_autovalidation_last_id');
        $last_substance_id = is_numeric($last_substance_id) ? $last_substance_id : 1;

        if($is_running)
        {
            return;
        }

        $substance_model = new Substances();
        $rdkit = new Rdkit();
        $fileModel = new File();
        $unichem = new UniChem();
        $pubchem_model = new Pubchem();

        $pch_membrane = $pubchem_model->get_logP_membrane();
        $pch_method = $pubchem_model->get_logP_method();
        $pch_dataset = $pubchem_model->get_dataset();
        $pch_publication = $pubchem_model->get_publication();

        if(!$pch_membrane || !$pch_membrane->id || !$pch_method || !$pch_method->id ||
            !$pch_dataset || !$pch_dataset->id || !$pch_publication || !$pch_publication->id)
        {
            // Add log
            echo "Cannot find pubchem dataset default values.";
        }

        // Global scheduler log
        $scheduler_log = new Log_scheduler();

        $substances = $substance_model->where(array
            (
                'id >' => $last_substance_id
            ))
            ->limit(1000)
            ->get_all();

        // Hold report with errors
        $report = new Scheduler_report(Scheduler_report::T_SUBSTANCE);

        foreach($substances as $s)
        {
            $s = new Substances($s->id);
            try
            {
                // Check, if molecule has filled DB identifier
                if(!$s->identifier)
                {
                    $s->identifier = Identifiers::generate_substance_identifier($s->id);
                    $s->save();
                }

                $identifiers = Identifiers::check_name_for_identifiers($s->name);

                // If found
                if(count($identifiers) == 1)
                {
                    // Save info about found identifier
                    if(isset($identifiers['chembl']))
                    {
                        $s->chEMBL = $s->name;
                        $s->name = $s->identifier;
                        $s->save();
                    }
                    elseif(isset($identifiers['drugbank']))
                    {

                        $s->drugbank = $s->name;
                        $s->name = $s->identifier;
                        $s->save();
                    }
                }
                elseif(count($identifiers) > 1)
                {
                    // Fatal error
                    throw new Exception('Name contains more than one identifier.');
                }
            }
            catch(Exception $e)
            {

            }
        }
    }


    // /**
    //  * Autofill molecules missing info
    //  * 
    //  * @author Jakub Juračka
    //  */
    // private function substance_validation($only_not_validated = False)
    // {
    //     $substance_model = new Substances();
    //     $rdkit = new Rdkit();
    //     $fileModel = new File();
    //     $unichem = new UniChem();
    //     $pubchem_model = new Pubchem();

    //     if($this->debug_FMD)
    //     {
    //         $only_not_validated = FALSE;
    //     }

    //     $membrane = $pubchem_model->get_logP_membrane();
    //     $method = $pubchem_model->get_logP_method();
    //     $dataset = $pubchem_model->get_dataset();
    //     $publication = $pubchem_model->get_publication();

    //     if(!$membrane || !$membrane->id || !$method || !$method->id ||
    //         !$dataset || !$dataset->id || !$publication || !$publication->id)
    //     {
    //         echo "Cannot find pubchem dataset default values.";
    //         return;
    //     }

    //     // Init log
    //     $error_record = new Scheduler_errors();
    //     $scheduler_log = new Log_scheduler();

    //     if(!$only_not_validated)
    //     {
    //         $substances = $substance_model->where(array
    //             (
    //                 '(',
    //                     '(',
    //                     'prev_validation_state IS NULL',
    //                     'waiting IS NULL',
    //                     ')',
    //                     'OR',
    //                     '(',
    //                     'waiting IS NULL',
    //                     'validated != ' => Validator::VALIDATED,
    //                     ")",
    //                     'OR',
    //                     '(',
    //                     'invalid_structure_flag' => Substances::INVALID_STRUCTURE,
    //                     ')',
    //                 ')',
    //                 'validated !=' => Validator::NOT_VALIDATED,
    //             ))
    //             ->limit(1000)
    //             ->get_all();
    //     }
    //     else
    //     {
    //         $substances = $substance_model->where(array
    //             (
    //                 'waiting IS NULL',
    //                 'validated' => Validator::NOT_VALIDATED,
    //             ))
    //             ->limit(1000)
    //             ->get_all();
    //     }

    //     $total_substances = count($substances);
    //     $success = 0;
    //     $error = 0;

    //     // Hold report with errors
    //     $report = new Scheduler_report(Scheduler_report::T_SUBSTANCE);

    //     // For each substance, get data and fill missing
    //     foreach($substances as $s)
    //     {
    //         try
    //         {
    //             // Check name if contains some identifier
    //             self::check_name_for_identifiers($s);

    //             if($s->validated == Validator::VALIDATED)
    //             {
    //                 $s->prev_validation_state = Validator::VALIDATED;
    //                 $s->save();
    //                 $success++;

    //                 continue;
    //             }

    //             // Change first letter of name to uppercase
    //             $s->name = ucfirst($s->name);
    //             $s->save();

    //             $ignore_dupl_ids = $s->get_non_duplicities();

    //             // Fill substance info if missing
    //             if($s->validated < Validator::SUBSTANCE_FILLED)
    //             {
    //                 // Fill identifier, if missing
    //                 if(!$s->identifier)
    //                 {
    //                     $s->identifier = Identifiers::generate_substance_identifier($s->id);
    //                 }

    //                 // If not smiles
    //                 if(!$s->SMILES || $s->SMILES == '')
    //                 {
    //                     // If not exists SMILES, try to find in another DBs
    //                     $s->SMILES = $this->find_smiles($s, $ignore_dupl_ids);
    //                 }

    //                 // Get RDKIT data
    //                 $data = $rdkit->get_general_info($s->SMILES);

    //                 if(!$data)
    //                 {
    //                     // If error, try to find new SMILES
    //                     $s->SMILES = $this->find_smiles($s, $ignore_dupl_ids);
    //                     $data = $rdkit->get_general_info($s->SMILES);

    //                     if(!$data)
    //                     {
    //                         throw new Exception('INVALID RESPONSE WHILE GETTING RDKIT GENERAL INFO.');
    //                     }
    //                 }

    //                 $canonized = $data->canonized_smiles;
    //                 $inchikey = $data->inchi;
    //                 $MW = Upload_validator::get_attr_val(Upload_validator::MW, $data->MW);
    //                 $logP = Upload_validator::get_attr_val(Upload_validator::LOG_P, $data->LogP);

    //                 // Check duplicities
    //                 if($canonized)
    //                 {
    //                     $exists_smiles = $substance_model->where(array
    //                         (
    //                             'SMILES' => $canonized,
    //                             'id !=' => $s->id
    //                         ))
    //                         ->in('id NOT', $ignore_dupl_ids)
    //                         ->get_one();
                        
    //                     if($exists_smiles->id)
    //                     {
    //                         $err_text = "Found possible duplicity. Found [$exists_smiles->identifier] with the same CANONICAL SMILES = $canonized.";
    //                         $s->add_duplicity($exists_smiles->id, $err_text);

    //                         throw new Exception($err_text);
    //                     }
    //                 }
    //                 if($inchikey)
    //                 {
    //                     $exists_inchikey = $substance_model->where(array
    //                         (
    //                             'inchikey' => $inchikey,
    //                             'id !=' => $s->id
    //                         ))
    //                         ->in('id NOT', $ignore_dupl_ids)
    //                         ->get_one();

    //                     if($exists_inchikey->id)
    //                     {
    //                         $err_text = "Found possible duplicity. Found [$exists_inchikey->identifier] with the same inchikey = $inchikey.";
    //                         $s->add_duplicity($exists_inchikey->id, $err_text);

    //                         throw new Exception($err_text);
    //                     }
    //                 }

    //                 // Fill missing values
    //                 $s->SMILES = $canonized ? $canonized : $s->SMILES;
    //                 $s->inchikey = $inchikey ? $inchikey : $s->inchikey;
    //                 $s->MW = $s->MW ? $s->MW : $MW;
    //                 $s->LogP = $logP ? $logP : $s->LogP;
                    
    //                 // Set as filled_data state
    //                 $s->prev_validation_state = $s->validated;
    //                 $s->validated = Validator::SUBSTANCE_FILLED;

    //                 $s->save();
    //             } 
    //             // END OF FILLING MISSING DATA 

    //             // Generate 3d structure file if missing
    //             if($s->invalid_structure_flag || !$fileModel->structure_file_exists($s->identifier))
    //             {
    //                 $content = $this->find_3d_structure($s);

    //                 if($content)
    //                 {
    //                     # Save new structure
    //                     $fileModel->save_structure_file($s->identifier, $content);
    //                     $s->invalid_structure_flag = NULL;

    //                     $s->save();
    //                 }
    //             }

    //             // FILL IDENTIFIERS
    //             if($s->validated < Validator::IDENTIFIERS_FILLED)
    //             {
    //                 // Find identifiers by inchikey in unichem
    //                 if($s->inchikey && $unichem->is_connected())
    //                 {
    //                     // Get identifiers by Unichem service
    //                     $identifiers = $unichem->get_identifiers($s->inchikey);

    //                     if($identifiers)
    //                     {
    //                         $filled = false;

    //                         // Check duplicities
    //                         if($pubchem = $s->pubchem ? $s->pubchem : Upload_validator::get_attr_val(Upload_validator::PUBCHEM, $identifiers->pubchem))
    //                         {
    //                             $filled = $pubchem !== $s->pubchem ? true : $filled;

    //                             // Add info about new found identifier
    //                             if($pubchem !== $s->pubchem)
    //                             {
    //                                 $error_record = new Scheduler_errors();
    //                                 $error_record->add($s->id, 'Found pubchem_id = ' . $pubchem . ' on remote server.');

    //                                 if(self::check_identifiers_waiting_state($identifiers, $s))
    //                                 {
    //                                     $s->waiting = 1;
    //                                     $s->pubchem = $pubchem;
    //                                     $s->save();
    //                                     throw new Exception("Found pubchem_id on unichem. Waiting for validation.");
    //                                 }
    //                             }
    //                             else if($s->pubchem && $identifiers->pubchem && $s->pubchem !== $identifiers->pubchem)
    //                             {
    //                                 $error_record = new Scheduler_errors();
    //                                 $error_record->add($s->id, 'Found different pubchem_id = ' . $identifiers->pubchem . ' [saved = ' . $s->pubchem . '] on remote server.');
    //                             }

    //                             $exists = $substance_model->where(array
    //                                 (
    //                                 'pubchem' => $pubchem,
    //                                 'id !=' => $s->id
    //                                 ))
    //                                 ->in('id NOT', $ignore_dupl_ids)
    //                                 ->get_one();

    //                             if($exists->id)
    //                             {
    //                                 $err_text = "Found possible duplicity. Found [$exists->identifier] with the same pubchem_id = $pubchem.";
    //                                 $s->add_duplicity($exists->id, $err_text);

    //                                 throw new Exception($err_text);
    //                             }
    //                         }
    //                         if($drugbank = $s->drugbank ? $s->drugbank : Upload_validator::get_attr_val(Upload_validator::DRUGBANK, $identifiers->drugbank))
    //                         {
    //                             $filled = $drugbank !== $s->drugbank ? true : $filled;

    //                             // Add info about new found identifier
    //                             if($drugbank !== $s->drugbank)
    //                             {
    //                                 $error_record = new Scheduler_errors();
    //                                 $error_record->add($s->id, 'Found drugbank_id = ' . $drugbank . ' on remote server.');

    //                                 if(self::check_identifiers_waiting_state($identifiers, $s))
    //                                 {
    //                                     $s->waiting = 1;
    //                                     $s->drugbank = $drugbank;
    //                                     $s->save();
    //                                     throw new Exception("Found drugbank_id on unichem. Waiting for validation.");
    //                                 }
    //                             }
    //                             else if($s->drugbank && $identifiers->drugbank && $s->drugbank !== $identifiers->drugbank)
    //                             {
    //                                 $error_record = new Scheduler_errors();
    //                                 $error_record->add($s->id, 'Found different drugbank_id = ' . $identifiers->drugbank . ' [saved = ' . $s->drugbank . '] on remote server.');
    //                             }

    //                             $exists = $substance_model->where(array
    //                                 (
    //                                 'drugbank' => $drugbank,
    //                                 'id !=' => $s->id
    //                                 ))
    //                                 ->in('id NOT', $ignore_dupl_ids)
    //                                 ->get_one();

    //                             if($exists->id)
    //                             {
    //                                 $err_text = "Found possible duplicity. Found [$exists->identifier] with the same drugbank_id = $drugbank.";
    //                                 $s->add_duplicity($exists->id, $err_text);

    //                                 throw new Exception($err_text);
    //                             }
    //                         }
    //                         if($chebi = $s->chEBI ? $s->chEBI : Upload_validator::get_attr_val(Upload_validator::CHEBI_ID, $identifiers->chebi))
    //                         {
    //                             $filled = $chebi !== $s->chEBI ? true : $filled;

    //                             // Add info about new found identifier
    //                             if($chebi !== $s->chEBI)
    //                             {
    //                                 $error_record = new Scheduler_errors();
    //                                 $error_record->add($s->id, 'Found chebi = ' . $chebi . ' on remote server.');

    //                                 if(self::check_identifiers_waiting_state($identifiers, $s))
    //                                 {
    //                                     $s->waiting = 1;
    //                                     $s->chEBI = $chebi;
    //                                     $s->save();
    //                                     throw new Exception("Found chebi_id on unichem. Waiting for validation.");
    //                                 }
    //                             }
    //                             else if($s->chEBI && $identifiers->chebi && $s->chEBI !== $identifiers->chebi)
    //                             {
    //                                 $error_record = new Scheduler_errors();
    //                                 $error_record->add($s->id, 'Found different chebi_id = ' . $identifiers->chebi . ' [saved = ' . $s->chEBI . '] on remote server.');
    //                             }

    //                             $exists = $substance_model->where(array
    //                                 (
    //                                 'chEBI' => $chebi,
    //                                 'id !=' => $s->id
    //                                 ))
    //                                 ->in('id NOT', $ignore_dupl_ids)
    //                                 ->get_one();

    //                             if($exists->id)
    //                             {
    //                                 $err_text = "Found possible duplicity. Found [$exists->identifier] with the same chebi_id = $chebi.";
    //                                 $s->add_duplicity($exists->id, $err_text);

    //                                 throw new Exception($err_text);
    //                             }
    //                         }
    //                         if($chembl = $s->chEMBL ? $s->chEMBL : Upload_validator::get_attr_val(Upload_validator::CHEMBL_ID, $identifiers->chembl))
    //                         {
    //                             $filled = $chembl !== $s->chEMBL ? true : $filled;

    //                             // Add info about new found identifier
    //                             if($chembl !== $s->chEMBL)
    //                             {
    //                                 $error_record = new Scheduler_errors();
    //                                 $error_record->add($s->id, 'Found chembl_id = ' . $chembl . ' on remote server.');

    //                                 if(self::check_identifiers_waiting_state($identifiers, $s))
    //                                 {
    //                                     $s->waiting = 1;
    //                                     $s->chEMBL = $chembl;
    //                                     $s->save();
    //                                     throw new Exception("Found chembl_id on unichem. Waiting for validation.");
    //                                 }
    //                             }
    //                             else if($s->chEMBL && $identifiers->chembl && $s->chEMBL !== $identifiers->chembl)
    //                             {
    //                                 $error_record = new Scheduler_errors();
    //                                 $error_record->add($s->id, 'Found different chembl_id = ' . $identifiers->chembl . ' [saved = ' . $s->chEMBL . '] on remote server.');
    //                             }

    //                             $exists = $substance_model->where(array
    //                                 (
    //                                 'chEMBL' => $chembl,
    //                                 'id !=' => $s->id
    //                                 ))
    //                                 ->in('id NOT', $ignore_dupl_ids)
    //                                 ->get_one();

    //                             if($exists->id)
    //                             {
    //                                 $err_text = "Found possible duplicity. Found [$exists->identifier] with the same chembl_id = $chembl.";
    //                                 $s->add_duplicity($exists->id, $err_text);

    //                                 throw new Exception($err_text);
    //                             }
    //                         }
    //                         if($pdb = $s->pdb ? $s->pdb : Upload_validator::get_attr_val(Upload_validator::PDB, $identifiers->pdb))
    //                         {
    //                             $filled = $pdb !== $s->pdb ? true : $filled;

    //                             // Add info about new found identifier
    //                             if($pdb !== $s->pdb)
    //                             {
    //                                 $error_record = new Scheduler_errors();
    //                                 $error_record->add($s->id, 'Found pdb_id = ' . $pdb . ' on remote server.');

    //                                 if(self::check_identifiers_waiting_state($identifiers, $s))
    //                                 {
    //                                     $s->waiting = 1;
    //                                     $s->pdb = $pdb;
    //                                     $s->save();
    //                                     throw new Exception("Found pdb_id on unichem. Waiting for validation.");
    //                                 }
    //                             }
    //                             else if($s->pdb && $identifiers->pdb && $s->pdb !== $identifiers->pdb)
    //                             {
    //                                 $error_record = new Scheduler_errors();
    //                                 $error_record->add($s->id, 'Found different pdb_id = ' . $identifiers->pdb . ' [saved = ' . $s->pdb . '] on remote server.');
    //                             }

    //                             $exists = $substance_model->where(array
    //                                 (
    //                                 'pdb' => $pdb,
    //                                 'id !=' => $s->id
    //                                 ))
    //                                 ->in('id NOT', $ignore_dupl_ids)
    //                                 ->get_one();

    //                             if($exists->id)
    //                             {
    //                                 $err_text = "Found possible duplicity. Found [$exists->identifier] with the same pdb_id = $pdb.";
    //                                 $s->add_duplicity($exists->id, $err_text);

    //                                 throw new Exception($err_text);
    //                             }
    //                         }

    //                         $s->pubchem = $s->pubchem ? $s->pubchem : $identifiers->pubchem;
    //                         $s->drugbank = $s->drugbank ? $s->drugbank : $identifiers->drugbank;
    //                         $s->chEBI = $s->chEBI ? $s->chEBI : $identifiers->chebi;
    //                         $s->chEMBL = $s->chEMBL ? $s->chEMBL : $identifiers->chembl;
    //                         $s->pdb = $s->pdb ? $s->pdb : $identifiers->pdb;
    //                         $s->prev_validation_state = $s->validated;
    //                         $s->validated = Validator::IDENTIFIERS_FILLED;

    //                         $s->save();
    //                     }
    //                     else if(!$s->pubchem && !$s->chEMBL)
    //                     {
    //                         $text = "Cannot find identifiers. ";

    //                         $s->prev_validation_state = $s->validated;
    //                         if($s->SMILES && $s->SMILES != '')
    //                         {
    //                             $s->validated = Validator::IDENTIFIERS_FILLED;
    //                             $text .= 'Will be ignored in next run.';
    //                         }
    //                         else
    //                         {
    //                             $s->validated = Validator::SUBSTANCE_FILLED;
    //                         }

    //                         $s->save();

    //                         throw new Exception($text);
    //                     }
    //                 }
    //                 else
    //                 {
    //                     $s->prev_validation_state = $s->validated;
    //                     $s->save();

    //                     throw new Exception("Invalid inchikey. Cannot find identifiers.");
    //                 }
    //             } 
    //             /// END OF FINDING IDENTIFIERS

    //             /// FILL NAME IF MISSING
    //             // If instead of name is filled identifier, try to find name in another DB
    //             if(Identifiers::is_identifier($s->name))
    //             {
    //                 $sources = array
    //                 (
    //                     "pubchem" => $pubchem_model
    //                 );

    //                 foreach($sources as $key => $source)
    //                 {
    //                     if($s->$key && $source->is_connected())
    //                     {
    //                         $data = $source->get_data($s->$key);

    //                         if($data && $data->name)
    //                         {
    //                             $name = Upload_validator::get_attr_val(Upload_validator::NAME, $data->name);

    //                             if($name && $name != "")
    //                             {
    //                                 $error_record->add($s->id, 'Name [' . $name . '] found on ' . $key . ' server.');
    //                                 $s->name = $name;
    //                                 $s->save();

    //                                 break;
    //                             }
    //                         }
    //                     }
    //                 }
    //             }
    //             /// END OF NAME FILL

    //             // Try to find LogPs from another DBs
    //             if($s->validated < Validator::LogP_FILLED)
    //             {
    //                 // With filled in identifiers, try to fill LogPs
    //                 $this->fill_logP($s);
    //             }

    //             $s->prev_validation_state = $s->validated;
    //             $s->validated = Validator::VALIDATED;
    //             $s->waiting = NULL;
    //             $s->save();

    //             $success++;
    //         }
    //         catch(Exception $e)
    //         {   
    //             $error++;

    //             if($s->validated !== $s->prev_validation_state)
    //             {
    //                 $report->add($s->identifier, $e->getMessage());
    //             }

    //             $error_record->add($s->id, $e->getMessage());
    //         }
    //     }

    //     // check, if last scheduler report can be just updated
    //     $exists = $scheduler_log
    //         ->where('type', Log_scheduler::T_SUBSTANCE_FILL)
    //         ->order_by("id", "desc")
    //         ->get_one();

    //     if($report->is_empty() && 
    //         $exists->id && 
    //         $exists->error_count == $error &&
    //         $exists->success == ($total_substances - $error) &&
    //         !$exists->report_path)
    //     {
    //         // Update record
    //         $exists->datetime = date('Y-m-d H:i:s');
    //         $exists->save();
    //     }
    //     else
    //     {
    //         // Make new record about scheduler run
    //         $scheduler_log->type = Log_scheduler::T_SUBSTANCE_FILL;
    //         $scheduler_log->error_count = $error;
    //         $scheduler_log->success_count = $total_substances - $error;
    //         $scheduler_log->save();
    //     }

    //     if(!$report->is_empty())
    //     {
    //         $report->save_report();
    //         $scheduler_log->report_path = $report->get_file_path();
    //         $scheduler_log->save();

    //         // Send email with report
    //         $text = 'A new error report was currently generated on the MolMeDB server while the scheduler was running.<br/>
    //                 <br/> Total checked substances: ' . $total_substances . "<br/>
    //                 - Error: " . ($total_substances-$success) . "<br/>
    //                 - Success: $success<br/><br/>
    //                 Check the attachment for more info.";

    //         $this->send_email_to_admins($text, 'Scheduler run report', $scheduler_log->report_path);
    //     }
    // }

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
     * Redownloads all 3D structures of molecules 
     * 
     * @param int $start_id
     * 
     */
    private function revalidate_3d_structures()
    {
        $subst_model = new Substances();
        $file_model = new File();
        $last_validation_time = $this->config->get(Configs::S_CHECK_REVALIDATE_3D_STRUCTURES_IS_RUNNING);

        if($last_validation_time && $last_validation_time > strtotime('-4 hour'))
        {
            echo "Validation in progress. Skipping. \n";
            return;
        }

        $last_id = $this->config->get(Configs::S_CHECK_REVALIDATE_3D_STRUCTURES_LAST_ID);

        if(!$last_id)
        {
            $last_id = 0;
        }

        # MAX validate 1000 substanes in one run
        $substance_ids = $subst_model->where('id >=', $last_id)->order_by('id', 'ASC')->select_list("id")->limit(1000)->get_all();

        $this->config->set(Configs::S_CHECK_REVALIDATE_3D_STRUCTURES_IS_RUNNING, strtotime('now'));

        echo "Validating total " . count($substance_ids) . " structures. \n";

        foreach($substance_ids as $sub)
        {
            $s = new Substances($sub->id);
            $content = $this->find_3d_structure($s);

            if($content)
            {
                # Save new structure
                $file_model->save_structure_file($s->identifier, $content);
                $s->invalid_structure_flag = NULL;

                $s->save();

                // echo "Validated " . $s->identifier . ". \n";
            }

            $this->config->set(Configs::S_CHECK_REVALIDATE_3D_STRUCTURES_LAST_ID, $s->id);
        }

        $this->config->set(Configs::S_CHECK_REVALIDATE_3D_STRUCTURES_IS_RUNNING, 0);

        echo "Validation done. \n";
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
                        $exists->LogK = Upload_validator::get_attr_val(Upload_validator::LOG_P, $data->logP);
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
                        $interaction->LogK = Upload_validator::get_attr_val(Upload_validator::LOG_P, $data->logP);
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

                $substance->pubchem = $remote_data->pubchem_id;
                $substance->waiting = 1;
                $substance->save();

                if($exists->id)
                {
                    $err_text = "Found possible duplicity. Found [$exists->identifier] with the same pubchem_id = $remote_data->pubchem_id.";
                    $substance->add_duplicity($exists->id, $err_text);

                    throw new Exception($err_text);
                }

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

    /**
     * Checks, if identifier is not in name 
     * If yes, then fill missing column
     * 
     * @param Substances $substance
     */
    private static function check_name_for_identifiers($substance)
    {
        $err = new Scheduler_errors();

        $name = trim($substance->name);

        // if(Upload_validator::check_identifier_format($name, Upload_validator::PUBCHEM, True) && !$substance->pubchem)
        // {
        //     $err->add($substance->id, 'Added pubchem_id in molecule name = ' . $name . '.');
        //     $substance->pubchem = $name;
        // }
        // elseif(Upload_validator::check_identifier_format($name, Upload_validator::PDB, True) && !$substance->pdb)
        // {
        //     $err->add($substance->id, 'Added pdb_id in molecule name = ' . $name . '.');
        //     $substance->pdb = $name;
        // }
        if(Upload_validator::check_identifier_format($name, Upload_validator::DRUGBANK, True) && !$substance->drugbank)
        {
            $err->add($substance->id, 'Found drugbank_id in molecule name = ' . $name . '.');
            $substance->drugbank = $name;
        }
        elseif(Upload_validator::check_identifier_format($name, Upload_validator::CHEMBL_ID, True) && !$substance->chEMBL)
        {
            $err->add($substance->id, 'Found chembl_id in molecule name = ' . $name . '.');
            $substance->chEMBL = $name;
        }
        // elseif(Upload_validator::check_identifier_format($name, Upload_validator::CHEBI_ID, True) && !$substance->chEBI)
        // {
        //     $err->add($substance->id, 'Added chebi_id in molecule name = ' . $name . '.');
        //     $substance->chEBI = $name;
        // }

        $substance->save();
    }
}