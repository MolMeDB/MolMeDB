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


    /**
     * Shows summary stats
    */
    public function show_all()
    {
        $stats = new Stats();

        $stat_interaction_reference = (object)$stats->get_interaction_stats_by_reference(20);

        // $this->data['interactions'] = (object)array
        // (
        //     // 'adding' => (object)$stats->get_interaction_adding_stats(),
        //     // 'adding' => (object)$stats->get_interaction_substances_adding_stats(),
        //     'adding' => (object)$stats->get_all_interactions_adding_stats(),
        //     'primary_reference' => (object)array
        //     (
        //         'data' => $stat_interaction_reference->data['primary_reference'],
        //         'axisBreaks' => $stat_interaction_reference->axisBreaks['primary_reference']
        //     ),
        //     'secondary_reference' => (object)array
        //     (
        //         'data' => $stat_interaction_reference->data['secondary_reference'],
        //         'axisBreaks' => $stat_interaction_reference->axisBreaks['secondary_reference']
        //     ),
        //     "passive" => (object)$stats->get_passive_interactions_adding_stats()
        // );

        // $this->data['substances'] = (object)array
        // (
        //     'adding' => (object)$stats->get_substances_adding_stats()
        // );

        $this->data['membranes'] = (object)$stats->get_membranes_stats(TRUE);

        // $this->data['methods'] = (object)$stats->get_methods_stats();

        // $this->data['identifiers'] = (object)$stats->get_identifiers_stats();

        // $this->data['interactions_pa'] = (object)$stats->get_passive_active_int_substance_stats();

        // print_r($this->data['interactions_pa']);
        // die;

        // $this->data['methods'] = (object)array
        // (
        //     // 'adding' => (object)$stats->get_methods_adding_stats()
        // );
       
        $this->data["detail"] = "";
        $this->header['title'] = 'Statistics';
        $this->view = 'stats';
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

        try
        {
            $list = $membraneModel->get_interactions_count();

            $this->data["total_membranes"] = $membraneModel->get_all_count();
            $this->data["total_methods"] = $methodModel->get_all_count();
            $this->data["total_substances"] = $substanceModel->get_all_count();
            $this->data["total_interactions"] = $interactionModel->get_all_count();
        }
        catch(Exception $ex)
        {
            $this->addMessageError('Error ocurred during getting stats detail.');
            $this->redirect('error');
        }

        $this->data['active_membrane'] = True;
        $this->data['detail'] = $this->make_list($list);
        $this->header['title'] = 'Statistics';
        $this->view = 'stats';
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

        try
        {
            $list = $methodModel->get_interactions_count();

            $this->data["total_membranes"] = $membraneModel->get_all_count();
            $this->data["total_methods"] = $methodModel->get_all_count();
            $this->data["total_substances"] = $substanceModel->get_all_count();
            $this->data["total_interactions"] = $interactionModel->get_all_count();
        }
        catch(Exception $ex)
        {
            $this->addMessageError('Error ocurred during getting stats detail.');
            $this->redirect('error');
        }

        $this->data['active_method'] = True;
        $this->data['detail'] = $this->make_list($list, self::T_METHOD);
        $this->header['title'] = 'Statistics';
        $this->view = 'stats';
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