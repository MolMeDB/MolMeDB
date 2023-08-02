<?php

/**
 * Edit controller
 * !!! ADD INTERACTION EDITOR !!!
 * 
 * @author Jakub Juračka
 */
class EditController extends Controller 
{
    /** MEMBRANE/METHOD EDIT TYPES */
    const T_DETAIL = 'detail';
    const T_CATEGORY = 'category';

    /** COMPOUNDS 3D structure upload */
    const T_3D_STRUCTURE = '3d_structure';

    /** MENU SECTIONS */
    const M_DATASET_INTER = 1;
    const M_ENERGY = 2;
    const M_MEMBRANE = 3;
    const M_METHOD = 4;
    const M_ARTICLE = 5;
    const M_PUBLICATION = 6;
    const M_USERS = 7;
    const M_DATASET_TRANS = 8;

    /** MENU ENDPOINTS */
    private $menu_endpoints = array
    (
        self::M_DATASET_INTER => 'dsInteractions',
        self::M_DATASET_TRANS => 'dsTransporters',
        self::M_ENERGY  => 'energy',
        self::M_MEMBRANE => 'membrane',
        self::M_METHOD => 'method',
        self::M_ARTICLE => 'article',
        self::M_PUBLICATION => 'publication',
        self::M_USERS => 'user'
    );

    /** MENU SUBSECTIONS */
    private $submenu = array();

    /**
     * Constructor
     * Checks access rights
     */
    function __construct()
    {
        parent::__construct();
        $this->verifyUser(True);
        $this->make_submenu();
    }

    // Creates submenu structure
    private function make_submenu()
    {
        $article_model = new Articles();

        $this->submenu = array
        (
            self::M_ARTICLE => array
            (
                $article_model->get_enum_type(Articles::T_INTRO) => array
                (
                    'ref' => $this->menu_endpoints[self::M_ARTICLE] . '/' . Articles::T_INTRO,
                    'title' => 'Intro'
                ),
                $article_model->get_enum_type(Articles::T_CONTACTS) => array
                (
                    'ref' => $this->menu_endpoints[self::M_ARTICLE] . '/' . Articles::T_CONTACTS,
                    'title' => 'Contacts'
                ),
                $article_model->get_enum_type(Articles::T_DOCUMENTATION) => array
                (
                    'ref' => $this->menu_endpoints[self::M_ARTICLE] . '/' . Articles::T_DOCUMENTATION,
                    'title' => 'Documentation'
                )
            ),
        );
    }

    /**
     * Gets menu references
     * 
     * @return array
     */
    public function get_menu_endpoints()
    {
        return $this->menu_endpoints;
    }


    /**
     * Edit article
     * 
     * @param string $type
     * 
     * @author Jakub Juračka
     */
    public function article($type = Articles::T_INTRO)
    {
        $articles_model = new Articles();

        // Get type if in variable is set article ID
        if(is_numeric($type))
        {
            $type = $articles_model->get_enum_type($type);
        }

        // Is type valid?
        if(!$articles_model->is_valid_enum_type($type))
        {
            $this->alert->error('Parameter');
            $this->redirect('error');
        }

        $id = $articles_model->get_id_by_type($type);

        $detail = new Articles($id);

        if(!$detail->id)
        {
            $this->alert->error('Article was not found.');
            $this->redirect('error');
        }

        // Save form
        if($_POST)
        {
            try
            {
                Db::beginTransaction();

                $detail->content = $_POST['content'];

                $detail->save();
                
                Db::commitTransaction();
                
                $this->alert->success("Saved");
            }
            catch(ErrorUser $ex)
            {
                Db::rollbackTransaction();
                $this->alert->error($ex);
            }
        }

        $this->view = new View('edit/' . $type);
        $this->view->detail = $detail;
        $this->view->navigator = $this->createNavigator(self::M_ARTICLE, $type);
        $this->title = 'Editor';

        $this->breadcrumbs = Breadcrumbs::instance()->add('Administration', 'administration')->add('Edit article');
    }
    
