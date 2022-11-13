<?php

/**
 * Renders similar entry item
 * 
 * @author Jakub Juracka
 */
class Render_similar_entry_item extends Render
{
    private $stats_interactions;

    private $show_next_total;

    /**
     * Constructor
     * 
     * @var array|Iterable_object $data
     */
    function __construct($data, $stats_interactions, $show_next_total = null)
    {
        $this->data = $data;
        $this->stats_interactions = $stats_interactions;
        $this->show_next_total = $show_next_total;

        if((!$this->data && !$this->stats_interactions && !$this->show_next_total))
        {
            $this->view = new View();
        }
        else
        {
            $this->view = new View('render/similar_entry');   
            $this->prepare(); 
        }
    }

    /**
     * Prepare view data
     */
    function prepare()
    {
        if($this->data && $this->stats_interactions)
        {
            $this->view->substance = $this->data->substance;
            $this->view->similarity = $this->data->similarity;
            $this->view->all_similarities = $this->data->all_similarities;
            $this->view->interactions = $this->data->interactions;
            $this->view->stats_interactions = $this->stats_interactions;
        }
        else
        {
            $this->view->show_next_total = $this->show_next_total;
        }
    }

    /**
     * Prints instance of 
     * 
     */
    static function print($data, $stats_interactions)
    {
        $e = new Render_similar_entry_item($data, $stats_interactions);
        return $e->__toString();
    }

    /**
     * Prints "show next" window
     * 
     * @param int $total
     */
    static function print_next($total, $visible)
    {
        if(!$total)
        {
            return '';
        }

        $diff = $total - $visible;
        $diff = $diff < 0 ? 0 : $diff;

        $e = new Render_similar_entry_item(null, null, $diff);
        return $e->__toString();
    }
}