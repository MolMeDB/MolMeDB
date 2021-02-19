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

                $this->data['publictaion'] = $publication;
                $this->data['pagination'] = $pagination;
                $this->data['itemsOnPage'] = isset($_GET['IOP']) ? $_GET['IOP'] : 5;
            }

            $this->data['publications'] = $publicationModel->get_nonempty_refs();
            $this->data['publications'] = self::transform_publications($this->data['publications']);
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


    /**
     * Publication list
     * 
     * @param Iterable_object $list
     * 
     * @return Iterable_object
     */
    private static function transform_publications($list)
    {
        $result = [];

        foreach($list as $row)
        {
            $result[] = array
            (
                'id' => $row->id,
                'citation' => $row->citation,
                'citation_short' => self::get_substring($row->citation, 65),
                'doi' => $row->doi,
                'doi_short' => self::get_substring($row->doi, 15),
                'pmid' => $row->pmid,
                'title' => $row->title,
                'title_short' => self::get_substring($row->title, 65),
                'year' => $row->year,
                'authors' => $row->authors,
                'authors_short' => self::get_substring($row->authors, 35),
            );
        }

        return new Iterable_object($result, TRUE);
    }

    /**
     * 
     */
    private static function get_substring($string, $limit)
    {
        if(strlen($string) <= $limit)
        {
            return $string;
        }

        $end = strrpos(substr($string,0,$limit), " ");

        if(!$end)
        {
            $end = $limit;
        }

        return substr($string, 0, $end) . '...';
    }
}