    /**
     * Compounds editor
     * 
     * @param integer $id
     * @param string $spec_type
     * 
     */
    public function compound($id, $spec_type = NULL)
    {
        $interactModel = new Interactions();
        $transporters_model = new Transporters();
        $scheduler_error = new Scheduler_errors();

        $detail = new Substances($id);

        // Checks if compound exists
        if(!$detail->id)
        {
            $this->alert->error('Param');
            $this->redirect('error');
        }

        // Upload new 3D structure file
        if($spec_type == self::T_3D_STRUCTURE)
        {
            try 
            {
                throw new MmdbException('Sorry, service is currently unavailable.', 'Sorry, service is currently unavailable.');

                $file = $_FILES["structure"];

                if ($file['name'] != '')
                {
                    $this->upload_3d_structure($file, $detail);
                }
                else 
                {
                    $type = $_POST['fileURL'];
                    $content = NULL;

                    switch($type)
                    {
                        case 'drugbank':
                            $drugbank = new Drugbank();
                            
                            if(!$drugbank->is_connected())
                            {
                                throw new MmdbException('Cannot connect to drugbank server.');
                            }

                            if(!$detail->drugbank)
                            {
                                throw new MmdbException('Missing drugbank identifier.');
                            }

                            $content = $drugbank->get_3d_structure($detail);
                            break;

                        case 'pdb':
                            $driver = new PDB();
                            
                            if(!$driver->is_connected())
                            {
                                throw new MmdbException('Cannot connect to the PDB server.');
                            }

                            if(!$detail->pdb)
                            {
                                throw new MmdbException('Missing pdb identifier.');
                            }

                            $content = $driver->get_3d_structure($detail);
                            break;

                        case 'pubchem':
                            $driver = new Pubchem();
                            
                            if(!$driver->is_connected())
                            {
                                throw new MmdbException('Cannot connect to the pubchem server.');
                            }

                            if(!$detail->pubchem)
                            {
                                throw new MmdbException('Missing pubchem identifier.');
                            }

                            $content = $driver->get_3d_structure($detail);
                            break;

                        case 'rdkit':
                            $driver = new Rdkit();
                            
                            if(!$driver->is_connected())
                            {
                                throw new MmdbException('Cannot connect to Rdkit server.');
                            }

                            if(!$detail->SMILES)
                            {
                                throw new MmdbException('Missing SMILES.');
                            }

                            $content = $driver->get_3d_structure($detail);
                            break;

                        default:
                            throw new MmdbException('Invalid parameter.');
                    }

                    if(!$content)
                    {
                        throw new MmdbException('Cannot find 3D structure file.');
                    }

                    // Fill identifier, if missing
                    if(!$detail->identifier)
                    {
                        $detail->identifier = Identifiers::generate_substance_identifier($detail->id);
                    }

                    $file_model = new File();

                    $file_model->save_structure_file($detail->identifier, $content);
                    $detail->invalid_structure_flag = NULL;

                    $detail->save();
                }

                $this->alert->success('3D Structure was saved.');
            } 
            catch (MmdbException $ex) 
            {
                $this->alert->error($ex);
            }

            $this->redirect('edit/compound/' . $id);
        }
        else if ($this->form->is_post()) 
        {
            // Edit substance
            try 
            {
                $rdkit = new Rdkit();

                $detail->name = $this->form->param->name;
                $detail->MW = $this->form->param->MW;
                $detail->SMILES = $this->form->param->SMILES;
                $detail->inchikey = $this->form->param->inchikey;
                // $detail->LogP = $this->form->param->LogP;
                // $detail->Area = $this->form->param->Area;
                // $detail->Volume = $this->form->param->Volume;
                $detail->pdb = $this->form->param->pdb;
                $detail->pubchem = $this->form->param->pubchem;
                $detail->drugbank = $this->form->param->drugbank;
                $detail->chEBI = $this->form->param->chEBI;
                $detail->chEMBL = $this->form->param->chEMBL;


                // Autofill inchikey if missing
                if(empty($_POST['inchikey']) && !empty($_POST['SMILES']) && $rdkit->is_connected())
                {
                    $data = $rdkit->get_general_info($_POST['SMILES']);

                    if(!$data)
                    {
                        $this->alert->warning('Cannot load general data from rdkit. Please, check SMILES.');
                    }
                    elseif(!empty($data->canonized_smiles))
                    {
                        $detail->SMILES = $data->canonized_smiles;
                        $this->alert->success('SMILES was automatically canonized.');
                    }
                    
                    if($data && !empty($data->inchi))
                    {
                        $detail->inchikey = $data->inchi;
                        $this->alert->success('Inchikey was automatically generated.');
                    }
                }

                $detail->save_checking_identifiers();
                $detail->check_identifiers();

                $this->alert->success('Changes successfully saved.');
                $this->redirect('edit/compound/' . $detail->id);
            } 
            catch (Exception $ex) 
            {
                $this->alert->error('Error: ' . $ex->getMessage());
            }
        }

        $assigned_interactions = $interactModel
            ->where('id_substance', $detail->id)
            ->get_all();

        $assigned_transporters = $transporters_model
            ->where('id_substance', $detail->id)
            ->get_all();

        $logs = $scheduler_error
            ->where('id_substance', $detail->id)
            ->order_by("id", "DESC")
            ->get_all();

        $this->view = new View('edit/compound');
        $this->view->detail = $detail;
        $this->view->interactions_all = $assigned_interactions;
        $this->view->transporters_all = $assigned_transporters;
        $this->view->logs = $logs;
        $this->title = 'Edit compound';
        $this->breadcrumbs = Breadcrumbs::instance()->add($detail->name, 'mol/' . $detail->identifier)->add('Edit');
    }



    /**
     * Edits interaction
     * 
     * @param integer $id ID of interaction
     */
    public function interaction($id)
    {
        // Redirection path
        $redir_path = isset($_GET['redirection']) ? $_GET['redirection'] : NULL;
        
        $interaction = new Interactions($id);

        if(!$interaction->id)
        {
            $this->alert->error('Interaction was not found.');
            $this->redirect($redir_path ? $redir_path : 'error');
        }

        if($this->form->is_post())
        {
            try 
            {
                if($this->form->param->Position_acc && !$this->form->param->Position)
                {
                    throw new MmdbException('Invalid position value.');
                }
                if($this->form->param->Penetration_acc && !$this->form->param->Penetration)
                {
                    throw new MmdbException('Invalid Penetration value.');
                }
                if($this->form->param->Water_acc && !$this->form->param->Water)
                {
                    throw new MmdbException('Invalid Water value.');
                }
                if($this->form->param->LogPerm_acc && !$this->form->param->LogPerm)
                {
                    throw new MmdbException('Invalid LogPerm value.');
                }
                if($this->form->param->theta_acc && !$this->form->param->theta)
                {
                    throw new MmdbException('Invalid theta value.');
                }
                if($this->form->param->abs_wl_acc && !$this->form->param->abs_wl)
                {
                    throw new MmdbException('Invalid abs_wl value.');
                }
                if($this->form->param->fluo_wl_acc && !$this->form->param->fluo_wl)
                {
                    throw new MmdbException('Invalid fluo_wl value.');
                }
                if($this->form->param->QY_acc && !$this->form->param->QY)
                {
                    throw new MmdbException('Invalid QY value.');
                }
                if($this->form->param->lt_acc && !$this->form->param->lt)
                {
                    throw new MmdbException('Invalid lt value.');
                }

                // Edit interaction
                $interaction->Position = $this->form->param->Position;
                $interaction->Position_acc = $this->form->param->Position_acc;
                $interaction->Penetration = $this->form->param->Penetration;
                $interaction->Penetration_acc = $this->form->param->Penetration_acc;
                $interaction->Water = $this->form->param->Water;
                $interaction->Water_acc = $this->form->param->Water_acc;
                $interaction->LogK = $this->form->param->LogK;
                $interaction->LogK_acc = $this->form->param->LogK_acc;
                $interaction->LogPerm = $this->form->param->LogPerm;
                $interaction->LogPerm_acc = $this->form->param->LogPerm_acc;
                $interaction->theta = $this->form->param->theta;
                $interaction->theta_acc = $this->form->param->theta_acc;
                $interaction->abs_wl = $this->form->param->abs_wl;
                $interaction->abs_wl_acc = $this->form->param->abs_wl_acc;
                $interaction->fluo_wl = $this->form->param->fluo_wl;
                $interaction->fluo_wl_acc = $this->form->param->fluo_wl_acc;
                $interaction->QY = $this->form->param->QY;
                $interaction->QY_acc = $this->form->param->QY_acc;
                $interaction->lt = $this->form->param->lt;
                $interaction->lt_acc = $this->form->param->lt_acc;
                $interaction->temperature = $this->form->param->Temperature;
                $interaction->charge = $this->form->param->Q;

                $interaction->save();

                $this->alert->success('Saved');

                $this->redirect($redir_path ? $redir_path : 'edit/compound/' . $interaction->substance->id);
            } 
            catch (MmdbException $ex) 
            {
                $this->alert->error($ex);
                $this->redirect('edit/interaction/' . $interaction->id);
            }
        }

        $this->view = new View('edit/interaction');
        $this->view->detail = $interaction;
        $this->title = "Interaction [$interaction->id] editor";
        $this->breadcrumbs = Breadcrumbs::instance()
                                ->add($interaction->substance->toStr(), 'mol/' . $interaction->substance->identifier)
                                ->add("Edit: " . $interaction->substance->name, 'edit/compound/' . $interaction->substance->id)
                                ->add('Edit interaction [ID:' . $interaction->id . ']');
    }

