<?php

/**
 * Login controller
 */
class LoginController extends Controller 
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        if($_POST)
        {
            $userManager = new Users();
            try
            {
                $userManager->login($_POST['name'], $_POST['password']);
                $this->addMessageSuccess('Success!');
                $this->redirect('detail/intro');
            } 
            catch (ErrorUser $ex) 
            {
                $this->addMessageError($ex->getMessage());
                $this->redirect('login');
            }
            catch (MmdbException $e)
            {
                $this->addMessageError($e);
                $this->redirect('login');
            }
            
        }

        $this->title = 'LogIn';
        $this->view = new View('login');
    }
}
