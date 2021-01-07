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

    /** Alerts handler */
    protected $alert;

    /** Form submit handler */
    protected $form;

    /**
     * Session info holder
     */
    protected $session;

    /**
     * Constructor
     */
    function __construct()
    {
        $this->config = new Config();
        $this->alert = new Alert();
        $this->form = new Form();
        $this->session = new Iterable_object($_SESSION, true);
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
        $this->alert->warning($message);
    }
    
    /**
     * Adds success message
     * 
     * @param string $message
     */
    public function addMessageSuccess($message)
    {
        $this->alert->success($message);
    }
    
    /**
     * Adds error message
     * 
     * @param string $message
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
