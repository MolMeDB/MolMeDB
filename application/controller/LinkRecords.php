<?php

class LinkRecordsController extends Controller {
    public function parse($parameters) {
        $substanceModel = new Substances();
        $alterNamesModel = new AlterNames();
        $interactionModel = new Interactions();
        $energyModel = new Energy();
        
        if($_POST){
            try{
                Db::beginTransaction();
                
                $alterNamesModel->linkRecords($_POST['id1'],$_POST['id2']);
                $interactionModel->linkRecords($_POST['id1'],$_POST['id2']);
                $energyModel->linkRecords($_POST['id1'],$_POST['id2']);
                $substanceModel->linkRecords($_POST['id1'],$_POST['id2']);
                $this->addMessageSuccess("Ligands with IDs: '" . $_POST['id1'] . "', '" . $_POST['id2'] . "' were succesfully linked.");
                Db::commitTransaction();
            }
            catch(Exception $ex){
                $this->addMessageError($ex);
                Db::rollbackTransaction();
            }
        }
       
        $this->header['title'] = 'Link Records';
        $this->view = 'linkRecords';
    }
}