    /**
     * Edits active interaction
     * 
     * @param integer $id ID of interaction
     */
    public function active_interaction($id)
    {
        // Redirection path
        $redir_path = isset($_GET['redirection']) ? $_GET['redirection'] : NULL;
        
        $interaction = new Transporters($id);

        if(!$interaction->id)
        {
            $this->alert->error('Interaction was not found.');
            $this->redirect($redir_path ? $redir_path : 'error');
        }

        if($this->form->is_post())
        {
            try 
            {
                $target = new Transporter_targets($this->form->param->target);

                if($this->form->param->Km_acc && !$this->form->param->Km)
                {
                    throw new MmdbException('Invalid Km value.');
                }
                if($this->form->param->Ki_acc && !$this->form->param->Ki)
                {
                    throw new MmdbException('Invalid Ki value.');
                }
                if($this->form->param->IC50_acc && !$this->form->param->IC50)
                {
                    throw new MmdbException('Invalid IC50 value.');
                }
                if($this->form->param->EC50_acc && !$this->form->param->EC50)
                {
                    throw new MmdbException('Invalid EC50 value.');
                }
                if(!Transporters::is_valid_type($this->form->param->type))
                {
                    throw new MmdbException('Invalid type value.');
                }
                if(!$target->id)
                {
                    throw new MmdbException('Invalid target value.');
                }

                // Edit interaction
                $interaction->type = $this->form->param->type;
                $interaction->id_target = $this->form->param->target;
                $interaction->Km = $this->form->param->Km;
                $interaction->Km_acc = $this->form->param->Km_acc;
                $interaction->Ki = $this->form->param->Ki;
                $interaction->Ki_acc = $this->form->param->Ki_acc;
                $interaction->EC50 = $this->form->param->EC50;
                $interaction->EC50_acc = $this->form->param->EC50_acc;
                $interaction->IC50 = $this->form->param->IC50;
                $interaction->IC50_acc = $this->form->param->IC50_acc;

                $interaction->save();

                $this->alert->success('Saved');

                $this->redirect($redir_path ? $redir_path : 'edit/compound/' . $interaction->substance->id);
            } 
            catch (MmdbException $ex) 
            {
                $this->alert->error($ex);
                $this->redirect('edit/active_interaction/' . $interaction->id);
            }
        }

        $this->view = new View('edit/active_interaction');
        $this->view->detail = $interaction;
        $this->view->targets = Transporter_targets::instance()->order_by('name')->get_all();
        $this->title = "Interaction [$interaction->id] editor";
        $this->breadcrumbs = Breadcrumbs::instance()
                                ->add($interaction->substance->toStr(), 'mol/' . $interaction->substance->identifier)
                                ->add("Edit: " . $interaction->substance->name, 'edit/compound/' . $interaction->substance->id)
                                ->add('Edit interaction [ID:' . $interaction->id . ']');
    }

    /**
     * File uploader - CHECK VALIDITY!
     * 
     * @param string $file
     * @param Substances $substance
     * @param integer $fileURL
     */
    private function upload_3d_structure($file, $substance, $fileURL = False)
    {
        $file_model = new File();
        $target_file = File::FOLDER_3DSTRUCTURES . $substance->identifier . ".mol";

        $file_model->make_path(File::FOLDER_3DSTRUCTURES);
       
        if($fileURL)
        {
            return file_put_contents($target_file, fopen($file, 'r'));
        }
        else
        {
            if($file["size"] > 100000)
            {
                throw new MmdbException('Sorry, your file is too large.');
            }
            if(move_uploaded_file($file['tmp_name'], $target_file))
            {
                return True;
            }
            else 
            {
                throw new MmdbException('Problem with saving file to target folder.');
            }
        }
    }


