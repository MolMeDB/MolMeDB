<?php

/**
 * Administration controller
 * 
 * @author Jakub Juračka
 */
class AdministrationController extends Controller 
{
    /**
     * Constructor
     * 
     * Only for administrators
     */
    function __construct()
    {
        parent::__construct();
        $this->verifyUser(true);
    }


    public function index() 
    {
        $user_manager = new Users();
        $upload_controller = new UploadController();
        $edit_controller = new EditController();

        $user = $user_manager->returnUser();
   
        $this->title = 'Administration';
        $this->view = new View('administration');
        $this->view->name = $user['name'];
        $this->view->admin = $user['admin'];
        $this->view->upload_endpoints = $upload_controller->get_menu_endpoints();
        $this->view->edit_endpoints = $edit_controller->get_menu_endpoints();

        $this->breadcrumbs = new Breadcrumbs();
        $this->breadcrumbs->add('Administration');
    }

    /**
     * Logout function
     */
    public function logout()
    {
        $usermanager = new Users();
        $usermanager->logout();
        $this->redirect('login');
    }
}
