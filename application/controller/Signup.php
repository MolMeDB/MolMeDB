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

    public function index() 
    {    
        if($this->form->is_post())
        {
            $usermanager = new Users();

            try
            {
                $usermanager->sign_up($this->form->param->name, $this->form->param->password, $this->form->param->passwordConfirm, $this->form->param->year);
                $usermanager->login($this->form->param->name, $this->form->param->password);
                $this->addMessageSuccess('Successfully signed up');
                $this->redirect('detail/intro');
            } 
            catch (Exception $ex) 
            {
                $this->addMessageError($ex->getMessage());
            }
        }
        
        $this->title = 'Sign up';
        $this->view = new View('signup');
    }
}
