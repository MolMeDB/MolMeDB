<?php

/**
 * Delete controller
 * 
 * @author Jakub Juračka
 */
class DeleteController extends Controller 
{

    /** ENDPOINT TYPES */
    CONST T_DETAIL = 1;
    CONST T_DATASET = 2;

    /**
     * Constructor
     * Checks access rights
     */
    function __construct()
    {
        parent::__construct();
        $this->verifyUser(True);
    }

    /**
     * Deletes COSMO scheduled job
     * 
     * @param int $id
     */
    public function cosmo_job($id)
    {
        $c = new Run_cosmo($id);
        $redir = 'validator/cosmo';

        if(!$c->id)
        {
            $this->alert->warning('Record not found.');
            $this->redirect($redir);
        }

        $c->status = $c::STATUS_REMOVE;
        $c->save();

        $this->alert->success('Scheduled job will be removed.');
        $this->redirect($redir);
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
        catch(MmdbException $e)
        {
            $this->alert->error($e);
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
        catch(MmdbException $e)
        {
            $this->alert->error($e);
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
        catch(MmdbException $e)
        {
            $this->alert->error($e);
            $this->redirect('error');
        }

        $this->addMessageSuccess('Record was deleted.');
        $this->redirect('detail/intro');
    }


    /**
     * Deletes interaction 
     * 
     * @param integer $type
     * @param integer $id - interaction ID
     */
    public function interaction($type, $id)
    {
        $redirect = isset($_GET['redirection']) ? $_GET['redirection'] : 'edit/dataset';

        $type = intval($type);

        if($type === self::T_DATASET)
        {
            $dataset = new Datasets($id);

            if(!$dataset->id)
            {
                $this->addMessageError('Dataset not found.');
            }
            try
            {
                $dataset->delete();    
                $this->addMessageSuccess('Dataset was deleted.');    
            }
            catch(MmdbException $e)
            {
                $this->alert->error($e);
            }

            
        }
        else if($type === self::T_DETAIL)
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
            catch(MmdbException $e)
            {
                $this->alert->error($e);
            }
        }
        else
        {
            $this->addMessageError('Invalid parameter type.');
        }

        $this->redirect($redirect);
    }

    /**
     * Deletes transporters 
     * 
     * @param integer $type
     * @param integer $id - interaction ID
     */
    public function transporter($type, $id)
    {
        $redirect = isset($_GET['redirection']) ? $_GET['redirection'] : 'edit/dataset';

        $type = intval($type);

        if($type === self::T_DATASET)
        {
            $dataset = new Transporter_datasets($id);

            if(!$dataset->id)
            {
                $this->addMessageError('Dataset not found.');
            }
            try
            {
                $dataset->delete();    
                $this->addMessageSuccess('Dataset was deleted.');    
            }
            catch(MmdbException $e)
            {
                $this->alert->error($e);
            }

            
        }
        else if($type === self::T_DETAIL)
        {
            $interaction = new Transporters($id);

            $redirect_path = isset($_GET['redirection']) ? $_GET['redirection'] : 'detail/intro';

            if(!$interaction->id)
            {
                $this->addMessageError('Record');
                $this->redirect($redirect_path);
            }
            try
            {
                $interaction->delete();
                $this->addMessageSuccess('Transporter detail [ID: ' . $id . '] was deleted.');
            }
            catch(MmdbException $e)
            {
                $this->alert->error($e);
            }
        }
        else
        {
            $this->addMessageError('Invalid parameter type.');
        }

        $this->redirect($redirect);
    }
}