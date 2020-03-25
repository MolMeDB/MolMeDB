<?php
 
/**
 * Controller for handling new user registrations
 */
class SignupController extends Controller 
{
    public function parse() 
    {    
        if($_POST)
        {
            $usermanager = new UserManager();

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
