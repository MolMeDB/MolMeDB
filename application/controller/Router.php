<?php

/**
 * Main entry controller
 */
class RouterController extends Controller
{
    /** @var Controller */
    public $controller;

    public $messages = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * String to camelCaps
     * 
     * @param string $text
     * 
     * @return string
     */
    private function camelCaps($text) 
    {
        $sentence = str_replace('-', ' ', $text);
        $sentence = ucwords($sentence);
        $sentence = str_replace(' ', '', $sentence);
        return $sentence;
    }

    /**
     * Parsing URL by backslashes
     * 
     * @param string $url
     * 
     * @return array
     */
    private function parseURL($url)
    {
        $parsedURL = parse_url($url);
        $parsedURL["path"] = ltrim($parsedURL["path"], "/");
        $parsedURL["path"] = trim($parsedURL["path"]);
        $dividedRoute = explode("/", $parsedURL["path"]);

        return $dividedRoute;
    }

    /**
     * Main function for parsing URL address
     * 
     * @param array $parameters
     */
    public function parse($parameters)
    {
        // If maintenance is in progress, show directly this site
        if(Server::is_maintenance())
        {
            $this->view = new View('maintenance');
            $this->view->render(TRUE);
            return;
        }

        $parsedURL = $this->parseURL($parameters);

        // Check and save token for internal requests
        session::check_api_token();

        // If not set endpoint
        if (!isset($parsedURL[0]) || empty($parsedURL[0]))
        {		
            $this->redirect('detail/intro');
        }

        // Loading target controller
        $classController = $this->camelCaps(array_shift($parsedURL)) . 'Controller';
        $targetFunction = isset($parsedURL[0]) ? strtolower($parsedURL[0]) : NULL;

        // REST API direct redirection
        if($classController == 'ApiController')
        {
            $api_controller = new ApiController($parsedURL);
            $api_controller->index();
            die;
        }

        // Scheduler redirection
        if($classController === 'SchedulerController')
        {
            // Can be access only from local machine
            if (!DEBUG && server::remote_addr() != server::server_addr() &&
                server::remote_addr() != "127.0.0.1")
            {
                echo 'access denied';
                die();
            }

            // if(!in_array($targetFunction, SchedulerController::$accessible))
            // {
            //     echo 'Endpoint is not remotely accessible.';
            //     die;
            // }

            array_shift($parsedURL);
            
            $api_controller = new SchedulerController();
            $api_controller->$targetFunction(...$parsedURL);
            die;
        }

        // If not set target function, set to "parse"
        if (!$targetFunction || !strlen($targetFunction)) 
        {
            $targetFunction = 'parse';
        }

        $file_class = str_replace('Controller', '', $classController);

        if (file_exists(APP_ROOT . 'controller/' . $file_class . '.php')) 
        {
            $this->controller = new $classController();
        } 
        else // Controller doesn't exists 
        {
            $this->redirect('error');
        }

        // Exists target function?
        if (method_exists($this->controller, $targetFunction)) 
        {
            array_shift($parsedURL);
        }
        else
        {
            $targetFunction = 'index';
        }

        if (!method_exists($this->controller, $targetFunction)) 
        {
            $this->alert->error('Endpoint was not found.');
            $this->redirect('error');
        }

        try
        {
            ob_start();
            $this->controller->$targetFunction(...$parsedURL);
        }
        catch(Exception $e)
        {
            ob_clean();

            Server::print_global_exception($e);
        }

        $this->view = new View('layout');
        $this->view->title = $this->controller->title;
        $this->view->breadcrumbs = $this->controller->breadcrumbs;
        $this->view->controller = $this->controller;
        $this->view->messages = $this->alert->get_all();
        $this->view->render(TRUE);
    }

}