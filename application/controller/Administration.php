<?php

/**
 * Administration controller
 * 
 * @author Jakub Juračka
 */
class AdministrationController extends Controller 
{
    function __construct()
    {
        $this->verifyUser();
    }


    public function parse() 
    {
        // Check if user has admin rights
        $this->verifyUser(true);

        $user_manager = new UserManager();
        $upload_controller = new UploadController();
        $edit_controller = new EditController();

        $user = $user_manager->returnUser();
   
        $this->data['name'] = $user['name'];
        $this->data['admin'] = $user['admin'];
        $this->data['upload_endpoints'] = $upload_controller->get_menu_endpoints();
        $this->data['edit_endpoints'] = $edit_controller->get_menu_endpoints();
        $this->header['title'] = 'Administration MolMeDB';
        $this->view = 'administration';
    }

    /**
     * Logout function
     * 
     */
    public function logout()
    {
        $usermanager = new UserManager();

        $usermanager->logout();

        $this->redirect('login');
    }
}
