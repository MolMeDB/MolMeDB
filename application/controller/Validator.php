<?php


/**
 * Data validator
 * 
 */
class ValidatorController extends Controller 
{

    /**
     * Constructor
     */
    function __construct()
    {
        $this->verifyUser(True);

        // $this->addMessageWarning('Sorry, page is temporarily unavailable.');
        // $this->join();
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
        if($_POST)
        {
            $substance_1 = isset($_POST['substance_1']) ? $_POST['substance_1'] : $substance_1;
            $substance_2 = isset($_POST['substance_2']) ? $_POST['substance_2'] : $substance_2;
        }

        $old = new Substances($substance_1);
        $new = new Substances($substance_2);

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
            die;
            try
            {
                // Start transaction
                $new->beginTransaction();

                // Join alternames
                $validatorModel->join_alternames($old->id, $new->id);

                // Join energy data
                $validatorModel->join_energy_values($old->id, $new->id);

                // Join interactions
                $validatorModel->join_interactions($old->id, $new->id);

                // Join transporters
                $validatorModel->join_transporters($old->id, $new->id);

                // Commit
                $new->commitTransaction();
            }
            catch(Exception $e)
            {
                $new->rollbackTransaction();
                $this->addMessageError($e->getMessage());
            }
        }

        $this->header['title'] = 'Join molecules';
        $this->view = 'validator/joiner';
    }

    public function parse() 
    {
        $this->redirect('validator/join');
        $validatorModel = new Validator();
        $substanceModel = new Substances();
        
        if($_POST)
        {
            if(isset($_POST['asValidated'])){
                $id = $_POST['ligand_1'];
                try{
                    $validatorModel->set_as_validated($id);
                    $this->addMessageSuccess("Molecule with id = " . $id . " was labeled as validated.");
                }
                catch(Exception $ex){
                    $this->addMessageError("Error");
                }
            }
            
            else if(isset($_POST['join'])){
                $id_1 = $_POST['ligand_1'];
                $id_2 = $_POST['ligand_2'];
                try{
                    DB::beginTransaction();
                    //Změnit idSubstance pro interakce - z ID2 na ID1
                    $validatorModel->changeInterIDs($id_1, $id_2);
                    //Zěnit hodnoty volných energií z ID2 na ID1
                    $validatorModel->changeEnergyIDs($id_1, $id_2);
                    //Změnit alternames z ID2 na ID1
                    $validatorModel->changeAlterNamesIDs($id_1, $id_2);
                    //Odstranit záznamy validací s ID1 x ID2
                    //UPDATE validace z ID2 na ID1
                    $validatorModel->delete_change_ids($id_1, $id_2);
                    //Odstranění molekuly
                    $substanceModel->delete($id_2);
                    Db::commitTransaction();
                    $this->addMessageSuccess("Molecules with IDs " . $id_1 . " and " . $id_2 . " was successfully joined.");
                }
                catch(Exception $ex){
                    $this->addMessageError("Error - molecules can't contain same interaction (same combination of Method,Membrane,Reference,Charge and Temperature)." . $ex->getMessage());
                    Db::rollbackTransaction();
                }
            }
        }
        
        // $this->data['molecules'] = $validatorModel->get_duplicites_ids();
        // $this->data['num_notValidated'] = $validatorModel->num_notValidated();
        $this->header['title'] = 'Validator';
        $this->view = 'validator';
    }
}
