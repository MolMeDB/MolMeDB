<?php

abstract class Controller
{
    /** Message types */
    CONST MESSAGE_TYPE_SUCCESS = 'success';
    CONST MESSAGE_TYPE_ERROR = 'danger';
    CONST MESSAGE_TYPE_WARNING = 'warning';
    
    /** Shared controller data */
    /**
     * @var View
     */
    public $view;

    /**
     * @var string 
     */
    public $title = '';

    /**
     * @var string
     */
    public $token = '';

    /**
     * @var Breadcrumbs
     */
    public $breadcrumbs = NULL;

    /** 
     * Config handler
     * 
     * @var Config
     */
    protected $config;

    /** 
     * Alerts handler 
     * 
     * @var Alert
     */
    protected $alert;

    /** 
     * Form submit handler 
     * 
     * @var Form
     */
    protected $form;

    /**
     * @var HeaderParser
     */
    static protected $requested_header;

    /**
     * Constructor
     */
    function __construct()
    {
        $this->config = new Config();
        $this->alert = new Alert();
        $this->form = new Form();
        self::$requested_header = self::$requested_header ?? new HeaderParser();
    }
    
    /**
     * Redirects to given $url
     * 
     * @param string $url
     */
    public function redirect($url, $prefer_setting = true)
    {
        if($prefer_setting && isset($_GET['redirection']))
        {
            $url = $_GET['redirection'] ? $_GET['redirection'] : $url;
        }

        if(!preg_match('/^http/', $url))
        {
            $url = "/".ltrim("$url","/");
        }

        header("Location: $url"); 
        header("Connection: close");
        exit;
    }
    
    /**
     * Adds warning message
     * 
     * @param string $message
     * @deprecated - Use alert->warning instead
     */
    public function addMessageWarning($message)
    {
        $this->alert->warning($message);
    }
    
    /**
     * Adds success message
     * 
     * @param string $message
     * @deprecated - Use alert->success instead
     */
    public function addMessageSuccess($message)
    {
        $this->alert->success($message);
    }
    
    /**
     * Adds error message
     * 
     * @param string $message
     * @deprecated - Use alert->error instead
     */
    public function addMessageError($message)
    {
        $this->alert->error($message);
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
        $usermanager = new Users();
        $user = $usermanager->returnUser();

        if (!$user)
        {
            $this->alert->error('Please log in.');
            $this->redirect('login');
        }
        else if ($admin && !$user['admin'])
        {
            $this->alert->error('Insufficient rights. Contact your administrator.');
            $this->redirect('detail/intro');
        }
        else if ($superadmin && !$user['superadmin'])
        {
            $this->alert->error('Insufficient rights. Admin rights can change only administrators. Contact your administrator.');
            $this->redirect($spec_array['url']);
        }
        else if ($special_rights != "" && !$usermanager->verify_user($special_rights, $spec_array))
        {
            $this->alert->error("Insufficient rights. Contact your administrator.");
            $this->redirect($spec_array['url']);
        } 
    }
        
    /**
     * Transforms exception to short text string
     * 
     * @param Exception $e
     * 
     * @return string
     * @author Jakub Juračka
     */
    public static function transform_exception($e)
    {
        $message_text = $e->getMessage();

        // Remove junk info
        $end = strpos($message_text, 'Stack trace');
        $message_text = $end ? substr($message_text, 0, $end) : $message_text;

        return json_encode(
            array
            (
                'text' => $message_text,
                'trace' => json_encode($e->getTrace())
            )
        );
    }
}
