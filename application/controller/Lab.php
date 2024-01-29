<?php

use Symfony\Polyfill\Intl\Idn\Resources\unidata\Regex;

/**
 * Public controller for special function - only for authenticated users
 * 
 * @author Jakub Juracka
 */
class LabController extends Controller
{
    /**
     * Constructoor
     */
    public function __construct()
    {
        parent::__construct();

        // Access controll
        // todo:
    }

    /**
     * Default page - list of available services
     * 
     */
    public function index()
    {
        $this->breadcrumbs = new Breadcrumbs();
        $this->breadcrumbs
            ->add('Lab');

        $this->title = 'Lab';
        $this->view = new View('lab/index');
    }

    /**
     * Checks, if user has valid account for using
     * 
     * @return bool
     */
    private function is_valid_user()
    {
        $usr = $this->session->user;

        if(!$usr->id)
        {
            $this->alert->warning('Please log in.');
            $this->redirect('login');
        }

        if(!Regexp::is_valid_email($usr->email))
        {
            // Redirect to fill an email
            $this->alert->warning('Your account does not have an email assigned to it. For the proper functioning of the services, you need to add an email and perform validation.');
            $this->redirect('lab/registration/'.$usr->id);
        }

        $ver_model = new User_verification();

        // Is account verified?
        if(!$ver_model->is_verified($usr->id))
        {
            $this->alert->warning('Your e-mail address is not verified. Please, check the registration email.');
            $this->redirect('lab');
        }

        return true;
    }

