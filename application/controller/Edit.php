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
            self::M_MEMBRANE => array
            (
                self::T_DETAIL => array
                (
                    'ref' => $this->menu_endpoints[self::M_MEMBRANE] . '/' . self::T_DETAIL,
                    'title' => 'Detail',
                    'glyphicon' => "align-left"
                ),
                self::T_CATEGORY => array
                (
                    'ref' => $this->menu_endpoints[self::M_MEMBRANE] . '/' . self::T_CATEGORY,
                    'title' => 'Category',
                    'glyphicon' => "folder-open"
                )
            )
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
            $this->addMessageError('Parameter');
            $this->redirect('error');
        }

        $id = $articles_model->get_id_by_type($type);

        $detail = new Articles($id);

        if(!$detail->id)
        {
            $this->addMessageError('Article was not found.');
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
                
                $this->addMessageSuccess("Saved");
            }
            catch(ErrorUser $ex)
            {
                Db::rollbackTransaction();
                $this->addMessageError($ex);
            }
        }

        $this->data["detail"] = $detail;
        $this->header['title'] = 'Editor';
        $this->data['navigator'] = $this->createNavigator(self::M_ARTICLE, $type);
        $this->view = 'edit/' . $type;
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

        $detail = new Substances($id);

        // Checks if compound exists
        if(!$detail->id)
        {
            $this->addMessageError('Param');
            $this->redirect('error');
        }

        // Upload new 3D structure file
        if($spec_type == self::T_3D_STRUCTURE)
        {
            try 
            {
                $file = $_FILES["structure"];

                if ($file['name'] != '')
                {
                    $this->upload_3d_structure($file, $detail);
                }
                else 
                {
                    $fileURL = $_POST['fileURL'];
                    $this->upload_3d_structure($fileURL, $detail, True);
                }

                $this->addMessageSuccess('3D Structure was saved.');
            } 
            catch (Exception $ex) 
            {
                $this->addMessageError($ex->getMessage());
            }

            $this->redirect('edit/compound/' . $id);
        }
        else if ($_POST) 
        {
            // Edit substance
            try 
            {
                $detail->name = $_POST['name'];
                $detail->MW = $_POST['MW'];
                $detail->SMILES = $_POST['SMILES'];
                $detail->LogP = $_POST['LogP'];
                $detail->Area = $_POST['Area'];
                $detail->Volume = $_POST['Volume'];
                $detail->pdb = $_POST['pdb'];
                $detail->pubchem = $_POST['pubchem'];
                $detail->drugbank = $_POST['drugbank'];
                $detail->chEBI = $_POST['chEBI'];
                $detail->chEMBL = $_POST['chEMBL'];

                $detail->save();

                $this->addMessageSuccess('Saved');
                $this->redirect('edit/compound/' . $detail->id);
            } 
            catch (Exception $ex) 
            {
                $this->addMessageError('Error: ' . $ex->getMessage());
            }
        }

        $assigned_interactions = $interactModel
            ->where('id_substance', $id)
            ->get_all();

        $this->data['detail'] = $detail;
        $this->data['interactions_all'] = $assigned_interactions;
        $this->header['title'] = 'Edit compound';

        $this->view = 'edit/compound';
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
            $this->addMessageError('Interaction was not found.');
            $this->redirect($redir_path ? $redir_path : 'error');
        }

        if($this->form->is_post())
        {
            try 
            {
                if($this->form->param->Position_acc && !$this->form->param->Position)
                {
                    throw new Exception('Invalid position value.');
                }
                if($this->form->param->Penetration_acc && !$this->form->param->Penetration)
                {
                    throw new Exception('Invalid Penetration value.');
                }
                if($this->form->param->Water_acc && !$this->form->param->Water)
                {
                    throw new Exception('Invalid Water value.');
                }
                if($this->form->param->LogPerm_acc && !$this->form->param->LogPerm)
                {
                    throw new Exception('Invalid LogPerm value.');
                }
                if($this->form->param->theta_acc && !$this->form->param->theta)
                {
                    throw new Exception('Invalid theta value.');
                }
                if($this->form->param->abs_wl_acc && !$this->form->param->abs_wl)
                {
                    throw new Exception('Invalid abs_wl value.');
                }
                if($this->form->param->fluo_wl_acc && !$this->form->param->fluo_wl)
                {
                    throw new Exception('Invalid fluo_wl value.');
                }
                if($this->form->param->QY_acc && !$this->form->param->QY)
                {
                    throw new Exception('Invalid QY value.');
                }
                if($this->form->param->lt_acc && !$this->form->param->lt)
                {
                    throw new Exception('Invalid lt value.');
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

                $this->addMessageSuccess('Saved');

                $this->redirect($redir_path ? $redir_path : 'edit/compound/' . $interaction->substance->id);
            } 
            catch (Exception $ex) 
            {
                $this->addMessageError('Error: ' . $ex->getMessage());
                $this->redirect('edit/interaction/' . $interaction->id);
            }
        }

        $this->data['detail'] = $interaction;
        $this->header['title'] = "Interaction [$interaction->id] editor";
        $this->view = 'edit/interaction';
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
       
        if ($file_model->structure_file_exists($substance->identifier)) 
        {
            throw new Exception("Sorry, 3D structure file already exists.");
        }

        $file_model->make_path(File::FOLDER_3DSTRUCTURES);
       
        if($fileURL)
        {
            return file_put_contents($target_file, fopen($file, 'r'));
        }
        else
        {
            if($file["size"] > 100000)
            {
                throw new Exception('Sorry, your file is too large.');
            }
            if(move_uploaded_file($file['tmp_name'], $target_file))
            {
                return True;
            }
            else 
            {
                throw new Exception('Problem with saving file to target folder.');
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
            $this->addMessageError('Dataset not found');
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
                            $this->addMessageSuccess("Access rights was successfully changed!");
                        } 
                        catch (Exception $ex) 
                        {
                            Db::rollbackTransaction();
                            $this->addMessageError($ex->getMessage());
                        }
                        break;

                    case 'edit_basic':
                        try
                        {
                            // Access rights check
                            $this->verifyUser(true, 'edit_dataset', array('id_dataset' => $id, "url" => "edit/dataset/$id"));

                            Db::beginTransaction();

                            if(intval($_POST['id_dataset']) !== intval($dataset->id))
                            {
                                throw new Exception('Wrong dataset instance.');
                            }

                            // Update data
                            $dataset->name = $_POST['dataset_name'];
                            $dataset->id_membrane = $_POST['dataset_membrane'];
                            $dataset->id_method = $_POST['dataset_method'];
                            $dataset->id_publication = $_POST['dataset_publication'];
                            $dataset->visibility = $_POST['visibility'];
                            
                            $dataset->save();
                            
                            Db::commitTransaction();

                            $this->addMessageSuccess("Successfully updated!");
                        } 
                        catch (Exception $ex) 
                        {
                            Db::rollbackTransaction();
                            $this->addMessageError($ex->getMessage());
                        }
                        break;

                    case 'edit_interaction':
                        // Check access rights
                        $this->verifyUser(true, 'edit_dataset', array('id_dataset' => $id, "url" => "edit/dataset/$id"));
                        
                        try 
                        {
                            $interaction = new Interactions($_POST['id_interaction']);
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

                            // Edit substance
                            $substance->name = $_POST['substance_name'];
                            $substance->SMILES = $_POST['SMILES'];
                            $substance->LogP = $_POST['LogP'];
                            $substance->pubchem = $_POST['pubchem'];
                            $substance->pdb = $_POST['pdb'];
                            $substance->chEBI = $_POST['chEBI'];
                            $substance->chEMBL = $_POST['chEMBL'];
                            $substance->drugbank = $_POST['drugbank'];

                            $substance->save();

                            // Edit interaction
                            $interaction->Position = $_POST['Position'];
                            $interaction->Position_acc = $_POST['Position_acc'];
                            $interaction->Penetration = $_POST['Penetration'];
                            $interaction->Penetration_acc = $_POST['Penetration_acc'];
                            $interaction->Water = $_POST['Water'];
                            $interaction->Water_acc = $_POST['Water_acc'];
                            $interaction->LogK = $_POST['LogK'];
                            $interaction->LogK_acc = $_POST['LogK_acc'];
                            $interaction->LogPerm = $_POST['LogPerm'];
                            $interaction->LogPerm_acc = $_POST['LogPerm_acc'];
                            $interaction->theta = $_POST['theta'];
                            $interaction->theta_acc = $_POST['theta_acc'];
                            $interaction->abs_wl = $_POST['abs_wl'];
                            $interaction->abs_wl_acc = $_POST['abs_wl_acc'];
                            $interaction->fluo_wl = $_POST['fluo_wl'];
                            $interaction->fluo_wl_acc = $_POST['fluo_wl_acc'];
                            $interaction->QY = $_POST['QY'];
                            $interaction->QY_acc = $_POST['QY_acc'];
                            $interaction->lt = $_POST['lt'];
                            $interaction->lt_acc = $_POST['lt_acc'];
                            $interaction->temperature = $_POST['temperature'];
                            $interaction->charge = $_POST['charge'];

                            $interaction->save();

                            Db::commitTransaction();
                            $this->addMessageSuccess($substance->name . " was successfully updated!");
                        } catch (Exception $ex) 
                        {
                            Db::rollbackTransaction();
                            $this->addMessageError($ex->getMessage());
                        }
                        break;

                    default:
                        throw new Exception('Wrong request type.');
                        break;
                }

                $this->redirect('edit/dsInteractions/' . intval($id));
            }
            catch(Exception $e)
            {
                $this->addMessageError($e->getMessage());
                $this->redirect('edit/v/' . intval($id));
            }
        }

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

            $this->data['info'] = $dataset;
            $this->data['interaction_table'] = self::createInteractionTable($dataset_interactions, $dataset->id);
            $this->data['total'] = $total;
            $this->data['pagination'] = $pagination;
            $this->data['rights_users'] = $dataset->get_rights();
            $this->data['rights_groups'] = $dataset->get_rights(true);
        }

        $this->data['show_detail'] = $show_detail;
        // Get all datasets
        $this->data['datasets'] = $dataset
            ->order_by('createDateTime', 'DESC')
            ->get_all();

        $this->header['title'] = 'Dataset editor';
        $this->data['navigator'] = $this->createNavigator(self::M_DATASET_INTER);
        $this->view = 'edit/interaction_dataset';
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
            $this->addMessageError('Dataset not found');
            $this->redirect('edit/dataset');
        }

        // Is loaded dataset ? Then show detail
        $show_detail = $dataset->id ? True : False;

        if ($_POST) 
        {
            try
            {
                switch ($_POST['spec_type']) 
                {
                    case 'edit_rights':
                        // try 
                        // {
                        //     //Check access rights
                        //     $this->verifyUser(true, 'edit_dataset', array('id_dataset' => $id, "url" => "edit/dataset/$id"));

                        //     Db::beginTransaction();

                        //     $id_entity = $_POST['id_entity'];
                        //     $group = $_POST['group'];

                        //     $dataset->change_rights($id_entity, $group);
                            
                        //     Db::commitTransaction();
                        //     $this->addMessageSuccess("Access rights was successfully changed!");
                        // } 
                        // catch (Exception $ex) 
                        // {
                        //     Db::rollbackTransaction();
                        //     $this->addMessageError($ex->getMessage());
                        // }
                        $this->addMessageWarning('Sorry, service is temporary unavailable.');
                        break;

                    case 'edit_basic':
                        try
                        {
                            // Access rights check
                            // $this->verifyUser(true, 'edit_dataset', array('id_dataset' => $id, "url" => "edit/dataset/$id"));

                            Db::beginTransaction();

                            if(intval($_POST['id_dataset']) !== intval($dataset->id))
                            {
                                throw new Exception('Wrong dataset instance.');
                            }

                            // Update data
                            $dataset->name = $_POST['dataset_name'];
                            $dataset->id_reference = $_POST['dataset_publication'];
                            $dataset->visibility = $_POST['visibility'];
                            
                            $dataset->save();
                            
                            Db::commitTransaction();

                            $this->addMessageSuccess("Successfully updated!");
                        } 
                        catch (Exception $ex) 
                        {
                            Db::rollbackTransaction();
                            $this->addMessageError($ex->getMessage());
                        }
                        break;

                    // case 'edit_interaction':
                    //     // Check access rights
                    //     $this->verifyUser(true, 'edit_dataset', array('id_dataset' => $id, "url" => "edit/dataset/$id"));
                        
                    //     try 
                    //     {
                    //         $interaction = new Interactions($_POST['id_interaction']);
                    //         $substance = new Substances($interaction->id_substance);

                    //         if(!$interaction->id)
                    //         {
                    //             throw new Exception('Interaction was not found.');
                    //         }

                    //         if(!$substance->id)
                    //         {
                    //             throw new Exception('Substance was not found.');
                    //         }

                    //         Db::beginTransaction();

                    //         // Edit substance
                    //         $substance->name = $_POST['substance_name'];
                    //         $substance->SMILES = $_POST['SMILES'];
                    //         $substance->LogP = $_POST['LogP'];
                    //         $substance->pubchem = $_POST['pubchem'];
                    //         $substance->pdb = $_POST['pdb'];
                    //         $substance->chEBI = $_POST['chEBI'];
                    //         $substance->chEMBL = $_POST['chEMBL'];
                    //         $substance->drugbank = $_POST['drugbank'];

                    //         $substance->save();

                    //         // Edit interaction
                    //         $interaction->Position = $_POST['Position'];
                    //         $interaction->Position_acc = $_POST['Position_acc'];
                    //         $interaction->Penetration = $_POST['Penetration'];
                    //         $interaction->Penetration_acc = $_POST['Penetration_acc'];
                    //         $interaction->Water = $_POST['Water'];
                    //         $interaction->Water_acc = $_POST['Water_acc'];
                    //         $interaction->LogK = $_POST['LogK'];
                    //         $interaction->LogK_acc = $_POST['LogK_acc'];
                    //         $interaction->LogPerm = $_POST['LogPerm'];
                    //         $interaction->LogPerm_acc = $_POST['LogPerm_acc'];
                    //         $interaction->theta = $_POST['theta'];
                    //         $interaction->theta_acc = $_POST['theta_acc'];
                    //         $interaction->abs_wl = $_POST['abs_wl'];
                    //         $interaction->abs_wl_acc = $_POST['abs_wl_acc'];
                    //         $interaction->fluo_wl = $_POST['fluo_wl'];
                    //         $interaction->fluo_wl_acc = $_POST['fluo_wl_acc'];
                    //         $interaction->QY = $_POST['QY'];
                    //         $interaction->QY_acc = $_POST['QY_acc'];
                    //         $interaction->lt = $_POST['lt'];
                    //         $interaction->lt_acc = $_POST['lt_acc'];
                    //         $interaction->temperature = $_POST['temperature'];
                    //         $interaction->charge = $_POST['charge'];

                    //         $interaction->save();

                    //         Db::commitTransaction();
                    //         $this->addMessageSuccess($substance->name . " was successfully updated!");
                    //     } catch (Exception $ex) 
                    //     {
                    //         Db::rollbackTransaction();
                    //         $this->addMessageError($ex->getMessage());
                    //     }
                    //     break;

                    default:
                        throw new Exception('Wrong request type.');
                        break;
                }

                $this->redirect('edit/dsTransporters/' . intval($id));
            }
            catch(Exception $e)
            {
                $this->addMessageError($e->getMessage());
                $this->redirect('edit/dsTransporters/' . intval($id));
            }
        }

        // If dataset exists, show detail
        if($dataset->id)
        {
            $transporters_model = new Transporters();

            $dataset_transporters = $transporters_model
                ->where('id_dataset', $dataset->id)
                ->get_all();

            $this->data['info'] = $dataset;
            $this->data['transporters_table'] = self::createTransportersTable($dataset_transporters, $dataset->id);
            // $this->data['rights_users'] = $dataset->get_rights();
            // $this->data['rights_groups'] = $dataset->get_rights(true);
        }

        $this->data['show_detail'] = $show_detail;
        // Get all datasets
        $this->data['datasets'] = $dataset
            ->order_by('create_datetime', 'DESC')
            ->get_all();

        $this->header['title'] = 'Dataset editor';
        $this->data['navigator'] = $this->createNavigator(self::M_DATASET_TRANS);
        $this->view = 'edit/transporter_dataset';
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

        $this->data['users_table'] = '';

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

                $this->addMessageSuccess('Successfully edited.');
                $this->redirect('edit/user/' . $group_id);
            } 
            catch (Exception $ex) 
            {
                Db::rollbackTransaction();
                $this->addMessageError('Error, please contact administrator.');
            }
        }
        // Load group detail
        else if ($group_id)
        {
            try 
            {
                Db::beginTransaction();

                $users = $userModel->get_users_by_group($group_id);
                $this->data['users_table'] = $this->createTable_users("Group [#$group_id] users", array("#", "Name", "Affiliation", "Edit"), array("id", "name", "gp"), $users, $group_id);
                
                Db::commitTransaction();
            } catch (Exception $ex) {
                Db::rollbackTransaction();
                $this->addMessageError('Error, please contact administrator.');
            }
        }


        $this->data['groups_table'] = $this->createTable_groups('Edit group', array('#', "Name"), array('id', 'gp_name'), $groups);
        $this->data['navigator'] = $this->createNavigator(self::M_USERS);
        $this->header['title'] = 'Edit users';
        $this->view = 'edit/users';
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

        if($publication->id)
        {
            $this->data['detail'] = $publication;
        }

        if ($_POST) 
        {
            if(!$publication->id)
            {
                $this->addMessageWarning('Publication not found.');
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
                    throw new Exception('Publication with given DOI already exists.');
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
                    throw new Exception('Publication with given PmID already exists.');
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
                $publication->publicated_date = $_POST['publicated_date'];
                $publication->user_id = $_SESSION['user']['id'];

                $publication->set_empty_vals_to_null();

                $publication->save();
                
                $this->addMessageSuccess("Saved");
            } 
            catch (Exception $ex) 
            {
                $this->addMessageError($ex->getMessage());
            }
        }

        $this->data['publications'] = $publication->get_all();
        $this->data['navigator'] = $this->createNavigator(self::M_PUBLICATION);
        $this->header['title'] = 'Edit publication';
        $this->view = 'edit/publication';
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
            if ($_POST) 
            {
                // exists membrane?
                if (!$method->id) 
                {
                    $this->addMessageError('Method was not found.');
                    $this->redirect('edit/method');
                }

                try 
                {
                    // Save changes
                    $method->name = $_POST['name'];
                    $method->keywords = $_POST['keywords'];
                    $method->CAM = $_POST['CAM'];
                    $method->description = $_POST['description'];
                    $method->references = $_POST['reference'];

                    $method->save();

                    $this->addMessageSuccess("Saved");
                    $this->redirect('edit/method');
                } 
                catch (Exception $e) 
                {
                    $this->addMessageError('Error during saving method detail.');
                    $this->redirect('edit/method');
                }
            }

            $this->data['pictures'] = $this->loadPictures();
            $this->view = 'edit/method/detail';
        }
        // Change method category
        // else if ($type == self::T_CATEGORY) {
        //     // Is submitted post?
        //     if ($_POST) {
        //         // exists membrane?
        //         if (!$method->id) {
        //             $this->addMessageError('Membrane was not found.');
        //             $this->redirect('edit/membrane');
        //         }

        //         try {
        //             // Save changes
        //             $method->editCategory($_POST['idCat'], $_POST['idSubcat']);

        //             $this->addMessageSuccess("Saved");
        //             $this->redirect('edit/membrane');
        //         } catch (Exception $e) {
        //             $this->addMessageError('Error during saving membrane detail.');
        //             $this->redirect('edit/membrane');
        //         }
        //     }

        //     $this->data['categories'] = $method->get_all_categories();
        //     $this->data['subcategories'] = $method->get_all_subcategories();
        //     $this->view = 'edit/membrane/category';
        // } 
        else 
        {
            $this->addMessageWarning('Wrong parameter');
            $this->redirect('edit/method');
        }

        // Get all method
        $this->data["methods"] = $method->get_all();
        $this->data['navigator'] = $this->createNavigator(self::M_METHOD);
        $this->header['title'] = 'Edit method';
    }

    /**
     * Checks name of 3d structure files and generates new ones if missing
     * 
     */
    public function check_3d_structures()
    {
        // Check if exists and rename if wrong
        $substance_model = new Substances();

        $substances = $substance_model
            ->select_list(array
            (
                'uploadName',
                'identifier'
            ))
            ->get_all();

        $target_folder = MEDIA_ROOT . 'files/3Dstructures/';

        foreach($substances as $row)
        {
            try
            {
                $file = new File($target_folder . $row->uploadName . '.mol');

                $file->rename($row->identifier);
            }
            catch(Exception $e)
            {
                echo($e  . ' <br/>');
                continue;
            }
        }

        die;
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
            if($_POST)
            {
                // exists membrane?
                if (!$membrane->id) 
                {
                    $this->addMessageError('Membrane was not found.');
                    $this->redirect('edit/membrane');
                }

                try
                {
                    // Save changes
                    $membrane->name = $_POST['name'];
                    $membrane->keywords = $_POST['keywords'];
                    $membrane->CAM = $_POST['CAM'];
                    $membrane->description = $_POST['description'];
                    $membrane->references = $_POST['reference'];

                    $membrane->save();

                    $this->addMessageSuccess("Saved");
                    $this->redirect('edit/membrane');
                }
                catch(Exception $e)
                {
                    $this->addMessageError('Error during saving membrane detail.');
                    $this->redirect('edit/membrane');
                }
            }

            $this->data['pictures'] = $this->loadPictures();
            $this->view = 'edit/membrane/detail';
        }
        // Change membrane category
        else if($type == self::T_CATEGORY)
        {
            // Is submitted post?
            if ($_POST) 
            {
                if(!$membrane->id && isset($_POST['id']))
                {
                    $membrane = new Membranes($_POST['id']);
                }

                // exists membrane?
                if (!$membrane->id) 
                {
                    $this->addMessageError('Membrane was not found.');
                    $this->redirect('edit/membrane');
                }

                try 
                {
                    // Save changes
                    $membrane->editCategory($_POST['category_id'], $_POST['subcategory_id']);

                    $this->addMessageSuccess("Saved");
                    $this->redirect('edit/membrane/' . self::T_CATEGORY);
                } 
                catch (Exception $e) 
                {
                    $this->addMessageError('Error during saving membrane detail.');
                    $this->redirect('edit/membrane/' . self::T_CATEGORY);
                }
            }

            $this->data['categories'] = $membrane->get_all_categories();
            $this->data['subcategories'] = $membrane->get_all_subcategories();
            $this->view = 'edit/membrane/category';
        }
        else
        {
            $this->addMessageWarning('Wrong parameter');
            $this->redirect('edit/membrane');
        }

        // Get all membranes
        $this->data["membranes"] = $membrane->order_by('name')->get_all();
        $this->data['navigator'] = $this->createNavigator(self::M_MEMBRANE, $type);
        $this->header['title'] = 'Edit membranes';
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
            'edit' => 'Edit', 'delete' => 'Delete', 'id' => 'ID', 'name' => 'Molecule', 'charge' => 'Q', 'LogP' => 'LogP', 'LogK' => 'LogK',
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
            // 'edit' => 'Edit', 'delete' => 'Delete',
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
                    $table .= '<td onclick="modal_editor(\'' . $i->id . '\')"><span class="glyphicon glyphicon-pencil"></span></td>';

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
