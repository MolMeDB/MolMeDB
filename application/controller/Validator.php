<?php


/**
 * Data validator
 * 
 * @author Jakub Juračka
 */
class ValidatorController extends Controller 
{

    /**
     * Constructor
     */
    function __construct()
    {
        $this->verifyUser(True);
        parent::__construct();
    }

    /**
     * Default redirection
     */
    function parse($params = NULL)
    {
        $this->redirect('validator/show');
    }

    /**
     * Shows detail of scheduler compound errors
     * 
     * @param int $id
     */
    public function show($state = Validator::POSSIBLE_DUPLICITY, $pagination = 1, $id = null)
    {
        $validator = new Validator();
        $scheduler_reports = new Log_scheduler();
        $substance = new Substances($id);

        if(!Validator::is_state_valid($state) && $state && !$id)
        {
            $this->redirect('validator/show/' . Validator::POSSIBLE_DUPLICITY . '/' . $pagination . "/" . $state);
        }

        if($id && !$substance->id)
        {
            $this->addMessageError('Invalid substance id.');
            $this->redirect('validator/show/' . $state . "/" . $pagination);
        }

        if($substance->id)
        {
            $records = $validator->get_substance_detail($id);
            $this->data['detail'] = $records;
        }
        else
        {
            $this->data['detail'] = FALSE;
        }
        
        $this->data['state'] = $state;
        $this->data['pagination'] = $pagination;
        $this->data['compounds'] = $validator->get_substances($state, 100*($pagination - 1), 100);
        $this->data['total_compounds'] = count($validator->get_substances($state));
        $this->data['reports'] = $scheduler_reports->order_by('id', 'desc')->limit(50)->get_all();
        $this->view = 'validator/validator';
    }

    /**
     * Joins 2 given molecules to one record
     * 
     * @param integer $substance_1
     * @param integer $substance_2
     * 
     * @author Jakub Juračka
     */
    public function join($substance_1 = NULL, $substance_2 = NULL)
    {
        if($this->form->is_post())
        {
            $substance_1 = $this->form->param->substance_1 ? $this->form->param->substance_1 : $substance_1;
            $substance_2 = $this->form->param->substance_2 ? $this->form->param->substance_2 : $substance_2;
        }

        $new = new Substances($substance_1);
        $old = new Substances($substance_2);

        // Check if exists
        if($substance_1 && !$old->id)
        {
            $this->addMessageWarning('Substance not found.');
            return;
        }

        // Check if exists
        if($substance_2 && !$new->id)
        {
            $this->addMessageWarning('Substance not found.');
            return;
        }

        $validatorModel = new Validator();

        // Join
        if($old->id && $new->id)
        {
            try
            {
                // Start transaction
                $new->beginTransaction();

                // Join alternames
                try
                {
                    $validatorModel->join_alternames($old->id, $new->id);
                }
                catch(Exception $e)
                {
                    throw new Exception('Cannot join alternames.');
                }

                // Join energy data
                try
                {
                    $validatorModel->join_energy_values($old->id, $new->id);
                }
                catch(Exception $e)
                {
                    throw new Exception('Cannot join energy values.');
                }

                // Join interactions
                try
                {
                    $validatorModel->join_interactions($old->id, $new->id);
                }
                catch(Exception $e)
                {
                    throw new Exception('Cannot join interaction values.');
                }

                // Join transporters
                try
                {
                    $validatorModel->join_transporters($old->id, $new->id);
                }
                catch(Exception $e)
                {
                    throw new Exception('Cannot join transporter values.');
                }

                // Proccess info about duplications
                $validatorModel->delete_links_on_substance($old->id);
                $validatorModel->delete_substance_links($new->id);

                // Check name
                $new_name = $new->name;

                if($new_name == $new->identifier && $old->name != $old->identifier)
                {
                    $new_name = $old->name;
                }

                // Join substance details
                $new->name = $new_name;
                $new->SMILES = !$new->SMILES || $new->SMILES == '' ? $old->SMILES : $new->SMILES;
                $new->MW = $new->MW === NULL || $new->MW == '' ? $old->MW : $new->MW;
                $new->Area = $new->Area === NULL || $new->Area == '' ? $old->Area : $new->Area;
                $new->Volume = $new->Volume === NULL || $new->Volume == '' ? $old->Volume : $new->Volume;
                $new->LogP = $new->LogP === NULL || $new->LogP == '' ? $old->LogP : $new->LogP;
                $new->pubchem = !$new->pubchem || $new->pubchem == '' ? $old->pubchem : $new->pubchem;
                $new->drugbank = !$new->drugbank || $new->drugbank == '' ? $old->drugbank : $new->drugbank;
                $new->chEBI = !$new->chEBI || $new->chEBI == '' ? $old->chEBI : $new->chEBI;
                $new->chEMBL = !$new->chEMBL || $new->chEMBL == '' ? $old->chEMBL : $new->chEMBL;
                $new->pdb = !$new->pdb || $new->pdb == '' ? $old->pdb : $new->pdb;
                $new->validated = Validator::NOT_VALIDATED;

                // Add record about link
                try
                {
                    $link = new Substance_links();
                    $link->id_substance = $new->id;
                    $link->identifier = $old->identifier;
                    $link->user_id = $this->session->user->id;

                    $link->save();
                }
                catch(Exception $e)
                {
                    throw new Exception('Cannot add link from old substance to the new one.');
                }

                // Delete old substance
                $old->delete();
                $new->save();

                // Commit
                $new->commitTransaction();

                $this->addMessageSuccess('Molecules was successfully joined.');
                $this->addMessageWarning('New molecule was labeled as NOT VALIDATED and will be re-validated in next validation run.');

                $this->redirect('mol/' . $new->identifier);
            }
            catch(Exception $e)
            {
                $new->rollbackTransaction();
                $this->addMessageError($e->getMessage());
            }
        }

        $this->redirect('validator/show');
    }
}
