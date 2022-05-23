<?php

/** DEFINE GLOBAL COMMON MESSAGES */
define('ACCESS', 'Access denied.');
define("DB_ERROR", 'Problem with saving data to DB. Try it again later.');
define("DATA_PROCESS_ERROR", 'Data processing error.');
define("ERROR", 'Something went wrong. Please, try it again.');
define("FILTER_SUCCESS", 'Filters were updated.');
define("FILTER_ERROR", 'Filter wasn\'t updated. Please, try again.');
define('PARAMETER', 'Invalid parameter.');
define("URL_ERROR", 'Invalid URL.');

/**
 * Alert class for handling alert messages
 * 
 * @method void success($message)
 * @method void warning($message)
 * @method void error($message)
 * @method string get_all()
 * 
 * @author Jakub Juračka
 */
class Alert 
{
    /**
     * Constructor
     */
    function __construct()
    {
        
    }

    /**
     * Adds SUCCESS message
     * 
     * @param string $messsage
     */
    public function success($message)
    {
        if ($message instanceof Exception) 
        {
            $message = $message->getMessage();
        }

        if(isset($_SESSION['success_messages']))
        {
            $_SESSION['success_messages'][] = $message;
        }
        else
        {
            $_SESSION['success_messages'] = array($message);
        }
    }

    /**
     * Adds WARNING message
     * 
     * @param string $messsage
     */
    public function warning($message)
    {
        if($message instanceof Exception)
        {
            $message = $message->getMessage();
        }

        if(isset($_SESSION['warning_messages']))
        {
            $_SESSION['warning_messages'][] = $message;
        }
        else
        {
            $_SESSION['warning_messages'] = array($message);
        }
    }

    /**
     * Adds ERROR message
     * 
     * @param string|MMmdbException|Exception $message
     */
    public function error($message)
    {
        if($message instanceof MmdbException)
        {
            $message = $message->getPrintable();
        }
        elseif($message instanceof Exception)
        {
            $message = $message->getMessage();
        }

        if(isset($_SESSION['error_messages']))
        {
            $_SESSION['error_messages'][] = $message;
        }
        else
        {
            $_SESSION['error_messages'] = array($message);
        }
    }

    /**
     * Returns messages returned by any controller
     * 
     * @return string
     */
    public function get_all()
    {
        $result = '';
        $close = '<a href="#" class="close" data-dismiss="alert" aria-label="close">×</a>';
        
        if(isset($_SESSION['success_messages']))
        {
            $messages = $_SESSION['success_messages'];
            unset($_SESSION['success_messages']);
            
            foreach($messages as $m)
            {
                $result .= '<div class="alert alert-success dismissable fade in">'
                . $close
                . $m
                . '</div>';
            }
        }
        
        if(isset($_SESSION['error_messages']))
        {
            $messages = $_SESSION['error_messages'];
            unset($_SESSION['error_messages']);
            
            foreach($messages as $m)
            {
                $result .= '<div class="alert alert-danger dismissable fade in">'
                . $close
                . $m
                . '</div>';
            }
        }
        
        if(isset($_SESSION['warning_messages']))
        {
            $messages = $_SESSION['warning_messages'];
            unset($_SESSION['warning_messages']);
            
            foreach($messages as $m)
            {
                $result .= '<div class="alert alert-warning dismissable fade in">'
                . $close
                . $m
                . '</div>';
            }
        }

        return $result;
    }
}