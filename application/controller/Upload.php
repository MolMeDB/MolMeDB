<?php

/**
 * Upload controller
 */
class UploadController extends Controller 
{
    /** POST TYPES */
    const P_SAVE_DATASET = 'save_dataset';
    const P_SAVE_TRANSPORTERS = 'save_transporters';
    const P_UPLOAD_FILE = 'file_upload';

    /** MENU sections */
    const M_DATASET = 1;
    const M_ENERGY = 2;
    const M_MEMBRANE = 3;
    const M_METHOD = 4;
    const M_PICTURE = 5;
    const M_PUBLICATION = 6;

    /** MENU ENDPOINTS */
    private $menu_endpoints = array
    (
        self::M_DATASET => 'dataset',
        self::M_ENERGY  => 'energy',
        self::M_MEMBRANE => 'membrane',
        self::M_METHOD => 'method',
        self::M_PICTURE => 'picture',
        self::M_PUBLICATION => 'publication',
    );

    /**
     * Constructor
     */
    function __construct()
    {
        // Only admins have access rights
        parent::__construct();
        $this->verifyUser(True);
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
                $data = $this->uploadFile($file, $format, Files::T_UPLOAD_ENERGY);
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
            catch (MmdbException $ex) 
            {
                $this->alert->error('Problem ocurred during uploading energy file.');
                $this->alert->error($ex);
            }
        }
        
        $this->title = 'Uploader';