    /**
     * Here can users request their own Cosmo computations
     * 
     * @author Jakub Juracka
     */
    public function new_dataset()
    {
        // Access controll - redirects if error
        $this->is_valid_user();

        // Add new data
        if($this->form->is_post())
        {
            try
            {
                Db::beginTransaction();
                $membrane = new Membranes($this->form->param->membrane);
                $method = $this->form->param->method;
                $temperature = $this->form->param->temperature;
                $comment = $this->form->param->comment;

                // Get number of unfinised datasets
                $ds_model = new Run_cosmo_datasets();
                
                if($ds_model->get_total_running(session::user_id()) > 4)
                {
                    throw new MmdbException('Dataset limit reached.', "You have reached the limit of the number of unfinished datasets in the queue. Please wait for one of your datasets to complete.");
                }

                if(!Run_cosmo::is_valid_method($method))
                {
                    throw new MmdbException('Invalid method.', "Invalid method.");
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
                    if(strlen($line) < 2)
                    {
                        continue;
                    }
                    $line_count++;

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
                        throw new MmdbException('Cannot canonize smiles `' . $line . '`. Please, check the input.', 
                            'Cannot canonize smiles `' . $line . '` on line ' . $line_count . '. Please, check the input and try again.');
                    }

                    // Check if structure is neutral
                    if(!$f->is_neutral($can))
                    {
                        throw new MmdbException('Structure ' . $can . ' doesnt look to be neutral.', 
                            'Structure ' . $can . ' on line ' . $line_count .  ' doesnt look to be neutral. Please, remove the charge.');
                    }

                    $canonized[] = $can;
                }

                if($line_count > 200)
                {
                    throw new MmdbException('Too big dataset.', 'The data you provided has more than 200 lines. Please, trim the dataset and upload it again.');
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

                $computed = 0;

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
                        // Just link to the new dataset
                        $cosmo_dataset->link($exists);

                        // Is done?
                        if($exists->status > Run_cosmo::STATE_RESULT_DOWNLOADED)
                        {
                            $computed++;
                        }

                        continue;
                    }

                    $q = new Run_cosmo();

                    $q->id_fragment = $f->id;
                    $q->state = Run_cosmo::STATE_PENDING;
                    $q->status = Run_cosmo::STATUS_OK;
                    $q->temperature = $temperature;
                    $q->id_membrane = $membrane->id;
                    $q->method = $method;
                    $q->priority = Run_cosmo::PRIORITY_HIGH;

                    $q->save();

                    // And link
                    $cosmo_dataset->link($q);
                }

                Db::commitTransaction();
                $this->alert->success('The new dataset has been successfully added to the queue.');
                if($computed == count($fragments))
                {
                    $this->alert->success('All interactions are already calculated in the database and ready for download.');
                }
                else if($computed)
                {
                    $this->alert->warning('Total [' . $computed . '] interactions is ready for download.');
                }

                // Send information email
                $cosmo_dataset->notify_progress($cosmo_dataset::PROGRESS_NEW);

                $this->redirect('lab/datasets');
            }
            catch(MmdbException $e)
            {
                Db::rollbackTransaction();
                $this->alert->error($e);
            }
        }

        $this->breadcrumbs = new Breadcrumbs();
        $this->breadcrumbs
            ->add('Lab', "lab", true)
            ->add("Add new dataset");

        $this->title = 'Lab';
        $this->view = new View('lab/add_dataset');
        $this->view->membranes = Membranes::instance()->where('id_cosmo_file IS NOT', "NULL")->get_all();
        $this->view->methods = Run_cosmo::get_all_methods();
    }

    /**
     * Shows the datasets submited by user
     * 
     * @author Jakub Juracka
     */
    public function datasets($pagination = 1, $per_page = 20)
    {
        if(strlen($pagination) == 32)
        {
            $token = $pagination;
            $pagination = 1;
        }

        $ds_token = Run_cosmo_datasets::get_by_token(isset($token) ? $token : '1111');

        // Access controll - redirects if error
        if(!$ds_token->id)
        {
            $this->is_valid_user();
        }

        // Load user's datasets
        $dataset_model = new Run_cosmo_datasets();

        if(!$ds_token->id)
        {
            $total = $dataset_model->get_list_count(null, session::user_id());

            if($total < $pagination * $per_page)
            {
                $pagination = intval($total / $per_page) + 1;
            }
        }
        else
        {
            $total = 1;
        }

        $records = $dataset_model->get_list($pagination, $per_page, null, session::user_id(), $ds_token->token);

        $this->breadcrumbs = new Breadcrumbs();
        $this->breadcrumbs
            ->add('Lab', "lab", true)
            ->add("My datasets", "lab/datasets", $ds_token->id !== null);

        if($ds_token->id)
        {
            $this->breadcrumbs->add('Dataset ' . $ds_token->id, false);
        }

        $this->title = 'Lab';
        $this->view = new View('lab/datasets');
        $this->view->datasets = $records;
    }

    /**
     * Edit comment of cosmo dataset
     * 
     * @param int $pagination
     * 
     */
    public function edit_cosmo_dataset($id_dataset)
    {
        // Access controll - redirects if error
        $this->is_valid_user();

        $dataset = new Run_cosmo_datasets($id_dataset);

        if(!$dataset->id)
        {
            $this->alert->error('Dataset doesnt exist.');
            $this->redirect('lab/datasets');
        }

        // Check, if user is owner
        if($dataset->id_user !== session::user_id())
        {
            $this->alert->error('Access denied.');
            $this->redirect('lab/datasets');
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
                $this->alert->success('Dataset edited.');
                $this->redirect('lab/datasets');
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
            ->add('Lab', "lab", TRUE)
            ->add('My datasets', "lab/datasets", true)
            ->add('Edit dataset [ID: ' . $dataset->id . ']');

        $this->title = 'Edit dataset';
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
        // Access controll - redirects if error
        $this->is_valid_user();

        $dataset = new Run_cosmo_datasets($dataset_id);

        if(!$dataset->id)
        {
            $this->alert->warning(PARAMETER);
            $this->redirect('lab/datasets');
        }

        // Check, if user is owner
        if($dataset->id_user !== session::user_id())
        {
            $this->alert->error('Access denied.');
            $this->redirect('lab/datasets');
        }

        try
        {
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
                throw new MmdbException('Cannot create folder in tmp folder.', 'Server error. Please, try again.');
            }

            // Add info file
            file_put_contents($parental_dir."/Dataset_info.txt", json_encode(array
            (
                'ID'        => $dataset->id,
                'comment'   => $dataset->comment,
                'uploaded'  => $dataset->create_date,
                'uploader'  => $dataset->user->name
            )));

            // Go through all runs
            foreach($dataset->cosmo_runs as $run)
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
                        throw new MmdbException('Cannot create folder in tmp folder.', 'Server error. Please, try again.');
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

                    $computed = true;

                    if(empty($folder_info['xml']))
                    {
                        $computed = false;
                    }

                    // Get charge
                    $charge = $ion->fragment->get_charge($ion->smiles);
                    $target_folder = $dir_path.'/Q_'.$charge.'/ION_'.$ion->id.'/';

                    // Make folder for results
                    mkdir($target_folder,0777, true);

                    // Add input SMILES
                    file_put_contents($target_folder . 'info.txt', implode("\n", [
                        "SMILES: $ion->smiles",
                        "Computed: " . ($computed ? 'yes' : 'no'),
                        "Dataset_id: $dataset->id",
                        "Charge (Q): $charge",
                    ]));

                    foreach($folder_info['xml'] as $_file)
                    {
                        copy($path . $_file, $target_folder . $_file);

                        // Hide private info
                        $content = file_get_contents($target_folder . $_file);
                        $content = preg_replace('/(\/storage\/.+\/([^\/]+\.ccf))/', '$2', $content);
                        // And save
                        file_put_contents($target_folder . $_file, $content);
                    }

                    foreach($folder_info['json'] as $_file)
                    {
                        copy($path . $_file, $target_folder . $_file);
                        // Hide private info
                        $content = json_decode(file_get_contents($target_folder . $_file));
                        foreach($content as $record)
                        {
                            foreach($record->solutes as $sol)
                            {
                                $sol->name = preg_replace('/^.*\//', '', $sol->name);
                            }
                        }
                        // And save again
                        file_put_contents($target_folder . $_file, json_encode($content));
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

        $this->title = 'Download COSMO dataset';
        $this->breadcrumbs = Breadcrumbs::instance()
            ->add('Lab', "lab", TRUE)
            ->add('My datasets', "lab/datasets", true)
            ->add('Download dataset');

        $this->view = new View('lab/download_dataset');
        $this->view->dataset = $dataset;
    }

    /**
     * Verifies new users
     * 
     * @param string $token
     */
    public function verify($token)
    {
        // Check, if token exists
        $verification = User_verification::get_by_token($token);

        if(!$verification->id)
        {
            echo 'Invalid token.';
            die;
        }

        // Check if token is expired
        if($verification->has_expired())
        {
            $this->alert->error('Sorry, token has already expired. Try to login and generate new token.');
        }
        // Set as validated and redirect
        else
        {
            $verification->approved = 1;
            $verification->save();

            $this->alert->success('Your email has been successfully validated. You can now log in with the information sent in the email.');
        }
        
        $this->redirect('login', false);
    }

    /**
     * Register new applicants
     * 
     * @author Jakub Juracka
     */
    public function registration($uid = null)
    {
        
        // If logged in, just redirect
        $user = new Users($uid);
        
        if(!$uid && $this->session->user->id)
        {
            $this->alert->warning("You are already logged in to your account.");
            $this->redirect('lab');
        }

        if($uid && !$user->id)
        {
            $this->alert->warning('Invalid parameter.');
            $this->redirect('lab/registration');
        }

        $forge = new Forge('Registration form');

        $forge->add('molmedb_email')
            ->required()
            ->value($user->email)
            ->title('E-mail');

        $forge->add('affiliation')
            ->required()
            ->value($user->affiliation)
            ->title('Affiliation');

        $a = random_int(1,10);
        $b = random_int(1,10);

        $forge->add('antispam')
            ->type('number')
            ->required()
            ->title($a . '+' . $b . "=");

        if(!$user->id)
            $forge->submit('Create account');
        else
            $forge->submit('Validate account');

        if($this->form->is_post())
        {
            $email = $this->form->param->molmedb_email;
            $aff = $this->form->param->affiliation;
            $antispam = $this->form->param->antispam;

            if($antispam != $_SESSION['antispam'])
            {
                $forge->error('antispam', 'Invalid sum.');
                $_SESSION['antispam'] = $a+$b;
            }
            else
            {
                try
                {
                    // Exists?
                    $exists = $user->where('email', $email)->get_one();
                    $user->beginTransaction();

                    if($exists->id)
                    {
                        throw new ErrorUser('Account with email `'.$email.'` already exists. Please, log in.');
                    }

                    if(!Regexp::is_valid_email($email))
                    {
                        throw new ErrorUser('Invalid email address.');
                    }
                    
                    if(!$user->id)
                    {
                        // Register
                        $password = Encrypt::generate_password();
                        $login = $email;

                        $user->email = $email;
                        $user->name = $email;
                        $user->affiliation = $aff;
                        $user->password = $user::return_fingerprint($password);
                        $user->guest = 1;
                    }
                    else // Just add email (and affiliation)
                    {
                        $password = '(not changed)';
                        $login = $user->name;

                        $user->email = $email;
                        $user->affiliation = $aff;
                    }

                    $user->save();
                    // Generate validation url
                    $ver_model = new User_verification();
                    $verification = $ver_model->create_verification($user->id);

                    // Find message and fill variables
                    $message = Messages::get_by_type(Messages::TYPE_VALIDATION_WELCOME_MESSAGE);
                    $email_text = $message->email_text;

                    $data = array
                    (
                        'login' => $login,
                        'password' => $password,
                        'validation_url' => Url::verification($verification->token)
                    );

                    foreach($data as $key => $val)
                    {
                        $email_text = str_replace("{{$key}}", $val, $email_text);
                    }

                    // Send email to the user
                    $eq = new Email_queue();
                    $eq->recipient_email = $email;
                    $eq->subject = $message->email_subject;
                    $eq->text = $email_text;
                    $eq->status = Email_queue::SENDING;
                    $eq->save();

                    // Notify admin about new registration
                    $admin_emails = explode(';', $this->config->get(Configs::EMAIL_ADMIN_EMAILS));
                    $admin_message = Messages::get_by_type(Messages::TYPE_WELCOME_MESSAGE_ADMIN_NOTIFY);
                    $admin_text = str_replace("{email}", $email, $admin_message->email_text);
                    $admin_text = str_replace("{aff}", $aff, $admin_text);

                    foreach($admin_emails as $e)
                    {
                        $eq2 = new Email_queue();
                        $eq2->recipient_email = $e;
                        $eq2->subject = $admin_message->email_subject;
                        $eq2->text = $admin_text;
                        $eq2->status = Email_queue::SENDING;
                        $eq2->save();
                    }

                    // Link email to verification
                    $verification->id_email = $eq->id;
                    $verification->last_sent_date = date('Y-m-d H:i:s');
                    $verification->save();

                    $this->alert->success('Your account has been registered. Login details have been sent to your email.');
                    $user->commitTransaction();

                    if(!$this->session->user->id)
                    {
                        $this->redirect('login', false);
                    }
                    else
                    {
                        $this->redirect('administration/logout');
                    }
                } 
                catch (ErrorUser $ex) 
                {
                    $user->rollbackTransaction();
                    $this->alert->error($ex->getMessage());
                }
                catch (MmdbException $e)
                {
                    $user->rollbackTransaction();
                    $this->alert->error($e);
                }
            }
        }
        else
        {
            $_SESSION['antispam'] = $a+$b;
        }

        $this->title = 'Registration';
        $this->view = new View('forge');
        $this->view->forge = $forge;
    }
}