    /**
     * Edits dataset
     * 
     * @param integer $id ID of dataset
     * @param integer $pagination
     * 
     * @author Jakub Juračka
     */
    public function dsInteractions($id = NULL, $pagination = 1)
    {
        $dataset = new Datasets($id);

        if($id && !$dataset->id)
        {
            $this->alert->error('Dataset not found');
            $this->redirect('edit/dataset');
        }

        // Is loaded dataset ? Then show detail
        $show_detail = $dataset->id ? True : False;

        if ($this->form->is_post()) 
        {
            try
            {
                switch ($this->form->param->spec_type) 
                {
                    case 'edit_rights':
                        try 
                        {
                            //Check access rights
                            $this->verifyUser(true, 'edit_dataset', array('id_dataset' => $id, "url" => "edit/dataset/$id"));

                            Db::beginTransaction();

                            $id_entity = $this->form->param->id_entity;
                            $group = $this->form->param->group;

                            $dataset->change_rights($id_entity, $group);
                            
                            Db::commitTransaction();
                            $this->alert->success("Access rights was successfully changed!");
                        } 
                        catch (MmdbException $ex) 
                        {
                            Db::rollbackTransaction();
                            $this->alert->error($ex);
                        }
                        break;

                    case 'edit_basic':
                        try
                        {
                            // Access rights check
                            $this->verifyUser(true, 'edit_dataset', array('id_dataset' => $id, "url" => "edit/dataset/$id"));

                            Db::beginTransaction();

                            if(intval($this->form->param->id_dataset) !== intval($dataset->id))
                            {
                                throw new MmdbException('Wrong dataset instance.');
                            }

                            // Update data
                            $dataset->name = $this->form->param->dataset_name;
                            $dataset->id_membrane = $this->form->param->dataset_membrane;
                            $dataset->id_method = $this->form->param->dataset_method;
                            $dataset->id_publication = $this->form->param->dataset_publication;
                            $dataset->visibility = $this->form->param->visibility;
                            
                            $dataset->save();
                            
                            Db::commitTransaction();

                            $this->alert->success("Successfully updated!");
                        } 
                        catch (MmdbException $ex) 
                        {
                            Db::rollbackTransaction();
                            $this->alert->error($ex);
                        }
                        break;

                    case 'edit_interaction':
                        // Check access rights
                        $this->verifyUser(true, 'edit_dataset', array('id_dataset' => $id, "url" => "edit/dataset/$id"));
                        
                        try 
                        {
                            $interaction = new Interactions($this->form->param->id_interaction);
                            $substance = new Substances($interaction->id_substance);

                            if(!$interaction->id)
                            {
                                throw new Exception('Interaction was not found.');
                            }

                            if(!$substance->id)
                            {
                                throw new Exception('Substance was not found.');
                            }

                            Db::beginTransaction();

                            if(trim($this->form->param->substance_name) == '')
                            {
                                throw new MmdbException('Invalid parameter value.', 'Value `substance_name` cannot be empty.');
                            }

                            // Edit substance
                            $substance->name = $this->form->param->substance_name;
                            $substance->SMILES = $this->form->param->SMILES;
                            $substance->LogP = $this->form->param->LogP;
                            $substance->pubchem = $this->form->param->pubchem;
                            $substance->pdb = $this->form->param->pdb;
                            $substance->chEBI = $this->form->param->chEBI;
                            $substance->chEMBL = $this->form->param->chEMBL;
                            $substance->drugbank = $this->form->param->drugbank;

                            $substance->save_checking_identifiers();
                            $substance->check_identifiers();

                            // Edit interaction
                            $interaction->comment = $this->form->param->comment;
                            $interaction->Position = $this->form->param->Position;
                            $interaction->Position_acc = $this->form->param->Position_acc;
                            $interaction->Penetration = $this->form->param->Penetration;
                            $interaction->Penetration_acc = $this->form->param->Penetration_acc;
                            $interaction->Water = $this->form->param->Water;
                            $interaction->Water_acc = $this->form->param->Water_acc;
                            $interaction->LogK = $this->form->param->LogK;
                            $interaction->LogK_acc = $this->form->param->LogK_acc;
                            $interaction->LogPerm = $this->form->param->LogPerm;
                            $interaction->LogPerm_acc = $this->form->param->LogPerm_acc;
                            $interaction->theta = $this->form->param->theta;
                            $interaction->theta_acc = $this->form->param->theta_acc;
                            $interaction->abs_wl = $this->form->param->abs_wl;
                            $interaction->abs_wl_acc = $this->form->param->abs_wl_acc;
                            $interaction->fluo_wl = $this->form->param->fluo_wl;
                            $interaction->fluo_wl_acc = $this->form->param->fluo_wl_acc;
                            $interaction->QY = $this->form->param->QY;
                            $interaction->QY_acc = $this->form->param->QY_acc;
                            $interaction->lt = $this->form->param->lt;
                            $interaction->lt_acc = $this->form->param->lt_acc;
                            $interaction->temperature = $this->form->param->temperature;
                            $interaction->charge = $this->form->param->charge;

                            $interaction->save();

                            Db::commitTransaction();
                            $this->alert->success($substance->name . " was successfully updated!");
                        } catch (MmdbException $ex) 
                        {
                            Db::rollbackTransaction();
                            $this->alert->error($ex);
                        }
                        break;

                    default:
                        throw new Exception('Wrong request type.');
                        break;
                }

                $this->redirect('edit/dsInteractions/' . intval($id) . '/' . $pagination);
            }
            catch(MmdbException $e)
            {
                $this->alert->error($e);
                $this->redirect('edit/dsInteractions/' . intval($id) . '/' . $pagination);
            }
        }

        $this->view = new View('edit/interaction_dataset');

        // If dataset exists, show detail
        if($dataset->id)
        {
            $interaction_model = new Interactions();

            $items = 100;

            $dataset_interactions = $interaction_model
                ->where('id_dataset', $dataset->id)
                ->limit($pagination, $items)
                ->get_all();

            $total = $interaction_model
                ->where('id_dataset', $dataset->id)
                ->count_all();

            $this->view->pagination_setting = array
            (
                'total_items' => $total,
                'items_per_page' => $items,
                'active_page'   => $pagination,
                'callback' => 'edit/dsInteractions/' . $dataset->id . '/{pagination}'
            );
            $this->view->dataset = $dataset;
            $this->view->interaction_table = self::createInteractionTable($dataset_interactions, $dataset->id);
            $this->view->total = $total;
            $this->view->pagination = $pagination;
            $this->view->rights_users = $dataset->get_rights();
            $this->view->rights_groups = $dataset->get_rights(true);
        }

        $this->view->show_detail = $show_detail;
        // Get all datasets
        $this->view->datasets = $dataset
            ->order_by('createDateTime', 'DESC')
            ->get_all();

        $this->title = 'Dataset editor';
        $this->view->navigator = $this->createNavigator(self::M_DATASET_INTER);
        $this->breadcrumbs = Breadcrumbs::instance()->add('Administration', 'administration')->add('Edit passive interaction datasets');
    }
    
