<?php

/**
 * Search engine controller
 */
class SearchController extends Controller 
{
    /** SearchEngine types */
    const T_COMPOUND = 'compound';
    const T_MEMBRANE = 'membrane';
    const T_METHOD = 'method';
    const T_SMILES = 'smiles';
    const T_TRANSPORTER = 'transporter';

    
    /** Allowed types */
    private $valid_types = array
    (
        self::T_COMPOUND,
        self::T_MEMBRANE,
        self::T_METHOD,
        self::T_SMILES,
        self::T_TRANSPORTER
    );

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Main search engine parser
     * 
     * @param string $type
     * @param integer $pagination
     * 
     * @author Jakub Juračka
     */
    public function index($type = self::T_COMPOUND, $pagination = 1, $per_page = 10) 
    {
        // Is type valid?
        if(!in_array($type, $this->valid_types))
        {
            $this->addMessageWarning('Invalid parameter');
            $this->redirect('search/' . self::T_COMPOUND);
        }

        if(!$pagination || !is_numeric($pagination))
        {
            $pagination = 1;
        }

        $list = array();
        $info = '';

        $query = isset($_GET['q']) ? $_GET['q'] : NULL;
        $id = isset($_GET['id']) ? $_GET['id'] : NULL;

        try
        {
            $enum_type_model = new Enum_types();

            $membrane_categories = $enum_type_model->get_categories(Enum_types::TYPE_MEMBRANE_CATS);
            $method_categories = $enum_type_model->get_categories(Enum_types::TYPE_METHOD_CATS);
        }
        catch(MmdbException $e)
        {
            $this->alert->warning($e);
        }

        // Set main variables
        $this->title = 'Search';

        $this->view = new View('search');
        $this->view->count = 0;
        $this->view->list = $list;
        $this->view->info = $info;
        $this->view->pagination = $pagination;
        $this->view->searchInput = $query;
        $this->view->searchType = $type;
        $this->view->show_detail = false;

        $this->view->membrane_categories = json_encode($membrane_categories);
        $this->view->method_categories = json_encode($method_categories);
        $this->view->active_tab = $type;

        $this->paginator = new View_paginator();
        $this->paginator->path("$type")
            ->active($pagination)
            ->records_per_page($per_page);
        
        $this->view->paginator($this->paginator);

        // If not sent query, return
        if(!$query && !$id)
        {
            return;
        }

        // Remove whisper if exists
        $end = strpos($query, '[');

        if($end)
        {
            $query = trim(substr($query, 0, $end));
        }

        // Get data
        switch ($type) 
        {
            case self::T_COMPOUND:
                return $this->by_compound($query, $pagination);

            case self::T_MEMBRANE:
                return $this->by_membrane($id, $pagination);

            case self::T_METHOD:
                return $this->by_method($id, $pagination);

            case self::T_SMILES:
                return $this->by_smiles($query, $pagination);

            case self::T_TRANSPORTER:
                return $this->by_transporter($query, $pagination);
        }
        
    }

    /**
     * Search by transporter
     * 
     * @param string $query
     * @param int $pagination
     */
    private function by_transporter($query, $pagination)
    {
        $substance_model = new Substances();

        $list = array();
        $total = 0;

        try 
        {
            $list = $substance_model->search_by_transporter($query, $pagination);
            $total = $substance_model->search_by_transporter_count($query);
        } 
        catch (ErrorUser $ex) 
        {
            $this->addMessageError($ex);
            $this->redirect('search');
        }

        $this->view->pagination_setting = array
        (
            'total_items' => $total,
            'items_per_page' => 10,
            'active_page'   => $pagination,
            'callback' => 'search/transporter/{pagination}?q=' . $query
        );

        $this->view->list = $list;
        $this->view->count = $total;
        $this->view->show_detail = True;
        $info = "Results for name '<b>" . rawurldecode($query) . "</b>' ($total):";
        $this->view->info = $info;
        $this->paginator->total_records($total);
    }

