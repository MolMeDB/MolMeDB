<?php

use function PHPSTORM_META\map;

/**
 * Data validator
 * 
 * @author Jakub Juračka
 */
class ValidatorController extends Controller 
{
    /** MENU sections */
    const M_CHECK_DUPLICITY = 1;
    const M_COSMO = 2;

    /** MENU ENDPOINTS */
    private $menu_endpoints = array
    (
        self::M_CHECK_DUPLICITY => 'duplicity',
        self::M_COSMO => 'cosmo_datasets',
    );

    /**
     * Constructor
     */
    function __construct()
    {
        parent::__construct();
        $this->verifyUser(True);
    }

    /**
     * Returns all menu items
     * 
     * @return array
     */
    public function get_menu_endpoints()
    {
        return $this->menu_endpoints;
    }

    /**
     * Default redirection
     */
    function index($params = NULL)
    {
        $this->redirect('validator/show');
    }

    /**
     * Cosmo computations handler
     * 
     * @param int $pagination
     * 
     */
    public function cosmo($id_dataset = null, $pagination = 1, $per_page = 20)
    {
        $dataset = new Run_cosmo_datasets($id_dataset);

        if(!$dataset->id)
        {
            $this->alert->error('Dataset doesnt exists.');
            $this->redirect('validator/cosmo_datasets');
        }

        $cosmo_model = new Run_cosmo();
        $params = $this->form->param;

        $total = $cosmo_model->get_list_count($id_dataset, $params->compound, $params->state, $params->status);

        if($total < $pagination * $per_page)
        {
            $pagination = intval($total / $per_page) + 1;
        }

        $records = $cosmo_model->get_list($id_dataset, $pagination, $per_page, $params->compound, $params->state, $params->status);

        $this->view = new View('cosmo/overview');
        $this->view->jobs = $records;
        $paginator = new View_paginator();
        $paginator->path($id_dataset)
            ->active($pagination)
            ->records_per_page($per_page);

        $paginator->total_records($total);
        $this->view->paginator($paginator);
        $this->view->total = $total;
        $this->view->params = $params;

        $this->breadcrumbs = new Breadcrumbs();
        $this->breadcrumbs
            ->add('Administration', "administration", TRUE)
            ->add('COSMO datasets', 'validator/cosmo_datasets', true)
            ->add('Dataset [ID: ' . $dataset->id . ']');

        $this->title = 'COSMO';
    }

    /**
     * Cosmo computations handler
     * 
     * @param int $pagination
     * 
     */
    public function cosmo_datasets($pagination = 1, $per_page = 20)
    {
        $dataset_model = new Run_cosmo_datasets();

        $params = $this->form->param;

        $total = $dataset_model->get_list_count($params->comment);

        if($total < $pagination * $per_page)
        {
            $pagination = intval($total / $per_page) + 1;
        }

        $records = $dataset_model->get_list($pagination, $per_page, $params->comment);

        $this->view = new View('cosmo/datasets');
        $this->view->datasets = $records;

        $paginator = new View_paginator();
        $paginator->path()
            ->active($pagination)
            ->records_per_page($per_page);
        $paginator->total_records($total);

        $this->view->paginator($paginator);
        $this->view->total = $total;
        $this->view->params = $params;

        $this->breadcrumbs = new Breadcrumbs();
        $this->breadcrumbs
            ->add('Administration', "administration", TRUE)
            ->add('COSMO datasets');

        $this->title = 'COSMO datasets';
    }