    /**
     * Edits transporters datasets
     * 
     * @param integer $id ID of dataset
     * 
     * @author Jakub Juračka
     */
    public function dsTransporters($id = NULL)
    {
        $dataset = new Transporter_datasets($id);

        if($id && !$dataset->id)
        {
            $this->alert->error('Dataset not found');
            $this->redirect('edit/dataset');
        }

        // Is loaded dataset ? Then show detail
        $show_detail = $dataset->id ? True : False;

        if ($this->form->is_post()) 
        {
            try
            {
                switch ($this->form->param->spec_type) 
                {
                    case 'edit_rights':
                        // try 
                        // {
                        //     //Check access rights
                        //     $this->verifyUser(true, 'edit_dataset', array('id_dataset' => $id, "url" => "edit/dataset/$id"));

                        //     Db::beginTransaction();

                        //     $id_entity = $this->form->param->id_entity;
                        //     $group = $this->form->param->group;

                        //     $dataset->change_rights($id_entity, $group);
                            
                        //     Db::commitTransaction();
                        //     $this->alert->success("Access rights was successfully changed!");
                        // } 
                        // catch (Exception $ex) 
                        // {
                        //     Db::rollbackTransaction();
                        //     $this->alert->error($ex->getMessage());
                        // }
                        $this->alert->warning('Sorry, service is temporary unavailable.');
                        break;

                    case 'edit_basic':
                        try
                        {
                            // Access rights check
                            // $this->verifyUser(true, 'edit_dataset', array('id_dataset' => $id, "url" => "edit/dataset/$id"));

                            Db::beginTransaction();

                            if(intval($this->form->param->id_dataset) !== intval($dataset->id))
                            {
                                throw new MmdbException('Wrong dataset instance.');
                            }

                            // Update data
                            $dataset->name = $this->form->param->dataset_name;
                            $dataset->id_reference = $this->form->param->dataset_publication;
                            $dataset->visibility = $this->form->param->visibility;
                            
                            $dataset->save();
                            
                            Db::commitTransaction();

                            $this->alert->success("Successfully updated!");
                        } 
                        catch (MmdbException $ex) 
                        {
                            Db::rollbackTransaction();
                            $this->alert->error($ex);
                        }
                        break;

                    case 'edit_interaction':
                        // Check access rights
                        $this->verifyUser(true, 'edit_dataset', array('id_dataset' => $id, "url" => "edit/dataset/$id"));
                        
                        try 
                        {
                            $interaction = new Transporters($this->form->param->id_interaction);
                            $substance = new Substances($interaction->id_substance);

                            if(!$interaction->id)
                            {
                                throw new MmdbException('Interaction was not found.');
                            }

                            if(!$substance->id)
                            {
                                throw new MmdbException('Substance was not found.');
                            }

                            Db::beginTransaction();

                            // Edit substance
                            $substance->name = $this->form->param->substance_name;
                            $substance->SMILES = $this->form->param->SMILES;
                            $substance->LogP = $this->form->param->LogP;
                            $substance->pubchem = $this->form->param->pubchem;
                            $substance->pdb = $this->form->param->pdb;
                            $substance->chEBI = $this->form->param->chEBI;
                            $substance->chEMBL = $this->form->param->chEMBL;
                            $substance->drugbank = $this->form->param->drugbank;

                            $substance->save_checking_identifiers();
                            $substance->check_identifiers();

                            // Edit interaction
                            $interaction->type = $this->form->param->type;
                            $interaction->id_target = $this->form->param->target;
                            $interaction->note = $this->form->param->note;
                            $interaction->Km = $this->form->param->Km;
                            $interaction->Km_acc = $this->form->param->Km_acc;
                            $interaction->Ki = $this->form->param->Ki;
                            $interaction->Ki_acc = $this->form->param->Ki_acc;
                            $interaction->EC50 = $this->form->param->EC50;
                            $interaction->EC50_acc = $this->form->param->EC50_acc;
                            $interaction->IC50 = $this->form->param->IC50;
                            $interaction->IC50_acc = $this->form->param->IC50_acc;

                            $interaction->save();

                            Db::commitTransaction();
                            $this->alert->success($substance->name . " was successfully updated!");
                        } catch (MmdbException $ex) 
                        {
                            Db::rollbackTransaction();
                            $this->alert->error($ex);
                        }
                        break;

                    default:
                        throw new MmdbException('Wrong request type.');
                        break;
                }

                $this->redirect('edit/dsTransporters/' . intval($id));
            }
            catch(MmdbException $e)
            {
                $this->alert->error($e);
                $this->redirect('edit/dsTransporters/' . intval($id));
            }
        }

        $this->view = new View('edit/transporter_dataset');

        // If dataset exists, show detail
        if($dataset->id)
        {
            $transporters_model = new Transporters();
            $target_model = new Transporter_targets();

            $dataset_transporters = $transporters_model
                ->where('id_dataset', $dataset->id)
                ->get_all();

            $this->view->info = $dataset;
            $this->view->transporters_table = self::createTransportersTable($dataset_transporters, $dataset->id);
            $this->view->targets = $target_model->order_by('name', 'ASC')->get_all();
            // $this->view->rights_users = $dataset->get_rights();
            // $this->view->rights_groups = $dataset->get_rights(true);
        }

        $this->view->show_detail = $show_detail;
        // Get all datasets
        $this->view->datasets = $dataset
            ->order_by('create_datetime', 'DESC')
            ->get_all();

        $this->title = 'Dataset editor';
        $this->view->navigator = $this->createNavigator(self::M_DATASET_TRANS);
        $this->breadcrumbs = Breadcrumbs::instance()->add('Administration', 'administration')->add('Edit transporter datasets');
    }