    /**
     * Search by compound type
     * 
     * @param string $query
     * @param integer $pagination
     * 
     */
    private function by_compound($query, $pagination)
    {
        $substance_model = new Substances();

        $list = array();
        $total = 0;

        try 
        {
            $list = $substance_model->search($query, $pagination);
            $total = $substance_model->search_count($query);
        } 
        catch (Exception $ex) 
        {
            $this->alert->error($ex);
            $this->redirect('search');
        }

        $this->view->pagination_setting = array
        (
            'total_items' => $total,
            'items_per_page' => 10,
            'active_page'   => $pagination,
            'callback' => 'search/compound/{pagination}?q=' . $query
        );

        $this->view->list = $list;
        $this->view->count = $total;
        $this->view->show_detail = True;
        $info = "Results for name '<b>" . rawurldecode($query) . "</b>' ($total):";
        $this->view->info = $info;
        $this->paginator->total_records($total);
    }

    /**
     * Search compounds by membrane
     * 
     * @param int $id
     * @param integer $pagination
     * 
     */
    private function by_membrane($id, $pagination)
    {
        $list = array();
        $total = 0;
        
        $id = intval($id);


        $sub_model = new Substances();
        $membrane = new Membranes($id);

        if(!$membrane->id)
        {
            $this->alert->error('Membrane not found.');
            $this->redirect('search');
        }

        try 
        {
            $list = $sub_model->search_by_membrane($id, $pagination);
            $total = $sub_model->search_by_membrane_count($id);
        } 
        catch (ErrorUser $ex) 
        {
            $this->addMessageError($ex);
            $this->redirect('search');
        }

        $this->view->pagination_setting = array
        (
            'total_items' => $total,
            'items_per_page' => 10,
            'active_page'   => $pagination,
            'callback' => 'search/membrane/{pagination}?id=' . $id
        );

        $this->view->list = $list;
        $this->view->count = $total;
        $this->view->show_detail = True;
        $info = "Results for membrane '<b>" . Html::anchor(
            'browse/membranes?target=' . $membrane->id,
            $membrane->name,
            ) . "</b>' ($total):";
        $this->view->info = $info;
        $this->paginator->total_records($total);
    }

    /**
     * Search compounds by smiles
     * 
     * @param string $query
     * @param integer $pagination
     * 
     */
    private function by_smiles($query, $pagination)
    {
        $substance_model = new Substances();

        $list = array();
        $total = 0;

        // Prepare query
        $ind = ' [SMILES: ';
        $start = strpos($query, $ind);

        if($start)
        {
            $start += strlen($ind);
            $len = strlen($query) - $start - 1;
            $query = substr($query, $start, $len);
        }

        try 
        {
            $rdkit = new Rdkit();
            $smiles = $rdkit->canonize_smiles($query);

            $list = $substance_model->search_by_smiles($smiles ? $smiles : $query, $pagination);
            $total = $substance_model->search_by_smiles_count($smiles ? $smiles : $query);
        }
        catch (Exception $ex) 
        {
            $this->alert->error($ex);
            $this->redirect('search');
        }

        $this->view->pagination_setting = array
        (
            'total_items' => $total,
            'items_per_page' => 10,
            'active_page'   => $pagination,
            'callback' => 'search/smiles/{pagination}?q=' . $query
        );

        $this->view->list = $list;
        $this->view->count = $total;
        $this->view->show_detail = True;
        $info = "Results for SMILES '<b>" . rawurldecode($smiles ? $smiles : $query) . "</b>' ($total):";
        $this->view->info = $info;
        $this->view->smiles = $smiles ? $smiles : $query;
        $this->paginator->total_records($total);
    }

    /**
     * Search compounds by method
     * 
     * @param string $id
     * @param integer $pagination
     * 
     */
    private function by_method($id, $pagination)
    {
        $substance_model = new Substances();

        $list = array();
        $total = 0;

        $method = new Methods($id);

        if(!$method->id)
        {
            $this->alert->warning('Method not found.');
            $this->redirect('search');
        }
        
        try 
        {
            $list = $substance_model->search_by_method($id, $pagination);
            $total = $substance_model->search_by_method_count($id);
        } 
        catch (ErrorUser $ex) 
        {
            $this->addMessageError($ex);
            $this->redirect('search');
        }

        $this->view->pagination_setting = array
        (
            'total_items' => $total,
            'items_per_page' => 10,
            'active_page'   => $pagination,
            'callback' => 'search/method/{pagination}?q=' . $id
        );

        $this->view->count = $total;
        $this->view->list = $list;
        $this->view->show_detail = True;
        $info = "Results for method '<b>" . Html::anchor(
            'browse/methods?target=' . $method->id,
            $method->name,
            ) . "</b>' ($total):";
        $this->view->info = $info;
        $this->paginator->total_records($total);
    }

}

