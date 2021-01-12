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

        $this->data["content"] = $intro->content;;

        $this->view = 'intro';
        $this->header['title'] = 'MolMeDB';
    }


    /**
     * Show contact
     */
    public function contact()
    {
        $contact = new Articles(Articles::T_CONTACTS);

        $this->data["contact"] = $contact->content;
        $this->header['title'] = 'Contact';
        $this->view = 'contact';
    }

    /**
     * Shows documentation
     */
    public function documentation()
    {
        $documentation = new Articles(Articles::T_DOCUMENTATION);

        $this->data['content'] = $documentation->content;
        $this->view = 'documentation';
        $this->header['title'] = 'Documentation';
    }
}
