<?php

/**
 * Downloader
 * 
 * @author Jakub Juračka
 */
class DownloadController extends Controller 
{
    /**
     * Constructor 
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Paths
     */
    const PATH_ACTIVE = "active";
    const PATH_PASSIVE = "passive";

    static $valid_paths = array
    (
        self::PATH_ACTIVE,
        self::PATH_PASSIVE,
    );

    /**
     * Download conformers of molecule
     * 
     * @param int $id_fragment
     * @param int $id_ion
     * 
     * @author Jakub Juracka
     */
    public function conformers($id_fragment, $id_ion)
    {
        $fragment = new Fragments($id_fragment);
        $ion = new Fragment_ionized($id_ion);
        $redirect = isset($_GET['redir']) ? $_GET['redir'] : 'validator/cosmo';

        if(!$fragment->id || !$ion->id || $fragment->id != $ion->id_fragment)
        {
            $this->alert->warning('Invalid fragment/ion parameter.');
            $this->redirect($redirect);
        }

        $f = new File();

        $path = $f->prepare_conformer_folder($fragment->id, $ion->id);

        if(!file_exists($path))
        {
            $this->alert->warning('No files found.');
            $this->redirect($redirect);
        }

        $files = scandir($path);
        $files = array_filter($files, function($a){return preg_match('/\.(sdf)$/', $a);});
        
        foreach(array_keys($files) as $k)
        {
            $files[$k] = $path . $files[$k];
        }

        $zip = $f->zip($files, $path . 'conformers.zip');

        if(!$zip)
        {
            $this->alert->warning('Cannot make the zip file.');
            $this->redirect($redirect); 
        }

        $filename = $fragment->id . '_' . $ion->id . '_ion';

        header('Content-Tpe: application/zip');
        // tell the browser we want to save it instead of displaying it
        header('Content-Disposition: attachment; filename="'.$filename.'.zip";');
        header("Content-length: " . filesize($zip));
        header("Pragma: no-cache"); 
        header("Expires: 0"); 
        readfile("$zip");
    }   

    /**
     * Download cosmo files of molecule
     * 
     * @param int $id_fragment
     * @param int $id_ion
     * 
     * @author Jakub Juracka
     */
    public function cosmo_results($id_fragment, $id_ion)
    {
        $fragment = new Fragments($id_fragment);
        $ion = new Fragment_ionized($id_ion);
        $runs = Run_cosmo::instance()->where('id_fragment', $fragment->id)->get_all();
        $redirect = isset($_GET['redir']) ? $_GET['redir'] : 'validator/cosmo';

        if(!$fragment->id || !$ion->id || $fragment->id != $ion->id_fragment || !count($runs))
        {
            $this->alert->warning('Invalid fragment/ion parameter.');
            $this->redirect($redirect);
        }

        $f = new File();

        $path = $f->prepare_conformer_folder($fragment->id, $ion->id);
        $path = $path . 'COSMO/';

        if(!file_exists($path))
        {
            $this->alert->warning('No files found.');
            $this->redirect($redirect);
        }

        $parental_dir = sys_get_temp_dir() . "/Fragment_" . $fragment->id;
        $zip_paths = [];

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

        foreach($runs as $run)
        {
            $p_files = scandir($path);
            $prefix = strtolower($run->get_result_folder_name());
            $prefix = trim($prefix);
            $run_folder = $parental_dir .'/' . $prefix;

            if(file_exists($run_folder)){
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
                rmdir($run_folder);
            }
            if(!mkdir($run_folder))
            {
                throw new Exception('Cannot create folder in tmp folder.');
            }

            $zip_paths[] = $run_folder;

            foreach($p_files as $pt)
            {
                $pt = preg_replace('/^\.+/', '', $pt);

                if($prefix == strtolower(substr($pt, 0, strlen($prefix))))
                {
                    $files = scandir($path . $pt);
                    $files = array_filter($files, function($a){return !preg_match('/^\./', $a);});
                    
                    foreach(array_keys($files) as $k)
                    {
                        $pth = $path . $pt . "/" . $files[$k];

                        // Pretiffy JSON files
                        if(preg_match('/\.json$/', $files[$k]))
                        {
                            $content = json_decode(file_get_contents($pth));
                            file_put_contents($run_folder.'/'.$files[$k], json_encode($content, JSON_PRETTY_PRINT));
                        }
                        else
                            copy($pth, $run_folder . '/' . $files[$k]);
                    }
                }
            }
        }

        if(count($zip_paths))
        {
            // Add all to the zip file
            $zip = new ZipArchive();
            $zip_path = sys_get_temp_dir() . '/Fragment' . $fragment->id .'.zip';
            
            if($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE)
            {
                die("Cannot create ZIP file.");
            }

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
                    $relativePath = substr($filePath, strlen($parental_dir)+1);
                    
                    // Add current file to archive
                    if(!$zip->addFile($filePath, $relativePath))
                    {
                        die("Cannot add file to ZIP.");
                    }
                }
            }

            // Zip archive will be created only after closing object
            if(!$zip->close())
            {
                die("Cannot properly close ZIP archive.");
            }

            $filename = $fragment->id . '_' . $ion->id . '_cosmo';

            header('Content-Tpe: application/zip');
            header('Content-Disposition: attachment; filename="'.$filename.'.zip";');
            header("Content-length: " . filesize($zip_path));
            header("Pragma: no-cache"); 
            header("Expires: 0"); 
            ob_clean();
            flush();
            readfile("$zip_path");
            unlink($zip_path);
            exit;
        }

        $this->alert->warning('No files found.');
        $this->redirect($redirect);
    }   


    /**
     * Main function
     */
    public function index($path = self::PATH_PASSIVE)
    {
        if(!in_array($path, self::$valid_paths))
        {
            $this->redirect('download/' . self::PATH_PASSIVE);
        }

        $membrane_selector = new Render_multiple_selector("membranes", array
        (
            "title" => "Membranes",
            "item_sublabel" => 'total',
            "item_value" => "id",
            "item_label" => "name",
            "item_category" => "category",
        ));

        $method_selector = new Render_multiple_selector("methods", array
        (
            "title" => "Methods",
            "item_sublabel" => 'total',
            "item_value" => "id",
            "item_label" => "name",
            "item_category" => "category"
        ));

        // $transporter_selector = new Render_multiple_selector('transporters', array
        // (
        //     'title' => 'Transporters',
        //     "item_sublabel" => 'total',
        //     "item_value" => "id",
        //     "item_label" => "name",
        //     "item_category" => "category"
        // ));

        // Get data
        try
        {
            $mem_model = new Membranes();
            $met_model = new Methods();
            $t_model = new Transporters();

            if($path == self::PATH_PASSIVE)
            {
                $membranes = $mem_model->get_all_selector_data();
                $methods = $met_model->get_all_selector_data();
                $membrane_selector->dataset($membranes);
                $method_selector->dataset($methods);
            }
            else
            {
                $et_model = new Enum_types();
                $transporter_data = $et_model->get_categories($et_model::TYPE_TRANSPORTER_CATS);
            }
        }
        catch(MmdbException $e)
        {

        }

        $this->view = new View('downloader/index');
        $this->view->membrane_selector = $membrane_selector;
        $this->view->method_selector = $method_selector;
        $this->view->page_type = $path;
        $this->view->chart_data = isset($transporter_data) ? $transporter_data : null;

        $this->title = 'Downloader';
        $this->breadcrumbs = Breadcrumbs::instance()->add('Downloader')->add($path == self::PATH_ACTIVE ? 'Active interactions' : 'Passive interactions');
    }
}