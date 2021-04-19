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
        parent::__construct();
        $this->verifyUser(True);
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
        
        $this->header['title'] = 'Validator' . ($substance->name ? " | $substance->name" : '');
        $this->data['state'] = $state;
        $this->data['pagination'] = $pagination;
        $this->data['compounds'] = $validator->get_substances($state, 100*($pagination - 1), 100);
        $this->data['total_compounds'] = count($validator->get_substances($state));
        $this->data['reports'] = $scheduler_reports->order_by('id', 'desc')->limit(50)->get_all();
        $this->view = 'validator/validator';
    }

    /**
     * Validates current substance state
     * 
     * @param int $substance_id
     */
    public function validate_state($substance_id)
    {
        $substance = new Substances($substance_id);

        if(!$substance->id)
        {
            $this->alert->error('Substance not found.');
            $this->redirect('validator/show');
        }

        try
        {
            $substance->waiting = NULL;
            $substance->save();

            $log = new Scheduler_errors();
            $log->id_substance = $substance->id;
            $log->error_text = 'State = ' . $substance->validated . ' was validated.';
            $log->id_user = $this->session->user->id;
            $log->count = 1;

            $log->save();

            $this->alert->success('Current state of substance [' . $substance->name . '] was labeled as validated.');
        }
        catch(Exception $e)
        {
            $this->alert->error($e->getMessage());
        }

        $this->redirect('validator/show/' . $substance->validated . "/1/" . $substance->id);
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
                $new->waiting = NULL;

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

    /**
     * Unlabel interaction dataset duplicities
     * 
     * @param int $dataset_id
     * 
     * @author Jakub Juracka
     */
    public function unlabel_duplicity($dataset_id)
    {
        $dataset = new Datasets($dataset_id);

        if(!$dataset->id)
        {
            $this->addMessageError('Invalid dataset.');
            $this->redirect('edit/dsInteractions');
        }

        $validator = new Validator();

        try
        {
            $data = $validator->get_inter_dataset_duplicity($dataset->id);
            $dataset->beginTransaction();

            foreach($data as $row)
            {
                $this->disjoin($row->id_substance_1, $row->id_substance_2);
            }

            $dataset->commitTransaction();
            $this->alert->success('Done');
        }
        catch(Exception $e)
        {
            $dataset->rollbackTransaction();
            $this->alert->error($e->getMessage());
        }  

        $this->redirect('edit/dsInteractions/' . $dataset->id);
    }


    /**
     * Disjoin 2 possible duplicity molecules
     * 
     * @param int $substance_id_1
     * @param int $substance_id_2
     * 
     * @author Jakub Juracka
     */
    public function disjoin($substance_id_1, $substance_id_2)
    {
        $enabled_redirection = debug_backtrace()[1]['function'] === 'parse' ? TRUE : FALSE;

        $substance_1 = new Substances($substance_id_1);
        $substance_2 = new Substances($substance_id_2);

        if(!$substance_1->id || !$substance_2->id)
        {
            if($enabled_redirection)
            {
                $this->alert->error('Invalid arguments.');
                $this->redirect('validator');
            }
            else
            {
                throw new Exception('Invalid arguments.');
            }
        }

        try
        {
            if($enabled_redirection)
                $substance_1->beginTransaction();

            $validator_model = new Validator();
            $rows = $validator_model->where(array
                (
                    '(',
                    'id_substance_1' => $substance_1->id,
                    'id_substance_2' => $substance_2->id,
                    ')',
                ))
                ->where(array
                (
                    'OR',
                    '(',
                    'id_substance_1' => $substance_2->id,
                    'id_substance_2' => $substance_1->id,
                    ')'
                ))
                ->get_all();

            // Change state of validations
            foreach($rows as $r)
            {
                $val = new Validator($r->id);
                if(!$val->id)
                {
                    continue;
                }

                $val->active = 0;
                $val->save();

                // Add info about action
                $err = new Scheduler_errors();
                $err_2 = new Scheduler_errors();
                $err->id_substance = $substance_1->id;
                $err_2->id_substance = $substance_2->id;
                $err->error_text = 'Labeled as non duplicity. (' . $substance_2->identifier . ' - ' . $substance_2->name . ').';
                $err_2->error_text = 'Labeled as non duplicity. (' . $substance_1->identifier  . ' - ' . $substance_1->name . ').';
                $err->count = $err_2->count = 1;
                $err->id_user = $this->session->user->id;
                $err_2->id_user = $this->session->user->id;

                $err->save();
                $err_2->save();
            }

            // If substance doesnt have next possible duplicity, then revalidate
            $s1_vals = $validator_model->where(array
                (
                    'id_substance_1' => $substance_1->id,
                    'active' => '1'
                ))
                ->get_all();

            $s2_vals = $validator_model->where(array
                (
                    'id_substance_1' => $substance_2->id,
                    'active' => '1'
                ))
                ->get_all();

            if(!count($s1_vals))
            {
                if($enabled_redirection)
                {
                    $this->alert->warning('Substance ' . $substance_1->name . " [$substance_1->identifier] will be revalidated in next scheduler run.");
                }
                $substance_1->validated = Validator::NOT_VALIDATED;
                $substance_1->waiting = NULL;
                $substance_1->save();
            }

            if(!count($s2_vals))
            {
                if($enabled_redirection)
                {
                    $this->alert->warning('Substance ' . $substance_2->name . " [$substance_2->identifier] will be revalidated in next scheduler run.");
                }
                $substance_2->validated = Validator::NOT_VALIDATED;
                $substance_2->waiting = NULL;
                $substance_2->save();
            }

           
            if($enabled_redirection)
            {
                $substance_1->commitTransaction();
                $this->alert->success('Relation between ' . $substance_1->name . ' and ' . $substance_2->name . ' was labeled as nonduplicity.');
            }
        }
        catch(Exception $e)
        {   
            if($enabled_redirection)
            {
                $substance_1->rollbackTransaction();
                $this->alert->error($e);
            }
            else
            {
                throw new Exception($e);
            }
        }

        if($enabled_redirection)
        {
            $this->redirect('validator/3/1/' . $substance_1->id);
        }
    }
}
