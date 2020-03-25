<?php


/**
 * Data validator
 * 
 * TEMPORARY UNAVAILABLE!
 */
class ValidatorController extends Controller 
{
    function __construct()
    {
        $this->verifyUser(True);

        $this->addMessageWarning('Sorry, page is temporary unavailable.');
        $this->redirect('administration');
    }


    public function parse($parameters) 
    {
        $validatorModel = new Validator();
        $substanceModel = new Substances();
        $this->verifyUser(true);
        
        if(isset($parameters[0]) && $parameters[0] == 'validate'){
            $validatorModel->validate();
            $this->addMessageSuccess('All molecules checked.');
            $this->redirect('validator');
        }
        
        if($_POST){
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
        
        $this->data['molecules'] = $validatorModel->get_duplicites_ids();
        $this->data['num_notValidated'] = $validatorModel->num_notValidated();
        $this->header['title'] = 'Validator';
        $this->view = 'validator';
    }
}
