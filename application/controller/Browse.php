<?php

/**
 * Browse controller
 */
class BrowseController extends Controller 
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Compounds browser
     * DEPRECATED
     * 
     * @author Jakub Juračka
     */
    // public function compounds()
    // {
    //     $methodModel = new Methods();
    //     $membraneModel = new Membranes();
    //     $substanceModel = new Substances();

    //     try
    //     {
    //         $methods = $methodModel->get_all();
    //         $membranes = $membraneModel->get_all();

    //         $viewMem = $viewMet = array();

    //         // Apply filter
    //         if ($_POST) 
    //         {
    //             $viewMem = $_POST['membranes'];
    //             $viewMet = $_POST['methods'];

    //             $this->data['compounds'] = $substanceModel->get_by_membrane_method($viewMem, $viewMet);
    //         } 
    //         else
    //         { // if not send, show all
    //             $this->data['compounds'] = $substanceModel
    //                 ->select_list(array
    //                 (
    //                     'id',
    //                     'name',
    //                     'identifier'
    //                 ))
    //                 ->get_all();
    //         }
    //     }
    //     catch (Exception $e)
    //     {
    //         $this->addMessageError($e->getMessage());
    //         $this->redirect('error');
    //     }

    //     $this->data['active_membranes'] = $viewMem;
    //     $this->data['active_methods'] = $viewMet;
    //     $this->data['membranes'] = $membranes;
    //     $this->data['methods'] = $methods;
    //     $this->view = 'browseCompounds';
    //     $this->header['title'] = 'Compounds';
    // }


    /**
     * Membranes browser
     * 
     * @author Jakub Juračka
     */
    public function membranes()
    {
        $membraneModel = new Membranes();

        try
        {
            // Get all membranes
            $membranes = $membraneModel->get_all();
            $active_categories = array();

            // Load membrane categories
            foreach ($membranes as $m) 
            {
                $active_categories[$m->id] = $m->get_category();
            }

            $this->data['categories'] = $membraneModel->get_all_categories();
        }
        catch(Exception $e)
        {
            $this->addMessageError($e->getMessage());
            $this->redirect('error');
        }

        $this->data['membranes'] = $membranes;
        $this->data['active_categories'] = $active_categories;
        $this->header['title'] = 'Membranes';
        $this->view = 'browse/membranes';
    }

    /**
     * Methods browser
     * 
     * @author Jakub Juračka
     */
    public function methods()
    {
        $methodModel = new Methods();

        // Get all methods
        $methods = $methodModel->get_all();

        $this->data['methods'] = $methods;
        $this->header['title'] = 'Methods';
        $this->view = 'browse/methods';
    }

    /**
     * Methods browser
     * 
     * @param integer $reference_id
     * @param integer $pagination
     * 
     * @author Jakub Juračka
     */
    public function sets($reference_id = NULL, $pagination = 1)
    {
        $publicationModel = new Publications();
        $substanceModel = new Substances();

        $itemsOnPage = isset($_GET['IOP']) ? $_GET['IOP'] : 5;

        try
        {
            if ($reference_id) 
            {
                $publication = new Publications($reference_id);

                if(!$publication->id)
                {
                    throw new Exception('Record doesn\'t exist.');
                    $this->redirect('error');
                }

                $this->data['reference_name'] = $publication->citation;
                $this->data['list'] = $substanceModel->get_by_reference_id($reference_id, $pagination);
                $this->data['count'] = $substanceModel->count_by_reference_id($reference_id);
                $this->data['info'] = " <b> Results for: </b>" . $publication->reference;
                $this->data['idRef'] = $reference_id;
                $this->data['pagination'] = $pagination;
                $this->data['itemsOnPage'] = isset($_GET['IOP']) ? $_GET['IOP'] : 5;
            }

            $this->data['publications'] = $publicationModel->get_nonempty_refs();
        }
        catch(Exception $e)
        {
            $this->addMessageError($e->getMessage());
            $this->redirect('error');
        }

        $this->data['itemsOnPage'] = $itemsOnPage;
        $this->data['uri'] = str_replace("?IOP=$itemsOnPage", '', $_SERVER['REQUEST_URI']) . '?IOP=';
        $this->header['title'] = 'Datasets';
        $this->view = 'browse/sets';
    }
}