    /**
     * User editor
     * 
     * @param integer $group_id
     * @param integer $user_id
     * 
     */
    public function user($group_id = NULL, $user_id = NULL)
    {
        $userModel = new Users($user_id);
        $groups = $userModel->get_all_groups();

        $this->view = new View('edit/users');
        $this->view->users_table = '';

        // Toggle group affiliation
        if($group_id && $user_id)
        {
            try 
            {
                if ($group_id == 1)
                {
                    $this->verifyUser(true, '', array('url' => 'edit/users/' . $group_id), true);
                }

                Db::beginTransaction();
                
                $userModel->toggle_group($group_id);
                
                Db::commitTransaction();

                $this->alert->success('Successfully edited.');
                $this->redirect('edit/user/' . $group_id);
            } 
            catch (MmdbException $ex) 
            {
                Db::rollbackTransaction();
                $this->alert->error('Error, please contact administrator.');
            }
        }
        // Load group detail
        else if ($group_id)
        {
            try 
            {
                Db::beginTransaction();

                $users = $userModel->get_users_by_group($group_id);
                $this->view->users_table = $this->createTable_users("Group [#$group_id] users", array("#", "Name", "Affiliation", "Edit"), array("id", "name", "gp"), $users, $group_id);
                
                Db::commitTransaction();
            } catch (MmdbException $ex) {
                Db::rollbackTransaction();
                $this->alert->error('Error, please contact administrator.');
            }
        }


        $this->view->groups_table = $this->createTable_groups('Edit group', array('#', "Name"), array('id', 'gp_name'), $groups);
        $this->view->navigator = $this->createNavigator(self::M_USERS);
        $this->title = 'Edit users';
        $this->breadcrumbs = Breadcrumbs::instance()->add('Administration', 'administration')->add('Edit access rights');
    }

    /**
     * Publication editor
     * 
     * @param integer $id
     * 
     */
    public function publication($id = NULL)
    {
        $publication = new Publications($id);

        if ($_POST) 
        {
            if(!$publication->id)
            {
                $this->alert->warning('Publication not found.');
                $this->redirect('edit/publication');
            }
            
            try 
            {
                // Check if exists
                $check_doi = $publication->where(
                    array
                    (
                        'doi' => $_POST['doi'],
                        'id !=' => $publication->id 
                    ))
                    ->get_one();

                if($check_doi->id)
                {
                    throw new MmdbException('Publication with given DOI already exists.');
                }

                $check_pmid = $publication->where(
                    array
                    (
                        'pmid' => $_POST['pmid'],
                        'id !=' => $publication->id 
                    ))
                    ->get_one();

                if($check_pmid->id)
                {
                    throw new MmdbException('Publication with given PmID already exists.');
                }

                // Save new one
                $publication->doi = $_POST['doi'];
                $publication->citation = $_POST['citation'];
                $publication->authors = $_POST['authors'];
                $publication->pmid = $_POST['pmid'];
                $publication->title = $_POST['title'];
                $publication->journal = $_POST['journal'];
                $publication->volume = $_POST['volume'];
                $publication->issue = $_POST['issue'];
                $publication->page = $_POST['page'];
                $publication->year = $_POST['year'];
                $publication->publicated_date = isset($_POST['publicated_date']) && !empty($_POST['publicated_date']) ? date('Y-m-d', strtotime($_POST['publicated_date'])) : NULL;
                $publication->user_id = $_SESSION['user']['id'];

                $publication->set_empty_vals_to_null();

                $publication->save();
                
                $this->alert->success("Saved");
            } 
            catch (Exception $ex) 
            {
                $this->alert->error($ex->getMessage());
            }
        }

        $this->view = new View('edit/publication');

        if($publication->id)
        {
            $publication->publicated_date = $publication->publicated_date ? date('d-m-Y', strtotime($publication->publicated_date)) : NULL;
            $this->view->detail = $publication;
        }

        $this->view->publications = $publication->get_all();
        $this->view->navigator = $this->createNavigator(self::M_PUBLICATION);
        $this->title = 'Edit publication';
        $this->breadcrumbs = Breadcrumbs::instance()->add('Administration', 'administration')->add('Edit publications');
    }


    /**
     * Method editor
     * 
     * @param string $type
     * @param integer $id
     * 
     */
    public function method($type = self::T_DETAIL, $id = NULL)
    {
        $method = new Methods($id);

        // Change membrane detail
        if ($type == self::T_DETAIL) 
        {
            // Is submitted post?
            if ($this->form->is_post()) 
            {
                // exists membrane?
                if (!$method->id) 
                {
                    $this->alert->error('Method was not found.');
                    $this->redirect('edit/method');
                }

                try 
                {
                    // Save changes
                    $method->name = $this->form->param->name;
                    $method->keywords = $this->form->param->keywords;
                    $method->CAM = $this->form->param->CAM;
                    $method->description = $this->form->param->description;
                    $method->references = $this->form->param->reference;

                    $method->save();

                    $this->alert->success("Saved");
                    $this->redirect('edit/method');
                } 
                catch (MmdbException $e) 
                {
                    $this->alert->error('Error during saving method detail.');
                    $this->redirect('edit/method');
                }
            }

            $this->view = new View('edit/method/detail');
            $this->view->pictures = $this->loadPictures();
        }
        else 
        {
            $this->alert->warning('Wrong parameter');
            $this->redirect('edit/method');
        }

        // Get all method
        $this->view->methods = $method->get_all();
        $this->view->navigator = $this->createNavigator(self::M_METHOD);
        $this->title = 'Edit method';
        $this->breadcrumbs = Breadcrumbs::instance()->add('Administration', 'administration')->add('Edit methods');
    }