    /**
     * Downloads cosmo dataset
     * 
     * @param int $dataset_id
     * 
     * @author Jakub Juracka
     */
    public function download_cosmo_dataset($dataset_id)
    {
        $dataset = new Run_cosmo_datasets($dataset_id);

        if(!$dataset->id)
        {
            $this->alert->warning(PARAMETER);
            $this->redirect('validator/cosmo_datasets');
        }

        if($this->form->is_post())
        {
            try
            {
            $form_data = $this->form->param;
                $cosmo_runs = Run_cosmo::instance()->where('id_dataset', $dataset->id)->get_all();

                $f = new File();
                $zip_paths = [];

                $parental_dir = sys_get_temp_dir() . "/Dataset_" . $dataset->id;

                if(file_exists($parental_dir)){
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($parental_dir, 
                            RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($iterator as $file) {
                        if ($file->isDir()) {
                        rmdir($file->getPathname());
                        } else {
                        unlink($file->getPathname());
                        }
                    }
                    rmdir($parental_dir);
                }
                if(!mkdir($parental_dir))
                {
                    throw new Exception('Cannot create folder in tmp folder.');
                }

                // Go through all runs
                foreach($cosmo_runs as $run)
                {
                    $mol_link = Substances_fragments::instance()->where(array
                    (
                        'id_fragment' => $run->id_fragment,
                        'total_fragments' => 1
                    ))->get_one();
                    $molecule = new Substances($mol_link && $mol_link->id_substance ? $mol_link->id_substance : null);
                    $name = $molecule->id ? $molecule->identifier : "f" . $run->id_fragment;

                    // For each run, create folder, fill with files and add to the final zip
                    $dir_path = $parental_dir . "/" .$name;

                    if(!in_array($dir_path, $zip_paths))
                    {
                        if(file_exists($dir_path)){
                            $iterator = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($dir_path, 
                                    RecursiveDirectoryIterator::SKIP_DOTS),
                                RecursiveIteratorIterator::CHILD_FIRST
                            );
                            foreach ($iterator as $file) {
                                if ($file->isDir()) {
                                rmdir($file->getPathname());
                                } else {
                                unlink($file->getPathname());
                                }
                            }
                            rmdir($dir_path);
                        }
                        if(!mkdir($dir_path))
                        {
                            throw new Exception('Cannot create folder in tmp folder.');
                        }
                        $zip_paths[] = $dir_path;
                    }

                    // Load all ions
                    $ions = Fragment_ionized::instance()->where('id_fragment', $run->id_fragment)->get_all();

                    foreach($ions as $ion)
                    {
                        $path = $f->prepare_conformer_folder($run->id_fragment, $ion->id) . 'COSMO/';
                        // Check, if results exists if required
                        $path .= $run->get_result_folder_name() . '/';

                        if(!is_dir($path))
                        {
                            continue;
                        }

                        $avail_files = scandir($path);
                        $folder_info = array
                        (
                            'xml' => preg_grep('/\.xml$/', $avail_files),
                            'inp' => preg_grep('/\.inp$/', $avail_files),
                            'out' => preg_grep('/\.out$/', $avail_files),
                            'json'=> preg_grep('/\.json$/', $avail_files),
                            'tab' => preg_grep('/\.tab$/', $avail_files),
                            'mic' => preg_grep('/\.mic$/', $avail_files),
                        );

                        if($form_data->only_computed == 1 && empty($folder_info['xml']))
                        {
                            continue;
                        }

                        // Get charge
                        $charge = $ion->fragment->get_charge($ion->smiles);
                        $target_folder = $dir_path.'/Q_'.$charge.'/ION_'.$ion->id.'/';

                        // Make folder for results
                        mkdir($target_folder,0777, true);

                        // Add input SMILES
                        file_put_contents($target_folder . 'structure.smi', $ion->smiles);

                        if(in_array($form_data->result_type, ['json', 'both']) && !empty($folder_info['json']))
                        {
                            foreach($folder_info['json'] as $json_file)
                            {
                                copy($path . $json_file, $target_folder . $json_file);
                            }
                        }
                        if(in_array($form_data->result_type, ['raw', 'both']) && 
                            (!empty($folder_info['xml']) || !empty($folder_info['out']) || !empty($folder_info['tab'])))
                        {
                            foreach(['xml','out','tab'] as $k)
                                foreach($folder_info[$k] as $_file)
                                {
                                    copy($path . $_file, $target_folder . $_file);
                                }
                        }

                        if($form_data->membrane == 1 && !empty($folder_info['mic']))
                        {
                            foreach($folder_info['mic'] as $_file)
                            {
                                copy($path . $_file, $target_folder . $_file);
                            }
                        }

                        if($form_data->remote_input == 1 && !empty($folder_info['inp']))
                        {
                            foreach($folder_info['inp'] as $_file)
                            {
                                copy($path . $_file, $target_folder . $_file);
                            }
                        }

                        if($form_data->sdf == 1 && !file_exists($dir_path.'/sdf'))
                        {
                            $sdf = preg_grep('/\.sdf$/', scandir($f->prepare_conformer_folder($run->id_fragment, $ion->id)));
                            // Create folder for SDF files
                            if(mkdir($dir_path.'/sdf'))
                            {
                                foreach($sdf as $s)
                                {
                                    copy($f->prepare_conformer_folder($run->id_fragment, $ion->id) . $s, $dir_path.'/sdf/'.$s);
                                }
                            }
                        }
                    }
                }

                // Add all to the zip file
                $zip = new ZipArchive();
                $zip_path = sys_get_temp_dir() . '/Cosmo_Dataset_' . $dataset->id .'.zip';
                $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($parental_dir),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($files as $name => $file)
                {
                    // Skip directories (they would be added automatically)
                    if (!$file->isDir())
                    {
                        // Get real and relative path for current file
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($parental_dir) + 1);

                        // Add current file to archive
                        $zip->addFile($filePath, $relativePath);
                    }
                }

                // Zip archive will be created only after closing object
                $zip->close();

                // Download zip file
                header("Content-type: application/zip"); 
                header("Content-Disposition: attachment; filename=Cosmo_Dataset_" . $dataset->id . '.zip'); 
                header("Pragma: no-cache"); 
                header("Expires: 0"); 
                readfile("$zip_path");
                exit;
            }
            catch(Exception $e)
            {
                $this->alert->error($e);
            }
        }

