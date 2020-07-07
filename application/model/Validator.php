<?php

/**
 * Validator class
 * 
 * @property int $id
 * @property int $id_substance_1
 * @property int $id_substance_2
 * @property int $duplicity
 * @property string $createDateTime
 * 
 */
class Validator extends Db
{
    /**
     * Validator constants
     */
    const NOT_VALIDATED = 0;
    /** MISSING DATA AUTO-FILLED */
    const SUBSTANCE_FILLED = 1;
    /** Filled identifiers */
    const IDENTIFIERS_FILLED = 2;
    /** LABELED AS POSSIBLE DUPLICITY */
    const POSSIBLE_DUPLICITY = 3;
    /** Filled LogPs */
    const LogP_FILLED = 4;
    /** VALIDATED */
    const VALIDATED = 5;


    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'validations';
        parent::__construct($id);
    }

    /**
     * Joins energy values  
     * 
     * @param int $old_subst_id
     * @param int $new_subst_id
     * 
     */
    public function join_energy_values($old_subst_id, $new_subst_id)
    {
        try
        {
            return $this->query('
                UPDATE `energy`
                SET `id_substance` = ?
                WHERE `id_substance` = ?
            ', array($new_subst_id, $old_subst_id));
        }
        catch(Exception $e)
        {
            throw new Exception('Cannot join energy values. Maybe both has same combination membrane-method.');
        }
    } 

    /**
     * Joins alternames  
     * 
     * @param int $old_subst_id
     * @param int $new_subst_id
     * 
     */
    public function join_alternames($old_subst_id, $new_subst_id)
    {
        return $this->query('
            UPDATE `alternames`
            SET `id_substance` = ?
            WHERE `id_substance` = ?
        ', array($new_subst_id, $old_subst_id));
    } 

    /**
     * Joins interactions  
     * 
     * @param int $old_subst_id
     * @param int $new_subst_id
     * 
     */
    public function join_interactions($old_subst_id, $new_subst_id)
    {
        try
        {
            return $this->query('
                UPDATE `interaction`
                SET `id_substance` = ?
                WHERE `id_substance` = ?
            ', array($new_subst_id, $old_subst_id));
        }
        catch(Exception $e)
        {
            throw new Exception('Cannot join interactions. Maybe both has same combination membrane-method-reference-charge.');
        }
    } 
    
    /**
     * Joins alternames  
     * 
     * @param int $old_subst_id
     * @param int $new_subst_id
     * 
     */
    public function join_transporters($old_subst_id, $new_subst_id)
    {
        return $this->query('
            UPDATE `transporters`
            SET `id_substance` = ?
            WHERE `id_substance` = ?
        ', array($new_subst_id, $old_subst_id));
    } 


    
}