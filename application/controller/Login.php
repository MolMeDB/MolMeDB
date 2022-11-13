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
        $forge = new Forge('Login');

        $forge->add('molmedb_username')
            ->title('Username');

        $forge->add('molmedb_password')
            ->type('password')
            ->title('Password');

        $forge->submit('Login');

        if($this->form->is_post())
        {
            $userManager = new Users();
            try
            {
                $userManager->login($this->form->param->molmedb_username, $this->form->param->molmedb_password);
                $this->addMessageSuccess('Success!');

                if(session::is_admin())
                {
                    $this->redirect('administration');
                }

                $this->redirect('detail/intro');
            } 
            catch (ErrorUser $ex) 
            {
                $this->addMessageError($ex->getMessage());
            }
            catch (MmdbException $e)
            {
                $this->addMessageError($e);
            }
        }

        $this->title = 'Login';
        $this->view = new View('forge');
        $this->view->forge = $forge;
    }
}
