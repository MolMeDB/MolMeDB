<?php
 
/**
 * Controller for handling new user registrations
 */
class SignupController extends Controller 
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
            $usermanager = new Users();

            try
            {
                $usermanager->sign_up($_POST['name'], $_POST['password'], $_POST['passwordConfirm'], $_POST['year']);
                $usermanager->login($_POST['name'], $_POST['password']);
                $this->addMessageSuccess('Successfully signed up');
                $this->redirect('detail/intro');
            } 
            catch (Exception $ex) 
            {
                $this->addMessageError($ex->getMessage());
            }
        }
        
        $this->header['title'] = 'Sign up';
        $this->view = 'signup';
    }
}
