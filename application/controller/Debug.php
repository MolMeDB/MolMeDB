<?php


/**
 * Debug controller
 * - available only in debug mode
 */
class DebugController extends Controller
{
    /**
     * Constructor
     */
    public function __construct()
    {  
        if(!DEBUG)
        {
            $this->redirect('error');
        }

        parent::__construct(); 
    }

    /**
     * Default route
     */
    public function parse()
    {
        // $stats_model = new Statistics();
        // $stats_model->update_all();

        die;
    }

    /**
     * 
     */
    public function scheduler($method = 'run')
    {
        $s = new SchedulerController();

        if(method_exists($s, $method))
        {
            $s->$method();
        }

        die;
    }
}