<?php

abstract class Controller
{
    /** Message types */
    CONST MESSAGE_TYPE_SUCCESS = 'success';
    CONST MESSAGE_TYPE_ERROR = 'danger';
    CONST MESSAGE_TYPE_WARNING = 'warning';
    
    /** Shared controller data */
    protected $data = array();
    protected $view = "";
    protected $header = array
    (
        'title'         => '', 
        'description'   => '',
        'token'         => ''
    );

    /** 
     * Class attributes 
     */
    protected $config;

    /**
     * Constructor
     */
    function __construct()
    {
        $this->config = new Config();
    }

    /**
     * Shows current view
     */
    public function showView()
    {
        if($this->view)
        {
            extract($this->protect($this->data));
            extract($this->data, EXTR_PREFIX_ALL, "nonsec"); 
            require(APP_ROOT . "view/" . $this->view . ".phtml");
        }
    }
    
    /**
     * Redirects to given $url
     * 
     * @param string $url
     */
    public function redirect($url)
    {
       header("Location: /$url"); 
       header("Connection: close");
       exit;
    }
    
    /**
     * Adds warning message
     * 
     * @param string $message
     */
    public function addMessageWarning($message)
    {
        if (isset($_SESSION['Warning_messages']))
            $_SESSION['Warning_messages'][] .= $message;
        else
            $_SESSION['Warning_messages'] = array($message);
    }
    
    /**
     * Adds success message
     * 
     * @param string $message
     */
    public function addMessageSuccess($message)
    {
        if (isset($_SESSION['Success_messages']))
            $_SESSION['Success_messages'][] .= $message;
        else
            $_SESSION['Success_messages'] = array($message);
    }
    
    /**
     * Adds error message
     * 
     * @param string $message
     */
    public function addMessageError($message)
    {
        if (isset($_SESSION['Error_messages']))
            $_SESSION['Error_messages'][] .= $message;
        else
            $_SESSION['Error_messages'] = array($message);
    }

    /**
     * Get all current messages
     * 
     * @return array
     */
    public static function returnMessages()
    {
        $result = array();
        
        // Process success messages
        if(isset($_SESSION['Success_messages']))
        {
            $Success_messages = $_SESSION['Success_messages'];
            unset($_SESSION['Success_messages']);

            foreach($Success_messages as $m)
            {
                $result[] = self::makeMessage($m, self::MESSAGE_TYPE_SUCCESS);
            }
        }
       
        // Process error messages
        if(isset($_SESSION['Error_messages']))
        {
            $Error_messages = $_SESSION['Error_messages'];
            unset($_SESSION['Error_messages']);

            foreach($Error_messages as $m)
            {
                $result[] = self::makeMessage($m, self::MESSAGE_TYPE_ERROR);
            }
        }
        
        // Process warning messages
        if(isset($_SESSION['Warning_messages']))
        {
            $Warning_messages = $_SESSION['Warning_messages'];
            unset($_SESSION['Warning_messages']);

            foreach($Warning_messages as $m)
            {
                $result[] = self::makeMessage($m, self::MESSAGE_TYPE_WARNING);
            }
        }
        
        return $result;
    }
    
    /**
     * Makes message element
     * 
     * @param string $text
     * @param string $type
     * 
     * @return HTML_string
     */
    private static function makeMessage($text = '', $type = self::MESSAGE_TYPE_SUCCESS)
    {
        return  '<div class="alert alert-' . $type . ' alert dismissable fade in" >'
                . '<a href="#" class="close" data-dismiss="alert" aria-label="close">&times;</a>'
                . $text
                . '</div>';
    }
    
    /**
     * Verify user status
     * 
     * @param boolean $admin
     * @param string $special_rights
     * @param array $spec_array
     * @param boolean $superadmin
     * 
     * @return boolean
     */
    public function verifyUser($admin = false, $special_rights = "", $spec_array = array(), $superadmin = false)
    {
        $usermanager = new UserManager();
        $user = $usermanager->returnUser();

        if (!$user)
        {
                $this->addMessageError('Please log in.');
                $this->redirect('login');
        }
        else if ($admin && !$user['admin'])
        {
            $this->addMessageError('Insufficient rights. Contact your administrator.');
            $this->redirect('detail/intro');
        }
        else if ($superadmin && !$user['superadmin'])
        {
            $this->addMessageError('Insufficient rights. Admin rights can change only administrators. Contact your administrator.');
            $this->redirect($spec_array['url']);
        }
        else if ($special_rights != "" && !$usermanager->verify_user($special_rights, $spec_array))
        {
            $this->addMessageError("Insufficient rights. Contact your administrator.");
            $this->redirect($spec_array['url']);
        } 
    }
        
    /**
     * Protects given param against XSS
     * 
     * @param string|array $output
     * 
     * @return string|array
     */
    private function protect($output = null)
    { 
        if($output === FALSE)
        {
            return 0;
        }

        if($output === TRUE)
        {
            return 1;
        }

        if (is_string($output))
        {
            return htmlspecialchars($output, ENT_QUOTES);
        }
        else if(is_array($output))
        {
            foreach($output as $k => $txt)
            {
                $output[$k] = $this->protect($txt);
            }
            return $output;
        }
        else
        {
            return $output;
        }
    }
}