        $this->title = 'Download COSMO dataset';
        $this->breadcrumbs = Breadcrumbs::instance()
            ->add('Administration', "administration", TRUE)
            ->add('COSMO datasets', 'validator/cosmo_datasets')
            ->add('Download dataset [id: ' . $dataset->id . ']');

        $this->view = new View('cosmo/download_dataset');
        $this->view->dataset = $dataset;
    }

    /**
     * Cosmo computations handler
     * 
     * @param int $pagination
     * 
     */
    public function edit_cosmo_dataset($id_dataset)
    {
        $dataset = new Run_cosmo_datasets($id_dataset);

        if(!$dataset->id)
        {
            $this->alert->error('Dataset doesnt exist.');
            $this->redirect('validator/cosmo_datasets');
        }

        $form = new Forge('Cosmo dataset [' . $dataset->id . ']');

        $form->add('comment')
            ->type('longtext')
            ->title('Comment')
            ->value($dataset->comment);

        $form->submit('Save');

        if($this->form->is_post())
        {
            $params = $this->form->param;
            try
            {
                $dataset->comment = $params->comment;
                $dataset->save();
                $this->alert->success('Saved.');
                $this->redirect('validator/cosmo_datasets');
            }
            catch(MmdbException $e)
            {
                $this->alert->error($e);
            }
        }


        $this->view = new View('forge');
        $this->view->forge = $form;

        $this->breadcrumbs = new Breadcrumbs();
        $this->breadcrumbs
            ->add('Administration', "administration", TRUE)
            ->add('COSMO datasets', "validator/cosmo_datasets", true)
            ->add('Edit record [ID: ' . $dataset->id . ']');

        $this->title = 'COSMO dataset';
    }

    /**
     * Force cosmo run
     * 
     * @param int $id_fragment - If set, all runs for given fragment are forced
     * @param int $id_job - Only one job to force
     */
    public function force_cosmo_run($id_fragment = NULL, $id_job = NULL)
    {
        $fragment = new Fragments($id_fragment);
        $cosmo = new Run_cosmo($id_job);

        if(!$fragment->id && !$cosmo->id)
        {
            $this->alert->error('Invalid parameters.');
            $this->redirect('validator/cosmo');
        }

        if($cosmo->id)
        {
            $cosmo->forceRun = 1;
            $cosmo->state = Run_cosmo::STATE_PENDING;
            $cosmo->status = Run_cosmo::STATUS_OK;
            $cosmo->error_count = NULL;
            $cosmo->save();
        }
        else
        {
            $jobs = Run_cosmo::instance()->where('id_fragment', $fragment->id)->get_all();

            foreach($jobs as $j)
            {
                $j->forceRun = 1;
                $j->state = Run_cosmo::STATE_PENDING;
                $j->status = Run_cosmo::STATUS_OK;
                $j->error_count = NULL;
                $j->save();
            }
        }

        $this->alert->success('Job will be recomputed in next run.');
        $this->redirect('validator/cosmo');
    }

    /**
     * Returns detailed description and state of COSMO computation
     * 
     * @param int $id
     * 
     */
    public function cosmo_detail($id_fragment = NULL)
    {
        $cosmo_records = Run_cosmo::instance()->where('id_fragment', $id_fragment)->get_all();
        $f = new File();

        if(!count($cosmo_records))
        {
            $this->alert->error('Jobs not found.');
            $this->redirect('validator/cosmo');
        }

        $dataset = new Run_cosmo_datasets($this->form->param->id_dataset);

        // Collect info to display
        $python_logs = [];
        try
        {
            $ioniz_states = Fragment_ionized::instance()->where('id_fragment', $id_fragment)->get_all();
            $substance = $cosmo_records[0]->fragment->assigned_compound();

            foreach($ioniz_states as $ion)
            {
                $path = $f->prepare_conformer_folder($id_fragment, $ion->id);
                if(file_exists($path . 'cosmo.log'))
                {
                    $python_logs[$ion->id] = file_get_contents($path . 'cosmo.log');
                }
            }
        }
        catch(MmdbException $e)
        {
            $this->alert->error($e);
            $this->redirect('validator/cosmo');
        }

        $this->breadcrumbs = new Breadcrumbs();
        $this->breadcrumbs->add('Administration', 'administration')
            ->add('COSMO datasets', 'validator/cosmo_datasets');

        if($dataset->id)
        {
            $this->breadcrumbs->add('Dataset [ID: ' . $dataset->id . ']', 'validator/cosmo/' . $dataset->id);
        }
            
        $this->breadcrumbs->add('Fragment: ' . $id_fragment);

        $this->title = 'Fragment ' . $id_fragment;
        $this->view = new View('cosmo/job_detail');
        $this->view->cosmo = $cosmo_records[0];
        $this->view->cosmo_records = $cosmo_records;
        $this->view->ion_states = $ioniz_states;
        $this->view->substance = $substance;
        $this->view->python_logs = $python_logs;
        $this->view->ion_job = Run_ionization::instance()->where('id_fragment', $id_fragment)->get_one();
    }

    /**
     * Adds new smiles records to cosmo computations
     * 
     * @author Jakub Juracka
     */
    public function add_cosmo_smi()
    {
        // Add new data
        if($this->form->is_post())
        {
            try
            {
                Db::beginTransaction();
                $membrane = new Membranes($this->form->param->id_membrane);
                $method = $this->form->param->method;
                $priority = $this->form->param->priority;
                $temperature = $this->form->param->temperature;
                $comment = $this->form->param->comment;

                if(!Run_cosmo::is_valid_method($method))
                {
                    throw new MmdbException('Invalid method.', "Invalid method.");
                }

                if(!Run_cosmo::is_valid_priority($priority))
                {
                    throw new MmdbException('Invalid priority value.', "Invalid priority value.");
                }

                if(!$membrane->id)
                {
                    throw new MmdbException('Invalid membrane id.', "Invalid membrane id.");
                }

                if($temperature == NULL || !is_numeric($temperature))
                {
                    throw new MmdbException('Invalid temperature value.', 'Invalid temperature value.');
                }

                if(!$this->form->has_file('smiles'))
                {
                    throw new MmdbException('No SMILES file uploaded.', 'No SMILES file uploaded.');
                }

                $fstream = fopen($this->form->file->smiles->tmp_name, 'r');

                if(!$fstream)
                {
                    throw new MmdbException('Cannot read SMILES file content.', 'Cannot read SMILES file content.');
                }

                // Canonize each smiles
                $canonized = [];
                $rdkit = new Rdkit();
                $f = new Fragments();
                $line_count = 0;

                while(($line = fgets($fstream)) !== false)
                {
                    $line_count++;
                    if(strlen($line) < 2)
                    {
                        continue;
                    }

                    try
                    {
                        $can = $rdkit->canonize_smiles($line);
                    }
                    catch(MmdbException $e)
                    {
                        $can = false;
                    }

                    if($can == false)
                    {
                        throw new MmdbException('Cannot canonize smiles `' . $line . '`. Please, check the input.', 'Cannot canonize smiles `' . $line . '` on line ' . $line_count . '. Please, check the input.');
                    }

                    // Check if structure is neutral
                    if(!$f->is_neutral($can))
                    {
                        throw new MmdbException('Structure ' . $can . ' doesnt look to be neutral.', 'Structure ' . $can . ' doesnt look to be neutral.');
                    }

                    $canonized[] = $can;
                }

                fclose($fstream);

                $fragments = [];

                foreach($canonized as $c)
                {
                    $record = new Fragments();
                    $record->smiles = $c;
                    $record->save();

                    $fragments[] = $record;
                }

                // Create record about upload
                $cosmo_dataset = new Run_cosmo_datasets();
                $cosmo_dataset->id_user = session::user_id();
                $cosmo_dataset->create_date = date('Y-m-d');
                $cosmo_dataset->comment = $comment;

                $cosmo_dataset->save();

                // Add to the queue
                foreach($fragments as $f)
                {
                    // Check if already exists
                    $exists = Run_cosmo::instance()->where(array
                    (
                        'id_fragment' => $f->id,
                        'temperature' => $temperature,
                        'id_membrane' => $membrane->id,
                        'method'      => $method,
                    ))->get_one();

                    if($exists->id)
                    {
                        $this->alert->warning('Current setting already exists for structure ' . $f->smiles);
                        continue;
                    }

                    $q = new Run_cosmo();

                    $q->id_fragment = $f->id;
                    $q->id_dataset = $cosmo_dataset->id;
                    $q->state = Run_cosmo::STATE_PENDING;
                    $q->status = Run_cosmo::STATUS_OK;
                    $q->temperature = $temperature;
                    $q->id_membrane = $membrane->id;
                    $q->method = $method;

                    $q->save();
                }

                Db::commitTransaction();
                $this->alert->success('New queue records were created.');
                $this->redirect('validator/cosmo');
            }
            catch(MmdbException $e)
            {
                Db::rollbackTransaction();
                $this->alert->error($e);
            }
        }

        // Load all membrane options
        $membranes = Membranes::instance()->where('id_cosmo_file IS NOT', "NULL")->get_all();

        $this->title = 'Add smiles';
        $this->view = new View('cosmo/add_smiles');
        $this->view->membranes = $membranes;
        $this->view->methods = Run_cosmo::get_all_methods();
        $this->view->priorities = Run_cosmo::get_all_priorities();

        $this->breadcrumbs = new Breadcrumbs();
        $this->breadcrumbs
            ->add('Administration', 'administration', TRUE)
            ->add('COSMO datasets', 'validator/cosmo_datasets', true)
            ->add('Add new records to queue');
    }


    /**
     * 
     */

    // /**
    //  * Shows detail of scheduler compound errors
    //  * 
    //  * @param int $id
    //  */
    // public function show($state = Validator::POSSIBLE_DUPLICITY, $pagination = 1, $id = null)
    // {
    //     $validator = new Validator();
    //     $scheduler_reports = new Log_scheduler();
    //     $substance = new Substances($id);

    //     if(!Validator::is_state_valid($state) && $state && !$id)
    //     {
    //         $this->redirect('validator/show/' . Validator::POSSIBLE_DUPLICITY . '/' . $pagination . "/" . $state);
    //     }

    //     if($id && !$substance->id)
    //     {
    //         $this->addMessageError('Invalid substance id.');
    //         $this->redirect('validator/show/' . $state . "/" . $pagination);
    //     }

    //     if($substance->id)
    //     {
    //         $records = $validator->get_substance_detail($id);
    //         $this->data['detail'] = $records;
    //     }
    //     else
    //     {
    //         $this->data['detail'] = FALSE;
    //     }
        
    //     $this->title = 'Validator' . ($substance->name ? " | $substance->name" : '');
    //     $this->data['state'] = $state;
    //     $this->data['pagination'] = $pagination;
    //     $this->data['compounds'] = $validator->get_substances($state, 100*($pagination - 1), 100);
    //     $this->data['total_compounds'] = count($validator->get_substances($state));
    //     $this->data['reports'] = $scheduler_reports->order_by('id', 'desc')->limit(50)->get_all();
    //     $this->view = 'validator/validator';
    // }

    // /**
    //  * Validates current substance state
    //  * 
    //  * @param int $substance_id
    //  */
    // public function validate_state($substance_id)
    // {
    //     $substance = new Substances($substance_id);

    //     if(!$substance->id)
    //     {
    //         $this->alert->error('Substance not found.');
    //         $this->redirect('validator/show');
    //     }

    //     try
    //     {
    //         $substance->waiting = NULL;
    //         $substance->save();

    //         $log = new Scheduler_errors();
    //         $log->id_substance = $substance->id;
    //         $log->error_text = 'State = ' . $substance->validated . ' was validated.';
    //         $log->id_user = $this->session->user->id;
    //         $log->count = 1;

    //         $log->save();

    //         $this->alert->success('Current state of substance [' . $substance->name . '] was labeled as validated.');
    //     }
    //     catch(Exception $e)
    //     {
    //         $this->alert->error($e->getMessage());
    //     }

    //     $this->redirect('validator/show/' . $substance->validated . "/1/" . $substance->id);
    // }

    /**
     * Joins 2 given molecules to one record
     * 
     * @param integer $substance_1
     * @param integer $substance_2
     * 
     * @author Jakub Juračka
     */
    public function join($substance_1 = NULL, $substance_2 = NULL)
    {
        if($this->form->is_post())
        {
            $substance_1 = $this->form->param->substance_1 ? $this->form->param->substance_1 : $substance_1;
            $substance_2 = $this->form->param->substance_2 ? $this->form->param->substance_2 : $substance_2;
        }

        $new = new Substances($substance_1);
        $old = new Substances($substance_2);

        // Check if exists
        if($substance_1 && !$old->id)
        {
            $this->addMessageWarning('Substance not found.');
            return;
        }

        // Check if exists
        if($substance_2 && !$new->id)
        {
            $this->addMessageWarning('Substance not found.');
            return;
        }

        $validatorModel = new Validator();

        // Join
        if($old->id && $new->id)
        {
            try
            {
                // Start transaction
                $new->beginTransaction();

                // Join alternames
                try
                {
                    $validatorModel->join_alternames($old->id, $new->id);
                }
                catch(Exception $e)
                {
                    throw new Exception('Cannot join alternames.');
                }

                // Join energy data
                try
                {
                    $validatorModel->join_energy_values($old->id, $new->id);
                }
                catch(Exception $e)
                {
                    throw new Exception('Cannot join energy values.');
                }

                // Join interactions
                try
                {
                    $validatorModel->join_interactions($old->id, $new->id);
                }
                catch(Exception $e)
                {
                    throw new Exception('Cannot join interaction values.');
                }

                // Join transporters
                try
                {
                    $validatorModel->join_transporters($old->id, $new->id);
                }
                catch(Exception $e)
                {
                    throw new Exception('Cannot join transporter values.');
                }

                // Proccess info about duplications
                $validatorModel->delete_links_on_substance($old->id);
                $validatorModel->delete_substance_links($new->id);

                // Check name
                $new_name = $new->name;

                if($new_name == $new->identifier && $old->name != $old->identifier)
                {
                    $new_name = $old->name;
                }

                // Join substance details
                $new->name = $new_name;
                $new->SMILES = !$new->SMILES || $new->SMILES == '' ? $old->SMILES : $new->SMILES;
                $new->MW = $new->MW === NULL || $new->MW == '' ? $old->MW : $new->MW;
                $new->Area = $new->Area === NULL || $new->Area == '' ? $old->Area : $new->Area;
                $new->Volume = $new->Volume === NULL || $new->Volume == '' ? $old->Volume : $new->Volume;
                $new->LogP = $new->LogP === NULL || $new->LogP == '' ? $old->LogP : $new->LogP;
                $new->pubchem = !$new->pubchem || $new->pubchem == '' ? $old->pubchem : $new->pubchem;
                $new->drugbank = !$new->drugbank || $new->drugbank == '' ? $old->drugbank : $new->drugbank;
                $new->chEBI = !$new->chEBI || $new->chEBI == '' ? $old->chEBI : $new->chEBI;
                $new->chEMBL = !$new->chEMBL || $new->chEMBL == '' ? $old->chEMBL : $new->chEMBL;
                $new->pdb = !$new->pdb || $new->pdb == '' ? $old->pdb : $new->pdb;
                $new->validated = Validator::NOT_VALIDATED;
                $new->waiting = NULL;

                // Add record about link
                try
                {
                    $link = new Substance_links();
                    $link->id_substance = $new->id;
                    $link->identifier = $old->identifier;
                    $link->user_id = $this->session->user->id;

                    $link->save();
                }
                catch(Exception $e)
                {
                    throw new Exception('Cannot add link from old substance to the new one.');
                }

                // Delete old substance
                $old->delete();
                $new->save();

                // Commit
                $new->commitTransaction();

                $this->addMessageSuccess('Molecules was successfully joined.');
                $this->addMessageWarning('New molecule was labeled as NOT VALIDATED and will be re-validated in next validation run.');

                $this->redirect('mol/' . $new->identifier);
            }
            catch(Exception $e)
            {
                $new->rollbackTransaction();
                $this->addMessageError($e->getMessage());
            }
        }

        $this->redirect('validator/show');
    }

    /**
     * Unlabel interaction dataset duplicities
     * 
     * @param int $dataset_id
     * 
     * @author Jakub Juracka
     */
    public function unlabel_duplicity($dataset_id)
    {
        $dataset = new Datasets($dataset_id);

        if(!$dataset->id)
        {
            $this->addMessageError('Invalid dataset.');
            $this->redirect('edit/dsInteractions');
        }

        $validator = new Validator();

        try
        {
            $data = $validator->get_inter_dataset_duplicity($dataset->id);
            $dataset->beginTransaction();

            foreach($data as $row)
            {
                $this->disjoin($row->id_substance_1, $row->id_substance_2);
            }

            $dataset->commitTransaction();
            $this->alert->success('Done');
        }
        catch(Exception $e)
        {
            $dataset->rollbackTransaction();
            $this->alert->error($e->getMessage());
        }  

        $this->redirect('edit/dsInteractions/' . $dataset->id);
    }


    /**
     * Disjoin 2 possible duplicity molecules
     * 
     * @param int $substance_id_1
     * @param int $substance_id_2
     * 
     * @author Jakub Juracka
     */
    public function disjoin($substance_id_1, $substance_id_2)
    {
        $enabled_redirection = debug_backtrace()[1]['function'] === 'parse' ? TRUE : FALSE;

        $substance_1 = new Substances($substance_id_1);
        $substance_2 = new Substances($substance_id_2);

        if(!$substance_1->id || !$substance_2->id)
        {
            if($enabled_redirection)
            {
                $this->alert->error('Invalid arguments.');
                $this->redirect('validator');
            }
            else
            {
                throw new Exception('Invalid arguments.');
            }
        }

        try
        {
            if($enabled_redirection)
                $substance_1->beginTransaction();

            $validator_model = new Validator();
            $rows = $validator_model->where(array
                (
                    '(',
                    'id_substance_1' => $substance_1->id,
                    'id_substance_2' => $substance_2->id,
                    ')',
                ))
                ->where(array
                (
                    'OR',
                    '(',
                    'id_substance_1' => $substance_2->id,
                    'id_substance_2' => $substance_1->id,
                    ')'
                ))
                ->get_all();

            // Change state of validations
            foreach($rows as $r)
            {
                $val = new Validator($r->id);
                if(!$val->id)
                {
                    continue;
                }

                $val->active = 0;
                $val->save();

                // Add info about action
                $err = new Scheduler_errors();
                $err_2 = new Scheduler_errors();
                $err->id_substance = $substance_1->id;
                $err_2->id_substance = $substance_2->id;
                $err->error_text = 'Labeled as non duplicity. (' . $substance_2->identifier . ' - ' . $substance_2->name . ').';
                $err_2->error_text = 'Labeled as non duplicity. (' . $substance_1->identifier  . ' - ' . $substance_1->name . ').';
                $err->count = $err_2->count = 1;
                $err->id_user = $this->session->user->id;
                $err_2->id_user = $this->session->user->id;

                $err->save();
                $err_2->save();
            }

            // If substance doesnt have next possible duplicity, then revalidate
            $s1_vals = $validator_model->where(array
                (
                    'id_substance_1' => $substance_1->id,
                    'active' => '1'
                ))
                ->get_all();

            $s2_vals = $validator_model->where(array
                (
                    'id_substance_1' => $substance_2->id,
                    'active' => '1'
                ))
                ->get_all();

            if(!count($s1_vals))
            {
                if($enabled_redirection)
                {
                    $this->alert->warning('Substance ' . $substance_1->name . " [$substance_1->identifier] will be revalidated in next scheduler run.");
                }
                $substance_1->validated = Validator::NOT_VALIDATED;
                $substance_1->waiting = NULL;
                $substance_1->save();
            }

            if(!count($s2_vals))
            {
                if($enabled_redirection)
                {
                    $this->alert->warning('Substance ' . $substance_2->name . " [$substance_2->identifier] will be revalidated in next scheduler run.");
                }
                $substance_2->validated = Validator::NOT_VALIDATED;
                $substance_2->waiting = NULL;
                $substance_2->save();
            }

           
            if($enabled_redirection)
            {
                $substance_1->commitTransaction();
                $this->alert->success('Relation between ' . $substance_1->name . ' and ' . $substance_2->name . ' was labeled as nonduplicity.');
            }
        }
        catch(Exception $e)
        {   
            if($enabled_redirection)
            {
                $substance_1->rollbackTransaction();
                $this->alert->error($e);
            }
            else
            {
                throw new Exception($e);
            }
        }

        if($enabled_redirection)
        {
            $this->redirect('validator/3/1/' . $substance_1->id);
        }
    }
}