    /**
     * Membrane editor
     * 
     * @param integer $id
     * 
     */
    public function membrane($type = self::T_DETAIL, $id = NULL)
    {
        $membrane = new Membranes($id);

        // Change membrane detail
        if($type == self::T_DETAIL)
        {
            // Is submitted post?
            if($this->form->is_post())
            {
                // exists membrane?
                if (!$membrane->id) 
                {
                    $this->alert->error('Membrane was not found.');
                    $this->redirect('edit/membrane');
                }

                try
                {
                    // Save changes
                    $membrane->name = $this->form->param->name;
                    $membrane->keywords = $this->form->param->keywords;
                    $membrane->CAM = $this->form->param->CAM;
                    $membrane->description = $this->form->param->description;
                    $membrane->references = $this->form->param->reference;

                    $membrane->save();

                    $this->alert->success("Saved");
                    $this->redirect('edit/membrane');
                }
                catch(MmdbException $e)
                {
                    $this->alert->error('Error during saving membrane detail.');
                    $this->redirect('edit/membrane');
                }
            }

            $this->view = new View('edit/membrane/detail');
            $this->view->pictures = $this->loadPictures();
        }
        else
        {
            $this->alert->warning('Wrong parameter');
            $this->redirect('edit/membrane');
        }

        // Get all membranes
        $this->view->membranes = $membrane->order_by('name')->get_all();
        $this->view->navigator = $this->createNavigator(self::M_MEMBRANE, $type);
        $this->title = 'Edit membranes';
        $this->breadcrumbs = Breadcrumbs::instance()->add('Administration', 'administration')->add('Edit membranes');
    }

    /**
     * Gets all pictures
     */
    private function loadPictures()
    {
        $pics = scandir(MEDIA_ROOT . "files/pictures/");
        $res = [];
        $i = 0;
        for ($j = 0; $j < count($pics); $j++) 
        {
            if ($pics[$j] == "." || $pics[$j] === "..")
                continue;
            $res[$i] = $pics[$j];
            $i++;
        }
        return $res;
    }

    /**
     * Creates table with user's groups
     */
    private function createTable_groups($name, $header, $keys, $data)
    {
        $result = '<h4><b>' . $name . '</b></h4><table class="dataset-table"><thead><tr>';

        foreach ($header as $h) {
            $result .= "<td>$h</td>";
        }

        $result .= '</tr></thead><tbody>';

        foreach ($data as $d) {
            $result .= "<tr onclick=\"redirect('edit/user/$d[0]')\">";
            foreach ($keys as $key) {
                $result .= "<td>$d[$key]</td>";
            }
            $result .= "</tr>";
        }
        $result .= "</tbody></table>";
        return $result;
    }

    /**
     * Creates user's table
     */
    private function createTable_users($name, $header, $keys, $data, $idGroup)
    {
        $result = '<h4><b>' . $name . '</b></h4><table class="dataset-table"><thead><tr>';
        foreach ($header as $h) 
        {
            $result .= "<td>$h</td>";
        }

        $result .= '</tr></thead><tbody>';
        foreach ($data as $d) 
        {
            $result .= "<tr>";
            for ($i = 0; $i < 2; $i++) 
            {
                $result .= "<td>" . $d[$keys[$i]] . "</td>";
            }

            if ($d['gp'] == 1)
                $result .= "<td>YES</td>";
            else
                $result .= "<td>NO</td>";
            if ($d['id'] == 5)
                $result .= '<td></td>';
            else
                $result .= "<td><span onclick=\"redirect('edit/user/$idGroup/$d->id')\" class=\"glyphicon glyphicon-edit\"></span></td>";

            $result .= "</tr>";
        }
        $result .= "</tbody></table>";
        return $result;
    }


    /**
     * Makes dataset interaction table
     * 
     * @param array $interactions
     * @param integer $id_dataset
     * 
     * @return string HTML
     */
    private function createInteractionTable($interactions, $id_dataset)
    {
        $thead = array
        (
            'edit' => 'Edit', 'delete' => 'Delete', 'id' => 'ID', 'name' => 'Molecule', "comment" => "Note", 'charge' => 'Q', 'LogP' => 'LogP', 'LogK' => 'LogK',
            'Position' => 'X_min', 'Penetration' => 'G_pen', 'Water' => 'G_wat', 'LogPerm' => 'LogPerm', 'theta' => 'Theta', 'abs_wl' => 'abs_wl', 'fluo_wl' => 'fluo_wl',
            'QY' => 'QY', 'lt' => 'lt'
        );
        $hidden = array();

        foreach ($interactions as $i) 
        {
            $i->name = $i->substance->name;

            foreach ($thead as $key => $v) 
            {
                if (in_array($key, array('edit','delete')))
                {
                    continue;
                }

                if (($i->$key === NULL || $i->$key == "NULL" || $i->$key == '') && (!isset($hidden[$key]) || $hidden[$key] == 0))
                {
                    $hidden[$key] = 0;
                }
                else
                {
                    $hidden[$key] = 1;
                }
            }
        }


        $table = '<table class="dataset-table"><thead><tr>';

        foreach ($thead as $key => $h) 
        {
            if (isset($hidden[$key]) && $hidden[$key] == 0)
                continue;
            $table .= '<td>' . $h . '</td>';
        }

        $table .= '</tr></thead><tbody>';

        foreach ($interactions as $i) 
        {
            $table .= '<tr id="' . $i->id . '">';
            foreach ($thead as $key => $v) 
            {
                if (($key !== 'edit' && $key !== 'delete'))
                    if ($hidden[$key] == 0)
                        continue;
                if ($v == 'Edit')
                    $table .= '<td onclick="modal_editor(\'' . $i->id . '\')"><span class="glyphicon glyphicon-pencil"></span></td>';

                else if ($v == 'Delete')
                    $table .= '<td onclick="delete_interaction(\'' . $id_dataset . '\',\'' . $i->id . '\')"><span style="color:red;" class="glyphicon glyphicon-remove"></span></td>';

                else
                    $table .= '<td>' . $i->$key . '</td>';
            }

            $table .= '</tr>';
        }

        $table .= '</tbody></table>';
        
        return $table;
    }

