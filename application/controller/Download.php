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
        $run = Run_cosmo::instance()->where('id_fragment', $fragment->id)->get_one();
        $redirect = isset($_GET['redir']) ? $_GET['redir'] : 'validator/cosmo';

        if(!$fragment->id || !$ion->id || $fragment->id != $ion->id_fragment || !$run->id)
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

        $files = scandir($path);
        $prefix = strtolower($run->get_script_method() . '_' . str_replace('/','_',str_replace(' ', '-',$run->membrane->name)) . '_' . str_replace('.', ',', $run->temperature));
        $prefix = trim($prefix);
        
        foreach($files as $pt)
        {
            $pt = preg_replace('/^\.+/', '', $pt);

            if($prefix == strtolower(substr($pt, 0, strlen($prefix))))
            {
                $files = scandir($path . $pt);

                $files = array_filter($files, function($a){return !preg_match('/^\./', $a);});

                
                foreach(array_keys($files) as $k)
                {
                    $files[$k] = $path . $pt . "/" . $files[$k];
                }

                $zip = $f->zip($files, $path . 'cosmo.zip');

                if(!$zip)
                {
                    $this->alert->warning('Cannot make the zip file.');
                    $this->redirect($redirect); 
                }

                $filename = $fragment->id . '_' . $ion->id . '_cosmo';

                header('Content-Tpe: application/zip');
                // tell the browser we want to save it instead of displaying it
                header('Content-Disposition: attachment; filename="'.$filename.'.zip";');
                header("Content-length: " . filesize($zip));
                header("Pragma: no-cache"); 
                header("Expires: 0"); 
                readfile("$zip");
                die;
            }
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