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

    /**
     * Main function
     */
    public function index($path = self::PATH_PASSIVE)
    {
        if(!in_array($path, [self::PATH_ACTIVE, self::PATH_PASSIVE]))
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
    }
}