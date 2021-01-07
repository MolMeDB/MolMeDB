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

    public function parse()
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
            catch (Exception $e)
            {
                $this->addMessageError($e->getMessage());
                $this->redirect('login');
            }
            
        }

        $this->header['title'] = 'LogIn';
        $this->view = 'login';
    }
}