    /**
     * Makes dataset transporters table
     * 
     * @param array $tranporters
     * @param integer $id_dataset
     * 
     * @return string HTML
     */
    private function createTransportersTable($tranporters, $id_dataset)
    {
        $thead = array
        (
            'edit' => 'Edit', 'delete' => 'Delete',
            'id' => 'ID', 'name' => 'Molecule', 'target' => 'Target', 'LogP' => 'LogP',
            'type' => "Type", "Km" => "Km", "Ki" => "Ki", 'EC50' => 'EC50', 'IC50' => "IC50", 'id_reference' => "Reference_id"
        );
        $hidden = array();

        foreach ($tranporters as $i) 
        {
            $i->name = $i->substance->name;

            foreach ($thead as $key => $v) 
            {
                if (in_array($key, array('edit','delete')))
                {
                    continue;
                }

                if (($i->$key === NULL || $i->$key == "NULL" || $i->$key == '') && (!isset($hidden[$key]) || $hidden[$key] == 0))
                {
                    $hidden[$key] = 0;
                }
                else
                {
                    $hidden[$key] = 1;
                }
            }
        }


        $table = '<table class="dataset-table"><thead><tr>';

        foreach ($thead as $key => $h) 
        {
            if (isset($hidden[$key]) && $hidden[$key] == 0)
                continue;
            $table .= '<td>' . $h . '</td>';
        }

        $table .= '</tr></thead><tbody>';

        foreach ($tranporters as $i) 
        {
            $table .= '<tr id="' . $i->id . '">';
            foreach ($thead as $key => $v) 
            {
                if (($key !== 'edit' && $key !== 'delete'))
                    if ($hidden[$key] == 0)
                        continue;
                if ($v == 'Edit')
                    $table .= '<td onclick="modal_editor(\'' . $i->id . '\', \'true\')"><span class="glyphicon glyphicon-pencil"></span></td>';

                else if ($v == 'Delete')
                    $table .= '<td onclick="delete_transporter(\'' . $id_dataset . '\',\'' . $i->id . '\')"><span style="color:red;" class="glyphicon glyphicon-remove"></span></td>';

                else if($v == 'Target')
                {
                    $table .= '<td>' . ($i->target ? $i->target->name : '') . '</td>';
                }
                else if($v == 'Type')
                {
                    $table .= '<td>' . $i->get_enum_type() . '</td>';
                }
                else
                    $table .= '<td>' . $i->$key . '</td>';
            }

            $table .= '</tr>';
        }

        $table .= '</tbody></table>';

        return $table;
    }


    /**
     * Creates navigator for editor
     * 
     * @param string $active
     * @param string $active_submenu
     * 
     * @return HTML
     */
    public function createNavigator($active, $active_submenu = NULL)
    {
        // Editor sections
        $sections = array
        (
            array
            (
                'type'  => self::M_ARTICLE,
                'name' => 'Article',
                'glyphicon' => 'align-left',
                'ref' => $this->menu_endpoints[self::M_ARTICLE]
            ),
            array
            (
                'type'  => self::M_DATASET_INTER,
                'name' => 'Interactions',
                'glyphicon' => 'folder-open',
                'ref' => $this->menu_endpoints[self::M_DATASET_INTER]
            ),
            array
            (
                'type'  => self::M_MEMBRANE,
                'name' => 'Membrane',
                'glyphicon' => 'option-vertical',
                'ref' => $this->menu_endpoints[self::M_MEMBRANE]
            ),
            array
            (
                'type'  => self::M_METHOD,
                'name' => 'Method',
                'glyphicon' => 'asterisk',
                'ref' => $this->menu_endpoints[self::M_METHOD]
            ),
            array
            (
                'type'  => self::M_PUBLICATION,
                'name' => 'Publication',
                'glyphicon' => 'link',
                'ref' => $this->menu_endpoints[self::M_PUBLICATION]
            ),
            array
            (
                'type'  => self::M_DATASET_TRANS,
                'name' => 'Transporters',
                'glyphicon' => 'folder-open',
                'ref' => $this->menu_endpoints[self::M_DATASET_TRANS]
            ),
            array
            (
                'type'  => self::M_USERS,
                'name' => 'Users',
                'glyphicon' => 'user',
                'ref' => $this->menu_endpoints[self::M_USERS]
            )
        );

        $navigator = '<div class="btn-group btn-group-justified">';
        $subnavigator = '<div class="btn-group btn-group-justified">';
        
        foreach($sections as $s)
        {
            $navigator .= '<a href="/edit/' . $s['ref'] . '" class="btn btn-primary ';
            if($active === $s['type'])
            {
                $navigator .= 'active';
            }

            $navigator .= '"> <span class="glyphicon glyphicon-' . $s['glyphicon'] . '"></span>  ' . $s['name'] . '</a>';
        }

        // Exists subnavigator options?
        if(isset($this->submenu[$active]))
        {
            foreach($this->submenu[$active] as $type => $section)
            {
                $subnavigator .= '<a href="/edit/' . $section['ref'] . '" class="btn btn-primary ';
                if ($active_submenu === $type) 
                {
                    $subnavigator .= 'active';
                }
                if(isset($section['glyphicon']))
                {
                    $subnavigator .= '"> <span class="glyphicon glyphicon-' . $section['glyphicon'] . '"></span>  ' . $section['title'] . '</a>';
                }
                else
                {
                    $subnavigator .= '"> ' . $section['title'] . '</a>';
                }

            }

            $subnavigator .= '</div>';
        }
        else
        {
            $subnavigator = '';
        }

        $navigator .= '</div>';

        return $navigator . $subnavigator;
    }
}