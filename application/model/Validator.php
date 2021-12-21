<?php

/**
 * Validator class
 * 
 * @property int $id
 * @property int $id_substance_1
 * @property Substances $substance_1
 * @property int $id_substance_2
 * @property Substances $substance_2
 * @property int $type
 * @property int $state
 * @property string $description
 * @property string $dateTime
 * @property string $createDateTime
 * 
 * @property Validator_value[] $values
 */
class Validator extends Db
{
    /**
     * Validator STATES
     */
    const STATE_OPENED = 1;
    const STATE_CLOSED = 2;

    /**
     * Validator enum states
     */
    private static $enum_states = array
    (
        self::STATE_OPENED => 'Opened',
        self::STATE_CLOSED => 'Closed'
    );

    protected $has_one = array
    (
        [
            'var' => 'substance_1',
            'class' => 'Substances'
        ],
        [
            'var' => 'substance_2',
            'class' => 'Substances'
        ]
    );

    protected $has_many = array
    (
        [
            'var'   => 'values',
            'own'   => 'id_validation',
            'class' => 'Validator_value'
        ]
    );

    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'validations';
        parent::__construct($id);
    }

    /**
     * Is state valid?
     * 
     * @param int $state_id
     * 
     * @return boolean
     */ 
    public static function is_state_valid($state_id)
    {
        return array_key_exists($state_id, self::$enum_states);
    }

    /**
     * Returns enum state
     * 
     * @param int $state_id
     * 
     * @return string
     */
    public static function get_enum_state($state_id)
    {
        if(in_array($state_id, self::$enum_states))
        {
            return self::$enum_states[$state_id];
        }
        return NULL;
    }

    /**
     * Get validator data
     * 
     * @return Iterable_object
     */
    public function get_substances($state = NULL, $sql_offset = 0, $limit = NULL)
    {
        $limit_str = "";

        if($limit && is_numeric($limit))
        {
            $limit_str = "LIMIT $sql_offset, $limit";
        }

        if($state === NULL || !self::is_state_valid($state))
        {
            return $this->queryAll("
                SELECT DISTINCT t.id, SUM(errors) errors, t.name, t.validated, t.identifier, t.waiting, MAX(t.create_date_time) as create_date_time
                FROM
                (
                            
                    SELECT DISTINCT s.id, s.identifier, COUNT(s.id) errors, s.name, s.validated, s.waiting, 1 as type, MAX(v.createDateTime) as create_date_time
                    FROM substances s
                    JOIN validations v ON v.id_substance_1 = s.id
                    WHERE s.validated != ?
                    GROUP BY id
                    
                    UNION
                    
                    SELECT DISTINCT s.id, s.identifier, COUNT(s.id) errors, s.name, s.validated, s.waiting, 2 as type, MAX(e.last_update) as create_date_time
                    FROM substances s
                    JOIN scheduler_errors e ON e.id_substance = s.id
                    WHERE s.validated != ?
                    GROUP BY id
                ) t 
                GROUP BY id
                ORDER BY create_date_time, waiting DESC
                $limit_str
            ", array(self::VALIDATED, self::VALIDATED));
        }
        else
        {
            return $this->queryAll("
                SELECT DISTINCT t.id, SUM(errors) errors, t.name, t.validated, t.identifier, t.waiting, MAX(t.create_date_time) as create_date_time
                FROM
                (
                            
                    SELECT DISTINCT s.id, s.identifier, COUNT(s.id) errors, s.name, s.validated, 1 as type, s.waiting, MAX(v.createDateTime) as create_date_time
                    FROM substances s
                    JOIN validations v ON v.id_substance_1 = s.id
                    WHERE s.validated = ?
                    GROUP BY id
                    
                    UNION
                    
                    SELECT DISTINCT s.id, s.identifier, COUNT(s.id) errors, s.name, s.validated, 2 as type, s.waiting, MAX(e.last_update) as create_date_time
                    FROM substances s
                    JOIN scheduler_errors e ON e.id_substance = s.id
                    WHERE s.validated = ?
                    GROUP BY id
                ) t 
                GROUP BY id
                ORDER BY create_date_time, waiting DESC
                $limit_str
            ", array($state, $state));
        }
    }

    /**
     * Get validator data for substance id
     * 
     * @param int $id
     */
    public function get_substance_detail($id)
    {
        return $this->queryAll('
        SELECT *
        FROM
        (    
            SELECT s.*, v.id_substance_2, s2.identifier as identifier_2, v.duplicity COLLATE utf8_general_ci as duplicity, v.createDateTime as datetime
            FROM substances s
            JOIN validations v ON v.id_substance_1 = s.id
            JOIN substances s2 ON s2.id = v.id_substance_2
            WHERE s.id = ? AND v.active = 1

            UNION 

            SELECT s.*, NULL as id_substance_2, NULL as identifier_2, e.error_text COLLATE utf8_general_ci as duplicity, e.last_update as datetime
            FROM substances s
            JOIN scheduler_errors e ON e.id_substance = s.id
            WHERE s.id = ?
        ) t
        ORDER BY datetime DESC
        ', array($id, $id));
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
     * 
     */
    public function get_inter_dataset_duplicity($dataset_id)
    {
        return $this->queryAll('
            SELECT *
            FROM validations
            WHERE id_substance_1 IN (
                SELECT DISTINCT i.id_substance
                FROM interaction i
                JOIN datasets d ON d.id = i.id_dataset AND d.id = ?
                ) AND id_substance_2 IN (
                SELECT DISTINCT i.id_substance
                FROM interaction i
                JOIN datasets d ON d.id = i.id_dataset AND d.id = ?
                ) AND active = 1
        ', array($dataset_id, $dataset_id));
    }

    /**
     * 
     */
    public function get_inter_dataset_duplicity_count($dataset_id)
    {
        return $this->queryOne('
            SELECT COUNT(*) as count
            FROM validations
            WHERE id_substance_1 IN (
                SELECT DISTINCT i.id_substance
                FROM interaction i
                JOIN datasets d ON d.id = i.id_dataset AND d.id = ?
                ) AND id_substance_2 IN (
                SELECT DISTINCT i.id_substance
                FROM interaction i
                JOIN datasets d ON d.id = i.id_dataset AND d.id = ?
                ) AND active = 1
        ', array($dataset_id, $dataset_id))->count;
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


    /**
     * Deletes all links on old molecule
     *
     * @param int $id_substance
     */    
    public function delete_links_on_substance($id_substance)
    {
        $this->query('
            DELETE FROM `validations`
            WHERE `id_substance_1` = ? OR `id_substance_2` = ?
        ', array($id_substance, $id_substance));
    }

    /**
     * Deletes all substance links
     * 
     * @param int $id_substance
     */
    public function delete_substance_links($id_substance)
    {
        $this->query('
            DELETE FROM `validations`
            WHERE `id_substance_1` = ?
        ', array($id_substance));
    }

    /**
     * #####################################################################
     * #####################################################################
     * ##### SHORTCUTS FOR CALLING VALIDATION_STATE METHODS ################
     * #####################################################################
     * #####################################################################
     */

    /**
     * Returns molecule's fragmentation records
     * 
     * @param Susbtances $substance
     * 
     * @return Validator_fragmentation[]
     */
    public static function get_fragmentation_records($substance)
    {
        return Validator_state::get_fragmentation_records($substance);
    }

    /**
     * Returns all molecule's structure validation records
     * 
     * @param Substances $substance
     * 
     * @return Validator_3D_structure[]|null - Null if error occured
     */
    public static function get_structure_records($substance)
    {
        return Validator_state::get_structure_records($substance);
    }

    /**
     * Returns all molecule changes for given attribute
     * 
     * @param Substances $substance
     * @param int $attribute_flag
     * 
     * @return Iterable_object[]
     * @throws Exception
     */
    public static function get_atribute_changes($substance, $attribute_flag)
    {
        return Validator_state::get_atribute_changes($substance, $attribute_flag);
    }

    /**
     * Checks, if substance has given value for given attribute already
     * 
     * @param Substances $substance
     * @param int $attribute_flag
     * @param string $value
     * 
     * @return Iterable_object[]
     * @throws Exception
     */
    public static function had_attribute_value($substance, $attribute_flag, $value)
    {
        return Validator_state::had_attribute_value($substance, $attribute_flag, $value);
    }

    /**
     * #####################################################################
     * #####################################################################
     * ############################ END ####################################
     * #####################################################################
     * #####################################################################
     */
}