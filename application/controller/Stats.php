<?php

/**
 * Stats controller
 */
 class StatsController extends Controller
 {
    /** STATS DETAIL TYPES */
    const T_MEMBRANE = 1;
    const T_METHOD = 2;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /** DEFAULT ROUTE */
    public function index()
    {
        $this->show_all();
    }

    /**
     * Shows summary stats
    */
    public function show_all()
    {
        $stats = new Statistics();

        $this->title = 'Statistics';

        $this->view = new View('stats');
        $this->view->last_update = date('d-m-Y', strtotime($stats->get_last_update_date()));

        $this->view->interactions = (object)array
        (
            'adding' => (object)$stats->get(Statistics::TYPE_INTER_ADD),
            'total' => $stats->get(Statistics::TYPE_INTER_TOTAL),
            'active' => $stats->get(Statistics::TYPE_INTER_ACTIVE)
        );

        $this->view->substances = (object)array
        (
            'total' => $stats->get(Statistics::TYPE_SUBST_TOTAL)
        );

        $this->view->membranes = (object)array
        (
            'stats' => (object)$stats->get(Statistics::TYPE_MEMBRANES_DATA),
            'total' => $stats->get(Statistics::TYPE_MEMBRANES_TOTAL)
        );

        $this->view->methods = (object)array
        (
            'stats' => (object)$stats->get(Statistics::TYPE_METHODS_DATA),
            'total' => $stats->get(Statistics::TYPE_METHODS_TOTAL)
        );

        $this->view->identifiers = (object)$stats->get(Statistics::TYPE_IDENTIFIERS);
        $this->view->interactions_pa = (object)$stats->get(Statistics::TYPE_INTER_PASSIVE_ACITVE);
        $this->view->update_available = session::is_admin();
        $this->view->detail = "";
    }

    /**
     * Updates all stats
     */
    public function update_all()
    {
        $stats_model = new Statistics();

        try
        {
            $stats_model->beginTransaction();
            $stats_model->update_all();
            $stats_model->commitTransaction();

            $this->addMessageSuccess('Stats were updated.');
        }
        catch(Exception $e)
        {
            $this->addMessageError($e->getMessage());
        }

        $this->redirect('stats');
    }

    /**
     * Interactions stats
    *
    */
    public function membranes()
    {
        $substanceModel = new Substances();
        $interactionModel = new Interactions();
        $membraneModel = new Membranes();
        $methodModel = new Methods();

        $this->title = 'Statistics';
        $this->view = new View('stats');

        try
        {
            $list = $membraneModel->get_interactions_count();

            $this->view->total_membranes = $membraneModel->get_all_count();
            $this->view->total_methods = $methodModel->get_all_count();
            $this->view->total_substances = $substanceModel->get_all_count();
            $this->view->total_interactions = $interactionModel->get_all_count();
        }
        catch(MmdbException $ex)
        {
            $this->addMessageError('Error ocurred during getting stats detail.');
            $this->redirect('error');
        }

        $this->view->active_membrane = True;
        $this->view->detail = $this->make_list($list);
        
    }

    /**
     * Interactions stats
    *
    */
    public function methods()
    {
        $substanceModel = new Substances();
        $interactionModel = new Interactions();
        $membraneModel = new Membranes();
        $methodModel = new Methods();

        $this->title = 'Statistics';
        $this->view = new View('stats');

        try
        {
            $list = $methodModel->get_interactions_count();

            $this->view->total_membranes = $membraneModel->get_all_count();
            $this->view->total_methods = $methodModel->get_all_count();
            $this->view->total_substances = $substanceModel->get_all_count();
            $this->view->total_interactions = $interactionModel->get_all_count();
        }
        catch(MmdbException $ex)
        {
            $this->addMessageError('Error ocurred during getting stats detail.');
            $this->redirect('error');
        }

        $this->view->active_method = True;
        $this->view->detail = $this->make_list($list, self::T_METHOD);
    }

    /**
     * Makes membranes/methods list
    * 
    * @param Iterable $data
    *
    * @return string HTML
    */
    private function make_list($data, $type = self::T_MEMBRANE)
    {
        if($type === self::T_MEMBRANE)
        {
            $title = 'MEMBRANES';
            $class = 'membrane';
            $second_title = 'METHODS';
        }
        else
        {
            $title = 'METHODS';
            $class = 'method';
            $second_title = 'MEMBRANES';
        }

        // First column
        $html = '<div class="stats-list">
                <div class="stats-title">' . $title . '</div>';

        foreach($data as $row)
        {
            $html .= '<div class="stats-list-item ' . $class . '" ';
            $html .= 'data-spec=\'{"id": "' . $row->id . '", "type": "' . $class . '"}\'>';
            $html .= '<div>' . $row->name . '</div>';
            $html .= '<div>' . $row->count . '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';

        // Second column
        $html .= '<div class="stats-sublist" id="stats-sublist">';
        $html .= '<div class="stats-title">' . $second_title . '</div>';
        $html .= '</div>';

        // Detail
        $html .= '<div class="stats-list-detail" id="stats-list-detail"></div>';

        return $html;

    }
 } 