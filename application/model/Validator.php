<?php

class Validator{
    public function get_duplicites_ids(){
        $ids = array();
        $ids = Db::queryAll('SELECT DISTINCT id_substance_1 FROM validations ');
        
        $result = array();
        foreach($ids as $id){
            $result[] = Db::queryOne('SELECT idSubstance, name FROM substances WHERE idSubstance = ? LIMIT 1', array($id['id_substance_1']));
        }
        return $result;
    }
    
    public function set_as_validated($id){
        return Db::query("DELETE FROM validations WHERE id_substance_1 = ? OR id_substance_2 = ?", array($id, $id));
    }
    
    public function changeInterIds($to, $from){
        //From and To are IDs
        $exists = Db::queryAll('SELECT * FROM interaction WHERE idSubstance = ?', array($from));
        
        //Check possible duplicites
        foreach($exists as $detail){
            $check = Db::queryOne('SELECT id FROM interaction WHERE idMembrane = ? AND idSubstance = ? AND idMethod = ? AND temperature = ? AND charge = ? AND idReference = ?', 
                    array($detail['idMembrane'], $to, $detail['idMethod'], $detail['temperature'], $detail['charge'], $detail['idReference']));
            if($check){
                throw new Exception('Interaction duplicates.');
            }     
        }
        
        return Db::query("UPDATE `interaction` SET `idSubstance` = ? WHERE `idSubstance` = ?", array($to, $from));
    }
    
    public function changeEnergyIds($to, $from){
        //From and To are IDs
        return Db::query("UPDATE `energy` SET `idSubstance` = ? WHERE `idSubstance` = ?", array($to, $from));
    }
    
    public function changeAlterNamesIDs($to, $from){
        //From and To are IDs
        return Db::query("UPDATE `alternames` SET `idSubstance` = ? WHERE `idSubstance` = ?", array($to, $from));
    }
    
    public function delete_change_ids($to, $from){
        //From and To are IDs
        Db::query("DELETE FROM `validations` WHERE (`id_substance_1` = ? AND `id_substance_2` = ?) OR (`id_substance_1` = ? AND `id_substance_2` = ?)", array($to, $from, $from, $to));
        Db::query("UPDATE `validations` SET `id_substance_2` = ? WHERE `id_substance_2` = ?", array($to, $from));
        return Db::query("UPDATE `validations` SET `id_substance_1` = ? WHERE `id_substance_1` = ?", array($to, $from));
    }
    
    public function num_notValidated(){
        return Db::queryOne("SELECT COUNT(*) as pocet FROM substances WHERE validated = 0")['pocet'];
    }
    
    public function validate(){
        return Db::insert('log_validations', array('user_id' => $_SESSION['user']['id']));
    }
}