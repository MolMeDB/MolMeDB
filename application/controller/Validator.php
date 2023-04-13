<?php


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
        self::M_COSMO => 'cosmo',
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
    public function cosmo($pagination = 1, $per_page = 20)
    {
        $cosmo_model = new Run_cosmo();
        $params = $this->form->param;

        $records = $cosmo_model->get_list($pagination, $per_page, $params->compound, $params->state, $params->status);
        $total = $cosmo_model->get_list_count($params->compound, $params->state, $params->status);

        $this->view = new View('cosmo/overview');
        $this->view->jobs = $records;
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
            ->add('COSMO');

        $this->title = 'COSMO';
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

        // Collect info to display
        $cosmo_results = [];
        $python_logs = [];
        try
        {
            $ioniz_states = Fragment_ionized::instance()->where('id_fragment', $id_fragment)->get_all();
            foreach($cosmo_records as $cosmo)
            {
                $substance = $cosmo->fragment->assigned_compound();

                // Get results
                if($cosmo->state >= Run_cosmo::STATE_RESULT_PARSED)
                {
                    foreach($ioniz_states as $ion)
                    {
                        if(!isset($cosmo_results[$ion->id]))
                        {
                            $cosmo_results[$ion->id] = [];
                        }

                        $cosmo_results[$ion->id][$cosmo->id] = new Iterable_object();

                        $path = $f->prepare_conformer_folder($cosmo->fragment->id, $ion->id);

                        $path = $path . 'COSMO/';

                        if(!file_exists($path))
                        {
                            continue;
                        }

                        $files = array_filter(scandir($path), function($a){return !preg_match('/^\./', $a);});
                        $prefix = strtolower($cosmo->get_script_method() . '_' . str_replace(' ', '-',$cosmo->membrane->name) . '_' . str_replace('.', ',', $cosmo->temperature));
                        $prefix = trim($prefix);

                        foreach($files as $pt)
                        {
                            if($prefix == strtolower(substr($pt, 0, strlen($prefix))))
                            {
                                $out_path = $path . $pt . '/' . 'cosmo_parsed.json';

                                if(!file_exists($out_path))
                                {
                                    $cosmo->state = Run_cosmo::STATE_RESULT_DOWNLOADED;
                                    $cosmo->save();
                                    continue;
                                }

                                $data = json_decode(file_get_contents($out_path));
                                
                                foreach($data as $job)
                                {
                                    $energy_dist = $job->layer_positions;
                                    $temp = $job->temperature;

                                    foreach($job->solutes as $sol)
                                    {
                                        $e_values = [];
                                        
                                        foreach($sol->energy_values as $e)
                                        {
                                            $e_values[] = round($e, 2);
                                        }

                                        $cosmo_results[$ion->id][$cosmo->id] = (object)array
                                        (
                                            'logK' => $sol->logK,
                                            'logPerm' => $sol->logPerm,
                                            'energyValues' => $e_values,
                                            'energyDistance' => $energy_dist,
                                            'temperature' => $temp,
                                            'membraneName' => $cosmo->membrane->name,
                                            'methodName'   => $cosmo->get_enum_method()
                                        );
                                    }
                                }
                                continue 2;
                            }
                        }
                    }
                }
            }

            foreach($ioniz_states as $ion)
            {
                $path = $f->prepare_conformer_folder($id_fragment, $ion->id);
                if(file_exists($path . 'cosmo.log'))
                {
                    $python_logs[$ion->id] = file_get_contents($path . 'cosmo.log');
                }
            }

            // Prepare energy chart data
            foreach($cosmo_results as $ion_id => $ion_data)
            {
                foreach($ion_data as $cosmo_id => $data)
                {
                    if(!$data->membraneName)
                    {
                        continue;
                    }

                    $distances = $data->energyDistance;
                    $chart_data = [];

                    sort($distances);
                
                    foreach($distances as $d)
                    {
                        $chart_data[] = (object)array
                        (
                            'x' => floatval($d)/10
                        );
                    }

                    $yk = 'y';
                    foreach($distances as $key => $d)
                    {
                        $index = array_search($d, $data->energyDistance);

                        if($index === false)
                        {
                            $chart_data[$key]->$yk = null;
                        }
                        else
                        {
                            $chart_data[$key]->$yk = floatval($data->energyValues[$index]);
                        }
                    }

                    $cosmo_results[$ion_id][$cosmo_id]->chart_data = $chart_data;
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
            ->add('COSMO', 'validator/cosmo')
            ->add('Fragment: ' . $id_fragment);

        $this->title = 'Fragment ' . $id_fragment;
        $this->view = new View('cosmo/job_detail');
        $this->view->cosmo = $cosmo_records[0];
        $this->view->cosmo_records = $cosmo_records;
        $this->view->ion_states = $ioniz_states;
        $this->view->substance = $substance;
        $this->view->cosmo_results = $cosmo_results;
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

                $f = fopen($this->form->file->smiles->tmp_name, 'r');

                if(!$f)
                {
                    throw new MmdbException('Cannot read SMILES file content.', 'Cannot read SMILES file content.');
                }

                $lines = [];

                while(($line = fgets($f)) !== false)
                {
                    if(strlen($line) > 2)
                    {
                        $lines[] = trim($line);
                    }
                }

                fclose($f);

                // Canonize each smiles
                $canonized = [];
                $rdkit = new Rdkit();
                $f = new Fragments();

                foreach($lines as $l)
                {
                    $can = $rdkit->canonize_smiles($l);

                    if($can == false)
                    {
                        throw new MmdbException('Cannot canonize smiles ' . $l . '. Please, check the input.', 'Cannot canonize smiles ' . $l . '. Please, check the input.');
                    }

                    // Check if structure is neutral
                    if(!$f->is_neutral($can))
                    {
                        throw new MmdbException('Structure ' . $can . ' doesnt look to be neutral.', 'Structure ' . $can . ' doesnt look to be neutral.');
                    }

                    $canonized[] = $can;
                }

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
                // $cosmo_dataset->comment = $this->form->param->comment;

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
                $this->redirect('validator/cosmo');
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
            ->add('COSMO', 'validator/cosmo', true)
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
