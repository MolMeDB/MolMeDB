<?php

class DetailController extends Controller 
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Show intro
     */
    public function intro()
    {    
        $intro = new Articles(Articles::T_INTRO);

        $this->title = 'MolMeDB';
        $this->view = new View('intro');
        $this->view->content = $intro->content;
    }


    /**
     * Show contact
     */
    public function contact()
    {
        $contact = new Articles(Articles::T_CONTACTS);

        $this->title = 'Contact';
        $this->view = new View('contact');
        $this->view->contact = $contact->content;
    }

    /**
     * Shows documentation
     */
    public function documentation()
    {
        $documentation = new Articles(Articles::T_DOCUMENTATION);

        $this->title = 'Docs';
        $this->view = new View('documentation');
        $this->view->content = $documentation->content;
    }
}
