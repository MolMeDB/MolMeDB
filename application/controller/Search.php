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
    public function parse($type = self::T_COMPOUND, $pagination = 1) 
    {
        // Is type valid?
        if(!in_array($type, $this->valid_types))
        {
            $this->addMessageWarning('Invalid parameter');
            $this->redirect('search/' . self::T_COMPOUND);
        }

        $list = array();
        $info = '';

        $query = isset($_GET['q']) ? $_GET['q'] : NULL;

        // Set main variables
        $this->view = 'search';
        $this->header['title'] = 'Search';
        $this->data['count'] = 0;
        $this->data['list'] = $list;
        $this->data['info'] = $info;
        $this->data['pagination'] = $pagination;
        $this->data['searchInput'] = $query;
        $this->data['searchType'] = $type;
        $this->data['show_detail'] = false;

        // If not sent query, return
        if(!$query)
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
                return $this->by_membrane($query, $pagination);

            case self::T_METHOD:
                return $this->by_method($query, $pagination);

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

        $this->data['list'] = $list;
        $this->data['count'] = $total;
        $this->data['show_detail'] = True;
        $info = "Results for name '<b>" . rawurldecode($query) . "</b>' ($total):";
        $this->data['info'] = $info;
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
        catch (ErrorUser $ex) 
        {
            $this->addMessageError($ex);
            $this->redirect('search');
        }

        $this->data['list'] = $list;
        $this->data['count'] = $total;
        $this->data['show_detail'] = True;
        $info = "Results for name '<b>" . rawurldecode($query) . "</b>' ($total):";
        $this->data['info'] = $info;
    }

    /**
     * Search compounds by membrane
     * 
     * @param string $query
     * @param integer $pagination
     * 
     */
    private function by_membrane($query, $pagination)
    {
        $substance_model = new Substances();

        $list = array();
        $total = 0;
        
        try 
        {
            $list = $substance_model->search_by_membrane($query, $pagination);
            $total = $substance_model->search_by_membrane_count($query);
        } 
        catch (ErrorUser $ex) 
        {
            $this->addMessageError($ex);
            $this->redirect('search');
        }

        $this->data['list'] = $list;
        $this->data['count'] = $total;
        $this->data['show_detail'] = True;
        $info = "Results for name '<b>" . rawurldecode($query) . "</b>' ($total):";
        $this->data['info'] = $info;
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
            $list = $substance_model->search_by_smiles($query, $pagination);
            $total = $substance_model->search_by_smiles_count($query);
        } 
        catch (ErrorUser $ex) 
        {
            $this->addMessageError($ex);
            $this->redirect('search');
        }

        $this->data['list'] = $list;
        $this->data['count'] = $total;
        $this->data['show_detail'] = True;
        $info = "Results for SMILES '<b>" . rawurldecode($query) . "</b>' ($total):";
        $this->data['info'] = $info;
    }

    /**
     * Search compounds by method
     * 
     * @param string $query
     * @param integer $pagination
     * 
     */
    private function by_method($query, $pagination)
    {
        $substance_model = new Substances();

        $list = array();
        $total = 0;
        
        try 
        {
            $list = $substance_model->search_by_method($query, $pagination);
            $total = $substance_model->search_by_method_count($query);
        } 
        catch (ErrorUser $ex) 
        {
            $this->addMessageError($ex);
            $this->redirect('search');
        }

        $this->data['count'] = $total;
        $this->data['list'] = $list;
        $this->data['show_detail'] = True;
        $info = "Results for method '<b>" . rawurldecode($query) . "</b>' ($total):";
        $this->data['info'] = $info;
    }

}

