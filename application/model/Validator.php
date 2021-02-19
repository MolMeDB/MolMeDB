<?php

/**
 * Validator class
 * 
 * @property int $id
 * @property int $id_substance_1
 * @property int $id_substance_2
 * @property int $duplicity
 * @property int $active
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

    public static $enum_states = array
    (
        self::NOT_VALIDATED => 'Not validated',
        self::SUBSTANCE_FILLED => 'Missing values filled',
        self::IDENTIFIERS_FILLED => 'Identifiers filled',
        self::POSSIBLE_DUPLICITY => 'Possible duplicity',
        self::LogP_FILLED => 'LogP filled',
        self::VALIDATED => 'Validated'
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
}