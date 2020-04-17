<?php

/**
 * Upload controller
 */
class UploadController extends Controller 
{
    /** POST TYPES */
    const P_SAVE_DATASET = 'save_dataset';
    const P_UPLOAD_FILE = 'file_upload';

    /** MENU sections */
    const M_DATASET = 1;
    const M_ENERGY = 2;
    const M_MEMBRANE = 3;
    const M_METHOD = 4;
    const M_PICTURE = 5;
    const M_PUBLICATION = 6;
    const M_SMILES = 7;

    /** MENU ENDPOINTS */
    private $menu_endpoints = array
    (
        self::M_DATASET => 'dataset',
        self::M_ENERGY  => 'energy',
        self::M_MEMBRANE => 'membrane',
        self::M_METHOD => 'method',
        self::M_PICTURE => 'picture',
        self::M_PUBLICATION => 'publication',
        self::M_SMILES => 'smiles'
    );

    /** FILE TYPES */
    const FILE_SMILES = 1;
    const FILE_ENERGY = 2;
    const FILE_REFS = 3;

    /**
     * Constructor
     */
    function __construct()
    {
        // Only admins have access rights
        $this->verifyUser(True);

        parent::__construct();
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
     * Uploads energy file
     */
    public function energy()
    {
        $data = array();
        $membraneModel = new Membranes();
        $methodModel = new Methods();

        // Save new file
        if ($_POST && isset($_FILES['file'])) 
        {
            $file = $_FILES["file"];
            $format = 'csv';

            try 
            {
                $data = $this->uploadFile($file, $format, self::FILE_ENERGY);
                // Save energy file
                $energyModel = new Energy();
                $substanceModel = new Substances();
                $membrane = new Membranes($_POST['id_membrane']);
                $method = new Methods($_POST['id_method']);

                // Check validity
                if(!$membrane->id)
                {
                    throw new Exception('Membrane was not found.');
                }

                if(!$method->id)
                {
                    throw new Exception('Method was not found.');
                }

                // Remove header
                $header = array_shift($data);

                // Find substances
                $idSubstances = array();

                unset($header[0]);

                foreach($header as $key => $name)
                {
                    $s = $substanceModel->select_list('id')
                        ->where("name = '$name' OR uploadName = '$name'")
                        ->get_one();

                    if($s->id)
                    {
                        $idSubstances[$key] = $s->id;
                    }
                }

                foreach($data as $row)
                {
                    $distance = $row[0];

                    foreach($idSubstances as $key => $id)
                    {
                        $value = isset($row[$key]) ? $row[$key] : NULL;

                        if(!$value)
                        {
                            continue;
                        }

                        // Check if exists
                        $exists = $energyModel->where(array
                            (
                                'id_substance'   => $id,
                                'id_membrane'    => $membrane->id,
                                'id_method'      => $method->id,
                                'distance LIKE '       => $distance
                            ))
                            ->select_list('id')
                            ->get_one();

                        if($exists->id)
                        {
                            continue;
                        }

                        // save new energy value
                        $energy = new Energy();
                        $energy->id_substance = $id;
                        $energy->id_membrane = $membrane->id;
                        $energy->id_method = $method->id;
                        $energy->distance = $distance;
                        $energy->energy = $value;
                        $energy->user_id = $_SESSION['user']['id'];

                        $energy->save();
                    }
                }
            }
            catch (Exception $ex) 
            {
                $this->addMessageError('Problem ocurred during uploading energy file.');
                $this->addMessageError($ex->getMessage());
            }
        }

        $this->data['methods'] = $methodModel->get_all();
        $this->data['membranes'] = $membraneModel->get_all();
        $this->data['navigator'] = $this->createNavigator(self::M_ENERGY);
        $this->view = 'upload/energyfile';
        $this->header['title'] = 'Uploader';
    }


    /**
     * Membrane uploader
     */
    public function membrane()
    {
        $membrane = new Membranes();

        if ($_POST) 
        {
            try 
            {
                Db::beginTransaction();

                print_r($_POST);

                // Insert new Membrane
                $membrane->name = $_POST['name'];
                $membrane->description = $_POST['descript'];
                $membrane->keywords = $_POST['keywords'];
                $membrane->CAM = $_POST['CAM'];
                $membrane->references = $_POST['references'];
                $membrane->idTag = $_POST['tags']; 

                $membrane->save();

                // Save membrane category
                $membrane->save_membrane_category($_POST['idCat'], $_POST['idSubcat']);
                
                Db::commitTransaction();
                $this->addMessageSuccess('Membrane "' . $membrane->name . '" was inserted.');
                $this->redirect("upload/membrane");
            } 
            catch (Exception $ex) 
            {
                Db::rollbackTransaction();
                $this->addMessageError($ex->getMessage());
            }
        }

        $this->data['categories'] = $membrane->get_all_categories();
        $this->data['subcategories'] = $membrane->get_all_subcategories();
        $this->view = 'upload/membrane';
        $this->data['navigator'] = $this->createNavigator(self::M_MEMBRANE);
        $this->header['title'] = 'Uploader';
    }

    /**
     * Method uploader
     */
    public function method()
    {
        $method = new Methods();

        if ($_POST) 
        {
            try 
            {
                Db::beginTransaction();

                // Insert new Membrane
                $method->name = $_POST['name'];
                $method->description = $_POST['descript'];
                $method->keywords = $_POST['keywords'];
                $method->CAM = $_POST['CAM'];
                $method->references = $_POST['references'];
                $method->idTag = $_POST['tags'];

                $method->save();

                // Save method category
                // $method->save_method_category($_POST['idCat'], $_POST['idSubcat']);
                
                Db::commitTransaction();
                $this->addMessageSuccess('Method "' . $method->name  . '" was inserted.');
                $this->redirect("upload/method");
            } 
            catch (Exception $ex) 
            {
                Db::rollbackTransaction();
                $this->addMessageError($ex->getMessage());
            }
        }

        // $this->data['categories'] = $method->get_all_categories();
        // $this->data['subcategories'] = $method->get_all_subcategories();
        $this->view = 'upload/method';
        $this->data['navigator'] = $this->createNavigator(self::M_METHOD);
        $this->header['title'] = 'Uploader';
    }

    /**
     * Picture uploader
     */
    public function picture()
    {
        if ($_FILES) 
        {
            try 
            {
                $files = $_FILES['file'];

                $count = count($files["name"]);

                for ($i = 0; $i < $count; $i++) 
                {
                    $tmpFilePath = $files['tmp_name'][$i];
                    $name = $files['name'][$i];

                    if ($tmpFilePath != "") 
                    {
                        //Setup our new file path
                        $newFilePath = MEDIA_ROOT . "files/pictures/" . $name;

                        //Upload the file into the temp dir
                        if (!move_uploaded_file($tmpFilePath, $newFilePath)) 
                        {
                            $this->addMessageError("Error. Problem with saving picture '$name'");
                            $this->redirect("upload/picture");
                        }
                    }
                }

                $this->addMessageSuccess("Pictures were successfully uploaded.");
                $this->redirect("upload/picture");

            } 
            catch (Exception $ex) 
            {
                $this->addMessageError($ex->getMessage());
            }
        }

        $this->data['pictures'] = $this->loadPictures();
        $this->data['navigator'] = $this->createNavigator(self::M_PICTURE);
        $this->view = 'upload/picture';
        $this->header['title'] = 'Uploader';
    }

    /**
     * Gets all pictures
     */
    private function loadPictures()
    {
        $pics = scandir(MEDIA_ROOT . "files/pictures/");
        $res = [];

        foreach($pics as $p)
        {
            if($p == '.' || $p == '..')
            {
                continue;
            }

            $res[] = $p;
        }
        return $res;
    }

    /**
     * Publication uploader
     */
    public function publication()
    {
        if ($_POST) 
        {
            try 
            {
                $publication = new Publications();

                // Check if exists
                $check_doi = $publication->where(
                    array
                    (
                        'doi' => $_POST['doi']
                    ))
                    ->get_one();

                if($check_doi->id)
                {
                    throw new Exception('Publication with given DOI already exists.');
                }

                $check_pmid = $publication->where(
                    array
                    (
                        'pmid' => $_POST['pmid']
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

                $this->addMessageSuccess("Saved!");
            } 
            catch (Exception $ex) 
            {
                $this->addMessageError('Error ocurred during saving record.');
                $this->addMessageError($ex->getMessage());
            }
        }


        $this->view = 'upload/publication';
        $this->data['navigator'] = $this->createNavigator(self::M_PUBLICATION);
        $this->header['title'] = 'Uploader';
    }

    /**
     * Update smiles
     */
    public function update_smiles()
    {
        $smiles_model = new Smiles();
        try 
        {
            $smiles_model->checkSmiles();
            $this->addMessageSuccess("Smiles table was successfully updated");
        } 
        catch (Exception $ex) 
        {
            $this->addMessageError($ex->getMessage());
        }

        $this->redirect("upload/smiles");
    }

    /**
     * Smiles uploader
     * 
     * @param bool $canonize
     */
    public function smiles($canonize = false)
    {
        $data = array();

        $smilesModel = new Smiles();

        $redirection = isset($_GET['redirection']) ? $_GET['redirection'] : "upload/smiles";

        if($canonize)
        {
            // Canonizes all SMILES in DB
            try
            {
                $smilesModel->beginTransaction();

                $rdkit = new Rdkit();

                // First check, if RDKIT is working
                if(!$rdkit->is_connected())
                {
                    throw new Exception('RDKIT service is not working');
                }

                $last_update = strtotime($this->config->get(Configs::LAST_SMILES_UPDATE));

                // Process SMILES table
                $smiles = $smilesModel->where(array
                    (
                        'editDateTime >= ' => date('Y-m-d H:i:s', $last_update)
                    ))
                    ->get_all();

                foreach($smiles as $row)
                {
                    $old_smiles = $row->SMILES;

                    $canonized = $rdkit->canonize_smiles($old_smiles);

                    // Exists yet?
                    $check = $smilesModel->where(array
                        (
                            'id != ' => $row->id,
                            'SMILES' => $canonized,
                        ))
                        ->get_one();

                    if($check->id)
                    {
                        $row->delete();
                    }
                    else
                    {
                        $row->SMILES = $canonized;
                        $row->save();
                    }
                }

                // Proccess substance table
                $substanceModel = new Substances();

                $smiles = $substanceModel->select_list(array
                    (
                        'id',
                        'SMILES'
                    ))
                    ->where(array
                    (
                        'editDateTime >= ' => date('Y-m-d H:i:s', $last_update)
                    ))
                    ->get_all();

                foreach($smiles as $row)
                {
                    $old_smiles = $row->SMILES;

                    $canonized = $rdkit->canonize_smiles($old_smiles);

                    if($canonized == $old_smiles)
                    {
                        continue;
                    }

                    $row->SMILES = $canonized;
                    $row->save();
                }

                $this->config->set(Configs::LAST_SMILES_UPDATE, date('Y-m-d H:i:s'));

                $this->addMessageSuccess('Successfully updated.');

                $smilesModel->commitTransaction();
            }
            catch(Exception $e)
            {   
                $smilesModel->rollbackTransaction();
                $this->addMessageError('Cannot update smiles table.');
                $this->addMessageError($e->getMessage());
            }
            
            $this->redirect($redirection);
        }
        else if (isset($_FILES['file'])) 
        {
            $file = $_FILES['file'];
            $format = 'csv';

            try 
            {
                $data = $this->uploadFile($file, $format, self::FILE_SMILES);

                foreach($data as $row)
                {
                    $smiles = trim($row[0]);
                    $name = trim($row[1]);

                    if($smiles == '' || $name == '')
                    {
                        continue;
                    }

                    $s = new Smiles();

                    $s->name = $name;
                    $s->SMILES = $smiles;
                    $s->user_id = $_SESSION['user']['id'];

                    $s->save();
                }

                $this->addMessageSuccess('Saved');
                $this->redirect($redirection);
            } 
            catch (Exception $ex) 
            {
                $this->addMessageError($ex->getMessage());
            }
        }

        $this->view = 'upload/smiles';
        $this->data['navigator'] = $this->createNavigator(self::M_SMILES);
        $this->header['title'] = 'Uploader';
    }

    /**
     * Dataset uploader
     */
    public function dataset()
    {
        $data = array();

        if ($_POST) 
        {
            $post_type = isset($_POST["postType"]) ? $_POST["postType"] : NULL;

            // Save new datafile
            if ($post_type === self::P_SAVE_DATASET) 
            {

                $uploader = new Uploader();
                $dataset = new Datasets();

                // Get data
                $rowCount = intval($_POST['rowCount']);
                $colCount = intval($_POST['colCount']);
                $membrane_id = $_POST['membrane'];
                $method_id = $_POST['method'];
                $temperature = floatval($_POST['temp']);
                $secondary_ref_id = $_POST['reference'];

                // Checks if data are valid
                $membrane = new Membranes($membrane_id);
                $method = new Methods($method_id);
                $publication = new Publications($secondary_ref_id);
                $substanceModel = new Substances();

                if(!$membrane->id)
                {
                    $this->addMessageError('Membrane was not found.');
                    $this->redirect('upload/dataset');
                }

                if(!$method->id)
                {
                    $this->addMessageError('Method was not found.');
                    $this->redirect('upload/dataset');
                }

                $types = array
                (
                    "Name", "Primary_reference", "Q", "X_min", "X_min_acc", "G_pen", "G_pen_acc", "G_wat", 
                    "G_wat_acc", "LogK", "LogK_acc", "LogP", "LogPerm", "LogPerm_acc", "theta", "theta_acc", 
                    "abs_wl", "abs_wl_acc", "fluo_wl", "fluo_wl_acc", "QY", "QY_acc", "lt", "lt_acc",
                    "MW", "SMILES", "DrugBank_ID", "PubChem_ID", "PDB_ID", "Area", "Volume"
                );
                $values = $rows = $attrs = array();

                // Get rows
                for($i = 1; $i < $rowCount; $i++)
                {
                    // Check if exists
                    if(!isset($_POST['row_' . strval($i)]))
                    {
                        throw new Exception(PHP_POST_LIMIT);
                    }
                    
                    $r = $_POST['row_' . strval($i)];

                    //Check number of columns
                    if(count($r) != $colCount)
                    {
                        throw new Exception(PHP_POST_LIMIT);
                    }

                    $rows[] = $r;
                }

                // Get used attributes
                if(!isset($_POST['attr']) || !is_array($_POST['attr']) || 
                    count($_POST['attr']) != $colCount)
                {
                    throw new Exception('Attributes are not defined.');
                }

                foreach($_POST['attr'] as $order => $a)
                {
                    if(trim($a) == '')
                    {
                        continue;
                    }

                    if(!in_array($a, $types))
                    {
                        throw new Exception("Invalid attribute '$a'. Please, contact your administrator.");
                    }

                    $attrs[$order] = $a;
                }

                // process data
                foreach($rows as $row)
                {
                    $new = array();

                    foreach($attrs as $key => $attr)
                    {
                        $new[$attr] = trim($row[$key]) != '' ? trim($row[$key]) : NULL;
                    }

                    $values[] = $new;
                }

                // Save data
                try 
                {
                    // Transaction for whole dataset 
                    Db::beginTransaction();

                    $rdkit = new Rdkit();
                    
                    // Add new dataset
                    $dataset->id_membrane = $membrane->id;
                    $dataset->id_method = $method->id;
                    $dataset->id_publication = $publication->id;
                    $dataset->id_user_edit = $_SESSION['user']['id'];
                    $dataset->id_user_upload = $_SESSION['user']['id'];
                    $dataset->visibility = Datasets::INVISIBLE;
                    $dataset->name = $method->CAM . '_' . $membrane->CAM;

                    $dataset->save();

                    $num_compounds = 0;

                    // Add dataset rows
                    foreach($values as $detail) 
                    {
                        if (self::get_param($detail, 'Name') == '') 
                        {
                            $detail['Name'] = NULL;
                        }

                        // Canonize SMILES
                        $smiles = isset($detail['SMILES']) && trim($detail['SMILES']) != '' ? $detail['SMILES'] : NULL;

                        if($smiles && $rdkit->is_connected())
                        {
                            $smiles = $rdkit->canonize_smiles($smiles);
                        }

                        $uploader->insert_interaction(
                            $dataset->id,
                            $publication->id,
                            $detail["Name"],
                            $detail["Name"],
                            floatval(self::get_param($detail, 'MW')),
                            floatval(self::get_param($detail, 'X_min')),
                            floatval(self::get_param($detail, 'X_min_acc')),
                            floatval(self::get_param($detail, 'G_pen')),
                            floatval(self::get_param($detail, 'G_pen_acc')),
                            floatval(self::get_param($detail, 'G_wat')),
                            floatval(self::get_param($detail, 'G_wat_acc')),
                            floatval(self::get_param($detail, 'LogK')),
                            floatval(self::get_param($detail, 'LogK_acc')),
                            floatval(self::get_param($detail, 'Area')),
                            floatval(self::get_param($detail, 'Volume')),
                            floatval(self::get_param($detail, 'LogP')),
                            floatval(self::get_param($detail, 'LogPerm')),
                            floatval(self::get_param($detail, 'LogPerm_acc')),
                            floatval(self::get_param($detail, 'theta')),
                            floatval(self::get_param($detail, 'theta_acc')),
                            floatval(self::get_param($detail, 'abs_wl')),
                            floatval(self::get_param($detail, 'abs_wl_acc')),
                            floatval(self::get_param($detail, 'fluo_wl')),
                            floatval(self::get_param($detail, 'fluo_wl_acc')),
                            floatval(self::get_param($detail, 'QY')),
                            floatval(self::get_param($detail, 'QY_acc')),
                            floatval(self::get_param($detail, 'lt')),
                            floatval(self::get_param($detail, 'lt_acc')),
                            strval(self::get_param($detail, 'Q')),
                            $smiles,
                            self::get_param($detail, 'DrugBank_ID'),
                            self::get_param($detail, 'PubChem_ID'),
                            self::get_param($detail, 'PDB_ID'),
                            $membrane,
                            $method,
                            $temperature
                        );

                        $num_compounds++;
                        sleep(1);
                    }

                    // Check identifiers and names
                    $substanceModel->validate_table();

                    Db::commitTransaction();
                    $this->addMessageSuccess("$num_compounds lines successfully saved!");
                } 
                catch (Exception $ex) 
                {
                    Db::rollbackTransaction();
                    $this->addMessageError($ex->getMessage());
                }
            } 
            else if ($post_type === self::P_UPLOAD_FILE && isset($_FILES["file"])) 
            {
                $file = $_FILES["file"];
                $valid_format = 'csv';
            
                try 
                {
                    $data = $this->uploadFile($file, $valid_format);
                } 
                catch (Exception $ex) 
                {
                    $this->addMessageError($ex->getMessage());
                    $data = False;
                }

                $this->data['fileName'] = $data;
            }
            else
            {
                $this->addMessageWarning('Wrong POST parameter');
            }
        }

        $this->data['detail'] = $data;
        $this->data['navigator'] = $this->createNavigator(self::M_DATASET);
        $this->view = 'upload/dataset';
        $this->header['title'] = 'Uploader';
    }

    /**
     * Helper for checking if attr exists in arr
     * 
     * @param array $arr
     * @param string $attr
     * 
     * @return string
     */
    private static function get_param($arr, $attr)
    {
        if(!isset($arr[$attr]))
        {
            return NULL;
        }

        return $arr[$attr] != '' ? $arr[$attr] : NULL;
    }

    /**
     * Automatically fill missing 3D structures using RDKIT
     */
    public function fill3dStructures()
    {
        $this->addMessageWarning('Sorry, service is temporarily unavailable.');
        $this->redirect('upload/dataset');
    }

    /**
     * Makes upload navigator
     * 
     * @param integer $active
     * 
     * @return HTML_string
     */
    public function createNavigator($active)
    {
        $refs = $this->menu_endpoints;

        $glyphicons = array
        (
            self::M_DATASET => "file",
            self::M_ENERGY => "signal",
            self::M_MEMBRANE => "option-vertical",
            self::M_METHOD => "asterisk",
            self::M_PICTURE => "picture",
            self::M_PUBLICATION => "link",
            self::M_SMILES => "align-left"
        );

        $texts = array
        (
            self::M_DATASET => "Dataset",
            self::M_ENERGY => "Energy file",
            self::M_MEMBRANE => "Membrane",
            self::M_METHOD => "Method",
            self::M_PICTURE => "Picture",
            self::M_PUBLICATION => "Publication",
            self::M_SMILES => "SMILES"
        );

        $res = '<div class="btn-group btn-group-justified">';

        foreach($refs as $type => $endpoint)
        {
            $res .= '<a href="/upload/' . $endpoint . '" class="btn btn-primary ';
            if ($active === $type)
            {
                $res .= 'active';
            }
            $res .= '"> <span class="glyphicon glyphicon-' . $glyphicons[$type] . '"></span>  ' . $texts[$type] . '</a>';
        }
        
        $res .= '</div>';

        return $res;
    }
    
    
    /**
     * Uploads new file
     * 
     * @param file $file
     * @param string $format - Valid file format
     * @param string $type
     * 
     * @return string File path
     */
    public function uploadFile($file, $format, $file_type = NULL)
    {
        $target_dir = MEDIA_ROOT . "files/upload/";
        $newFile = new File($file);
        $newFile->name = basename($file['name']);

        $target_file = $target_dir . $newFile->name;

        $temp = $target_file;
       
        $i = 0;
        while(file_exists($temp))
        {
            $i++;
            $temp = substr($target_file, 0, -4) . "_" . $i;
            $newFile->name =  basename($file['name']) . '_' . $i;
        }
        
        $uploader  = new Uploader();
        $smilesModel = new Smiles();
        $publicationModel = new Publications();

        if(intval($newFile->size) > 5000000) // 5mb max size
        {
            $this->addMessageError('Sorry, your file is too large.');
            $this->redirect('upload/dataset');
        }

        if($newFile->extension !== 'csv') // Only CSV files allowed
        {
            $this->addMessageWarning('Wrong file format. Upload only ".csv" files.');
            $this->redirect('upload/dataset');
        }
        if($newFile->save_to_dir($target_dir)) // Finally, move file to target directory
        {
            $this->addMessageSuccess ('The file "' . $newFile->name . '" has been uploaded!');
        }

        if($file_type === self::FILE_SMILES)
        {
            return $smilesModel->loadData($target_file);
        }
        else if ($file_type === self::FILE_ENERGY)
        {
            return $uploader->loadData($target_file);
        }
        else if($file_type === self::FILE_REFS)
        {
            return $publicationModel->loadData($target_file);
        }
        else
        { 
            return $target_file;
        }
   }
}