        $this->view = new View('upload/energyfile');
        $this->view->methods = $methodModel->get_all();
        $this->view->membranes = $membraneModel->get_all();
        $this->view->navigator = $this->createNavigator(self::M_ENERGY);
    }


    /**
     * Membrane uploader
     */
    public function membrane()
    {
        $membrane = new Membranes();
        $et_model = new Enum_types();

        if ($this->form->is_post()) 
        {
            try 
            {
                Db::beginTransaction();

                // Insert new Membrane
                $membrane->name = $this->form->param->name;
                $membrane->description = $this->form->param->descript;
                $membrane->keywords = $this->form->param->keywords;
                $membrane->CAM = $this->form->param->CAM;
                $membrane->references = $this->form->param->references;
                $membrane->idTag = $this->form->param->tags; 

                $membrane->save();

                Db::commitTransaction();

                $this->alert->success('Membrane "' . $membrane->name . '" was saved.');
                $this->redirect("upload/membrane");
            } 
            catch (MmdbException $ex) 
            {
                Db::rollbackTransaction();
                $this->alert->error($ex);
            }
        }

        $this->title = 'Uploader';

        $this->view = new View('upload/membrane');
        $this->view->categories = $et_model->get_categories($et_model::TYPE_MEMBRANE_CATS);

        $this->view->navigator = $this->createNavigator(self::M_MEMBRANE);
    }

    /**
     * Method uploader
     */
    public function method()
    {
        $method = new Methods();

        if ($this->form->is_post()) 
        {
            try 
            {
                Db::beginTransaction();

                // Insert new Membrane
                $method->name = $this->form->param->name;
                $method->description = $this->form->param->descript;
                $method->keywords = $this->form->param->keywords;
                $method->CAM = $this->form->param->CAM;
                $method->references = $this->form->param->references;
                $method->idTag = $this->form->param->tags;

                $method->save();

                Db::commitTransaction();
                $this->alert->success('Method "' . $method->name  . '" was inserted.');
                $this->redirect("upload/method");
            } 
            catch (MmdbException $ex) 
            {
                Db::rollbackTransaction();
                $this->alert->error($ex);
            }
        }

        $this->title = 'Uploader';

        $this->view = new View('upload/method');
        $this->view->navigator = $this->createNavigator(self::M_METHOD);
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
                            $this->alert->error("Error. Problem with saving picture '$name'");
                            $this->redirect("upload/picture");
                        }
                    }
                }

                $this->alert->success("Pictures were successfully uploaded.");
                $this->redirect("upload/picture");

            } 
            catch (MmdbException $ex) 
            {
                $this->alert->error($ex);
            }
        }

        $this->title = 'Uploader';

        $this->view = new View('upload/picture');
        $this->view->pictures = $this->loadPictures();
        $this->view->navigator = $this->createNavigator(self::M_PICTURE);
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
                if(trim($_POST['doi']) != '')
                {
                    $check_doi = $publication->where(
                        array
                        (
                            'doi' => $_POST['doi']
                        ))
                        ->get_one();

                    if($check_doi->id)
                    {
                        throw new MmdbException('Publication with given DOI already exists.');
                    }
                }

                // Check if exists
                if(trim($_POST['pmid']) != '')
                {
                    $check_pmid = $publication->where(
                        array
                        (
                            'pmid' => $_POST['pmid']
                        ))
                        ->get_one();
    
                    if($check_pmid->id)
                    {
                        throw new MmdbException('Publication with given PmID already exists.');
                    }
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

                $this->alert->success("Saved!");
            } 
            catch (MmdbException $ex) 
            {
                $this->alert->error('Error ocurred during saving record.');
                $this->alert->error($ex);
            }
        }

        $this->title = 'Uploader';

        $this->view = new View('upload/publication');
        $this->view->navigator = $this->createNavigator(self::M_PUBLICATION);
    }

    /**
     * Dataset uploader
     */
    public function dataset()
    {
        $data = array();
        $fileType = NULL;

        $this->view = new View('upload/dataset');

        if ($this->form->is_post()) 
        {
            $post_type = $this->form->param->postType;
            $fileType = $this->form->param->fileType;

            // Save new datafile
            if (in_array($post_type, array(self::P_SAVE_DATASET, self::P_SAVE_TRANSPORTERS))) 
            {
                try
                {
                    if($post_type === self::P_SAVE_DATASET)
                    {
                        $this->save_dataset();
                    }
                    else if($post_type === self::P_SAVE_TRANSPORTERS)
                    {
                        $this->save_transporters();
                    }
                    else
                    {
                        throw new MmdbException('Invalid form type.');
                    }
                }
                catch (MmdbException $ex)
                {
                    $this->alert->error($ex);
                }
            } 
            // Upload new file and return databse instance with file
            else if ($post_type === self::P_UPLOAD_FILE && 
                isset($_FILES["file"]) && 
                Files::valid_type($fileType)) 
            {
                $file = $_FILES["file"];
            
                try 
                {
                    $this->view->uploaded_file = $this->uploadFile($file, $fileType);
                } 
                catch (MmdbException $ex) 
                {
                    $this->alert->error($ex);
                }
            }
            else
            {
                $this->alert->warning('Invalid POST parameter');
            }
        }

        $this->title = 'Uploader';

        $this->view->detail = $data;
        $this->view->queue = Upload_queue::instance()->order_by('id', 'DESC')->limit(30)->get_all();
        $this->view->fileType = $fileType;
        $this->view->navigator = $this->createNavigator(self::M_DATASET);
    }

    /**
     * Saves transporters dataset
     * 
     * @return string - Result message
     * 
     * @throws MmdbException
     */
    private function save_transporters()
    {
        if(!$this->form->is_post())
        {
            throw new MmdbException('Invalid post form.');
        }

        try
        {
            // Transaction for whole dataset 
            Db::beginTransaction();

            $file_object = new Files($this->form->param->file_id);

            if(!$file_object->id)
            {
                throw new MmdbException('Invalid file id.');
            }

            if(!file_exists($file_object->path))
            {
                throw new MmdbException('File not found on server. Please, reupload the file.');
            }

            $queue = new Upload_queue();
            $dataset = new Transporter_datasets();

            // Get data
            $secondary_ref_id = $this->form->param->reference;
            $delimiter = $this->form->param->delimiter;

            // Checks if data are valid
            $publication = new Publications($secondary_ref_id);

            if($delimiter == null)
            {
                throw new MmdbException('Invalid delimiter.');
            }

            // Try to parse file with current settings
            $file = new File($file_object->path);

            $content = $file->parse_csv_content($delimiter);
            
            if(empty($content) || count($content) < 2)
            {
                throw new MmdbException('', 'Invalid file content. Content is empty.');
            }

            // Remove header
            array_shift($content);

            // Get valid attribute types
            $valid_attrs = Upload_validator::get_transporter_attributes();
            $attrs = array();

            if(count($content[0]) < count($this->form->param->attr))
            {
                throw new MmdbException('', 'Invalid number of columns.');
            }

            $attrs = array();

            // Checks, if attributes are valid
            foreach($this->form->param->attr as $order => $a)
            {
                if(trim($a) == '')
                {
                    continue;
                }

                if(!in_array($a, $valid_attrs))
                {
                    throw new MmdbException("Invalid attribute '$a'. Please, contact your administrator.");
                }

                $attrs[$order] = $a;
            }

            $dataset = new Transporter_datasets($file_object->id_dataset_active);

            // Add new dataset
            if($dataset->id)
            {
                if($dataset->id_reference !== $publication->id)
                {
                    throw new MmdbException("",'Invalid dataset setting. 
                        Current file was previously uploaded with different publication selection. 
                        First, delete old dataset [id: ' . $dataset->id . '] and try again.');
                }

                $this->alert->warning('Found dataset for given file from a previous upload [ID: ' . $dataset->id . '].');  
            }
            else
            {
                $dataset->id_reference = $publication->id;
                $dataset->id_user_edit = session::user_id();
                $dataset->id_user_upload = session::user_id();
                $dataset->visibility = Datasets::INVISIBLE;
                $dataset->name = date('d-m-y H:i');

                $dataset->save();

                $file_object->id_dataset_active = $dataset->id;
                $file_object->save();
            }

            // Create new queue 
            $queue->id_dataset_active = $dataset->id;
            $queue->state = $queue::STATE_PENDING;
            $queue->id_user = session::user_id();
            $queue->id_file = $file_object->id;
            $queue->settings = json_encode(array
            (
                'delimiter' => $delimiter,
                'attributes' => $attrs
            ));

            $queue->save();
            Db::commitTransaction();
        }
        catch (MmdbException $ex)
        {
            Db::rollbackTransaction();
            throw new MmdbException($ex);
        }
    }

    /**
     * Saves interactions dataset
     * 
     * @return string - Result message
     * 
     * @throws MmdbException
     */
    private function save_dataset()
    {
        if(!$this->form->is_post())
        {
            throw new MmdbException('Invalid post form.');
        }

        try
        {
            // Transaction for whole dataset 
            Db::beginTransaction();

            $file_object = new Files($this->form->param->file_id);

            if(!$file_object->id)
            {
                throw new MmdbException('Invalid file id.');
            }

            if(!file_exists($file_object->path))
            {
                throw new MmdbException('File not found on server. Please, reupload the file.');
            }
            
            $dataset = new Datasets();
            $queue = new Upload_queue();

            // Get data
            $membrane_id = $this->form->param->membrane;
            $method_id = $this->form->param->method;
            $temperature = floatval($this->form->param->temp);
            $secondary_ref_id = $this->form->param->reference;
            $delimiter = $this->form->param->delimiter;

            // Checks if data are valid
            $membrane = new Membranes($membrane_id);
            $method = new Methods($method_id);
            $publication = new Publications($secondary_ref_id);

            if($delimiter == null)
            {
                throw new MmdbException('Invalid delimiter.');
            }

            if(!$membrane->id)
            {
                $this->alert->error('Membrane was not found.');
                $this->redirect('upload/dataset');
            }

            if(!$method->id)
            {
                $this->alert->error('Method was not found.');
                $this->redirect('upload/dataset');
            }

            // Try to parse file with current settings
            $file = new File($file_object->path);

            $content = $file->parse_csv_content($delimiter);
            
            if(empty($content) || count($content) < 2)
            {
                throw new MmdbException('', 'Invalid file content. Content is empty.');
            }

            // Remove header
            array_shift($content);

            // Get valid attribute types
            $valid_attrs = Upload_validator::get_dataset_attributes();
            $attrs = array();

            if(count($content[0]) < count($this->form->param->attr))
            {
                throw new MmdbException('', 'Invalid number of columns.');
            }

            // Checks, if attributes are valid
            foreach($this->form->param->attr as $order => $a)
            {
                if(trim($a) == '')
                {
                    continue;
                }

                if(!in_array($a, $valid_attrs))
                {
                    throw new MmdbException("Invalid attribute '$a'. Please, contact your administrator.");
                }

                $attrs[$order] = $a;
            }

            $dataset = new Datasets($file_object->id_dataset_passive);

            // Add new dataset
            if($dataset->id)
            {
                if($dataset->id_membrane !== $membrane->id || 
                    $dataset->id_method !== $method->id || 
                    $dataset->id_publication !== $publication->id)
                {
                    throw new MmdbException("",'Invalid dataset setting. 
                        Current file was previously uploaded with different membrane/method/publication setting. 
                        First, delete old dataset [id: ' . $dataset->id . '] and try again.');
                }

                $this->alert->warning('Found dataset for given file from a previous upload [ID: ' . $dataset->id . '].');  
            }
            else
            {
                $dataset->id_membrane = $membrane->id;
                $dataset->id_method = $method->id;
                $dataset->id_publication = $publication->id;
                $dataset->id_user_edit = session::user_id();
                $dataset->id_user_upload = session::user_id();
                $dataset->visibility = Datasets::INVISIBLE;
                $dataset->name = $method->CAM . '_' . $membrane->CAM;

                $dataset->save();

                $file_object->id_dataset_passive = $dataset->id;
                $file_object->save();
            }

            // Create new queue 
            $queue->id_dataset_passive = $dataset->id;
            $queue->state = $queue::STATE_PENDING;
            $queue->id_user = session::user_id();
            $queue->id_file = $file_object->id;
            $queue->settings = json_encode(array
            (
                'delimiter' => $delimiter,
                'attributes' => $attrs,
                'temperature' => $temperature
            ));

            $queue->save();

            $this->alert->success('Dataset upload was added to the queue [ID: ' . $queue->id . '].');

            Db::commitTransaction();
            return NULL;
        }
        catch (MmdbException $ex)
        {
            Db::rollbackTransaction();
            $this->alert->error('Error occured while uploading dataset.');
            throw $ex;
        }
    }

    /**
     * Automatically fill missing 3D structures using RDKIT
     */
    public function fill3dStructures()
    {
        $this->alert->warning('Sorry, service is temporarily unavailable.');
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
        );

        $texts = array
        (
            self::M_DATASET => "Dataset",
            self::M_ENERGY => "Energy file",
            self::M_MEMBRANE => "Membrane",
            self::M_METHOD => "Method",
            self::M_PICTURE => "Picture",
            self::M_PUBLICATION => "Publication",
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
     * @return Files File path
     */
    public function uploadFile($file, $file_type = NULL)
    {
        $target_dir = Files::get_type_path($file_type);
        $newFile = new File($file);
        $f_model = new Files();

        // Exists?
        $exists = $f_model->get_by_hash($newFile);

        if($exists->id)
        {
            $this->alert->warning('The file has been restored from previous upload. \n [Name: ' . $exists->name . '; Date: ' . $exists->datetime . ']');
            return $exists;
        }

        if(intval($newFile->size) > 50000000) // 50mb max size
        {
            $this->alert->error('Sorry, your file is too large.');
            $this->redirect('upload/dataset');
        }

        if($newFile->extension !== 'csv') // Only CSV files allowed
        {
            $this->alert->warning('Wrong file format. Upload only ".csv" files.');
            $this->redirect('upload/dataset');
        }
        if($newFile->save_to_dir($target_dir)) // Finally, move file to target directory
        {
            $this->alert->success('The file "' . $newFile->name . '" has been uploaded!');
        }
        else
        {
            throw new MmdbException('Cannot move file to the target folder.');
        }

        // Make new DB record and return ID
        $n_file = new Files();

        $n_file->type = $file_type;
        $n_file->name = $newFile->name;
        $n_file->path = $newFile->path;
        $n_file->id_user = session::user_id();
        $n_file->save();

        return $n_file;
   }
}
