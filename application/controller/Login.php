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

    /**
     * Re-send verification email
     */
    public function resend($id_user = null)
    {
        $user = new Users($id_user);

        if(!$user->id)
        {
            $this->redirect('login', false);
        }

        $val_model = new User_verification();

        if($val_model->is_verified($user->id))
        {
            $this->alert->success('Account is already verified.');
            $this->redirect('login', false);
        }

        try
        {
            $user->beginTransaction();

            $val_model->resend_email($user->id);

            $this->alert->success('Verification email successfuly sent.');
            $user->commitTransaction();
        }
        catch(MmdbException $e)
        {  
            $user->rollbackTransaction();
            $this->alert->error($e);
        }

        $this->redirect('login', false);
    }

    public function index()
    {
        // Is already logged in?
        if(session::user_id())
        {
            $this->redirect('lab');
        }

        $forge = new Forge('Login');

        $forge->add('molmedb_username')
            ->title('Username');

        $forge->add('molmedb_password')
            ->type('password')
            ->title('Password');

        $forge->footer_link('lab/registration', 'Sign up');

        $forge->submit('Login');

        if($this->form->is_post())
        {
            $userManager = new Users();
            try
            {
                $userManager->login($this->form->param->molmedb_username, $this->form->param->molmedb_password);
                $uid = session::user_id();

                // Check, if user has set email. If not, request
                if(!session::user_email())
                {
                    $userManager->logout();
                    $this->alert->warning('Your account does not have an email assigned to it. For the proper functioning of the services, you need to add an email and perform validation.');
                    $this->redirect('lab/registration/'.$uid);
                }

                // Check, if user is admin, or has validated email
                $ver_model = new User_verification();
                if(!$ver_model->is_verified($uid))
                {
                    $userManager->logout();
                    $this->alert->warning('Your e-mail address is not verified. Please, check the registration email.');
                    $this->alert->warning('Did you not receive the email? ' . Html::anchor("login/resend/" . $uid, 'Resend'));
                    $this->redirect('login', false);
                }

                $this->alert->success('Success!');

                if(session::is_admin())
                {
                    $this->redirect('administration');
                }

                $this->redirect('lab');
            } 
            catch (ErrorUser $ex) 
            {
                $this->alert->error($ex->getMessage());
            }
            catch (MmdbException $e)
            {
                $this->alert->error($e);
            }
        }

        $this->title = 'Login';
        $this->view = new View('forge');
        $this->view->forge = $forge;
    }
}
