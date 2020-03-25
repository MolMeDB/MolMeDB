<?php

/**
 * Delete controller
 * 
 * @author Jakub Juračka
 */
class DeleteController extends Controller 
{

    /**
     * Constructor
     * Checks access rights
     */
    function __construct()
    {
        $this->verifyUser(True);
    }


    /**
     * Delete membrane detail
     * 
     * @param integer $id
     */
    public function membrane($id)
    {
        $detail = new Membranes($id);
        $redirection = isset($_GET['redirect']) ? $_GET['redirect'] : 'edit/membrane';

        // if not exists
        if(!$detail->id)
        {
            $this->addMessageError('Membrane with given ID was not found.');
            $this->redirect('redirection');
        }

        // Try to delete
        try
        {
            $detail->delete();        
        }
        catch(Exception $e)
        {
            $this->addMessageError($e->getMessage());
            $this->redirect($redirection);
        }

        $this->addMessageSuccess('Membrane detail was deleted.');
        $this->redirect($redirection);
    }

    /**
     * Delete method detail
     * 
     * @param integer $id
     */
    public function method($id)
    {
        $detail = new Methods($id);
        $redirection = isset($_GET['redirect']) ? $_GET['redirect'] : 'edit/method';

        // if not exists
        if(!$detail->id)
        {
            $this->addMessageError('Method with given ID was not found.');
            $this->redirect('redirection');
        }

        // Try to delete
        try
        {
            $detail->delete();        
        }
        catch(Exception $e)
        {
            $this->addMessageError($e->getMessage());
            $this->redirect($redirection);
        }

        $this->addMessageSuccess('Method detail was deleted.');
        $this->redirect($redirection);
    }

    /**
     * Delete compound
     * 
     * @param integer $id
     * 
     */
    public function compound($id)
    {
        $detail = new Substances($id);

        // Checks if compound exists
        if(!$detail->id)
        {
            $this->addMessageError('Param');
            $this->redirect('error');
        }

        try
        {
            $detail->delete();        
        }
        catch(Exception $e)
        {
            $this->addMessageError($e->getMessage());
            $this->redirect('error');
        }

        $this->addMessageSuccess('Record was deleted.');
        $this->redirect('detail/intro');
    }

    /**
     * Deletes dataset
     */
    public function dataset($id)
    {
        $dataset = new Datasets($id);
        $redirect = isset($_GET['redirection']) ? $_GET['redirection'] : 'edit/dataset';

        if(!$dataset->id)
        {
            $this->addMessageError('Dataset not found.');
        }
        try
        {
            $dataset->delete();    
            $this->addMessageSuccess('Dataset was deleted.');    
        }
        catch(Exception $e)
        {
            $this->addMessageError($e->getMessage());
        }

        $this->redirect($redirect);
    }

    /**
     * Deletes interaction 
     * 
     * @param integer $id - interaction ID
     */
    public function interaction($id)
    {
        $interaction = new Interactions($id);

        $redirect_path = isset($_GET['redirection']) ? $_GET['redirection'] : 'detail/intro';

        if(!$interaction->id)
        {
            $this->addMessageError('Record');
            $this->redirect($redirect_path);
        }
        try
        {
            $interaction->delete();
            $this->addMessageSuccess('Interaction [ID: ' . $id . '] was deleted.');
        }
        catch(Exception $e)
        {
            $this->addMessageError($e->getMessage());
            $this->redirect($redirect_path);
        }

        $this->redirect($redirect_path);
    }
}