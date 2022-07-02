<?php

/**
 * Validator identifiers logs
 * 
 * @property int $id
 * @property int $id_substance
 * @property Substances $substance
 * @property int $type
 * @property int $state
 * @property string $error_text
 * @property int $error_count
 * @property boolean $ignore_next
 * @property string $datetime
 * 
 * @author Jakub Juracka
 */
class Validator_identifier_logs extends Db
{
    /**
     * Constructor
     * 
     * @param int $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'validator_identifiers_logs';
        parent::__construct($id);
    }

    /** TYPES */
    const TYPE_LOCAL_IDENTIFIERS = 1;
    const TYPE_SMILES = 2;
    const TYPE_INCHIKEY = 3;
    const TYPE_LOGP_MW = 4;
    const TYPE_REMOTE_IDENTIFIERS = 5;
    const TYPE_3D_STRUCTURE = 6;
    const TYPE_NAME_BY_IDENTIFIERS = 7;
    const TYPE_TITLE = 8;

    /** STATES */
    const STATE_WAITING = 0; // Adds 1000 score
    const STATE_DONE = 1; // Adds 0 score
    const STATE_ERROR = 2; // Adds 100 score
    const STATE_MULTIPLE_ERROR = 3; // Adds 0 score
    const STATE_PROCESSING = 5; // Adds 10 score - Same as NO-STATE

    /**
     * Holds all types
     */
    private static $valid_types = array
    (
        self::TYPE_LOCAL_IDENTIFIERS,
        self::TYPE_SMILES,
        self::TYPE_INCHIKEY,
        self::TYPE_LOGP_MW,
        self::TYPE_REMOTE_IDENTIFIERS,
        self::TYPE_3D_STRUCTURE,
        self::TYPE_NAME_BY_IDENTIFIERS,
    );

    /**
     * Links to other tables
     */
    protected $has_one = array
    (
        'id_substance'
    );

    /**
     * Checks, if log should be processed
     * 
     * @return boolean
     */
    public function is_done()
    {
        return $this && $this->id && $this->state === self::STATE_DONE;
    }


    /**
     * Returns current validation step by type
     * 
     * @param int $id_substance
     * @param int $type
     * 
     * @return Validator_identifier_logs
     */
    public function get_current_by_type($id_substance, $type)
    {
        if(!$id_substance || !in_array($type, self::$valid_types))
        {
            return new Validator_identifier_logs();
        }

        return $this->where(array
            (
                'id_substance'  => $id_substance,
                'type'          => $type
            ))
            ->order_by('datetime', 'DESC')
            ->get_one();
    }


    /**
     * Updates state of current object
     * 
     * @param int $id_substance
     * @param int $type
     * @param int $new_state
     */
    public function update_state($id_substance, $type, $new_state, $err_text = NULL)
    {
        $obj = $this->get_current_by_type($id_substance, $type);

        $obj->id_substance = $id_substance;
        $obj->type = $type;
        $obj->state = $new_state;

        if($new_state == self::STATE_ERROR || $new_state == self::STATE_MULTIPLE_ERROR)
        {
            $current = $obj->error_count ? $obj->error_count+1 : 1;

            $obj->error_text = $err_text;
            $obj->error_count = $current;
            $obj->state = $current > 2 ? self::STATE_MULTIPLE_ERROR : self::STATE_ERROR;
        }
        else
        {
            $obj->error_text = NULL;
            $obj->error_count = NULL;
        }

        $obj->datetime = date('Y-m-d H:i:s');
        $obj->save();

        return;
    }

    /**
     * Finds substances for next validatation run
     * 
     * @param int $limit
     * @param int $offset
     * @param bool $include_with_errors
     * @param bool $include_ignored
     * 
     * @return Substances[]
     */
    public function get_substances_for_validation($limit, $offset = 0, $include_with_errors = TRUE, $include_ignored = FALSE)
    {
        $select = 's.id, (';
        $joins = '';

        $s_error = self::STATE_ERROR;
        $s_fatal_error = self::STATE_MULTIPLE_ERROR;
        $s_waiting = self::STATE_WAITING;
        $s_process = self::STATE_PROCESSING;

        foreach(self::$valid_types as $type)
        {
            if($include_with_errors && $include_ignored)
            {
                $select .= "
                    IF(tab_$type.id IS NULL OR tab_$type.state = $s_process, 10, IF(tab_$type.state = $s_error, 100, IF(tab_$type.state = $s_fatal_error, 100, IF(tab_$type.state = $s_waiting, 1000, 0)))) +
                ";
            }
            else if($include_with_errors)
            {
                $select .= "
                    IF(tab_$type.id IS NULL OR tab_$type.state = $s_process, 10, IF(tab_$type.state = $s_error, 100, IF(tab_$type.state = $s_waiting, 1000, 0))) +
                ";
            }
            else if($include_ignored)
            {
                $select .= "
                    IF(tab_$type.id IS NULL OR tab_$type.state = $s_process, 10, IF(tab_$type.state = $s_fatal_error, 100, IF(tab_$type.state = $s_waiting, 1000, 0))) +
                ";
            }
            else
            {
                $select .= "
                    IF(tab_$type.id IS NULL OR tab_$type.state = $s_process, 10, IF(tab_$type.state = $s_waiting, 1000, 0)) +
                ";
            }

            $joins .= ' LEFT JOIN (
                SELECT *
                FROM validator_identifiers_logs_newest
                WHERE type = ' . $type .
                ') tab_' . $type . " ON tab_$type.id_substance = s.id";
        }

        $select = preg_replace('/\+\s*$/', '', $select);
        $select .= ') as score';

        $select .= ', GREATEST(IFNULL(tab_1.datetime,"0000-00-00 00:00:00"),
            IFNULL(tab_2.datetime,"0000-00-00 00:00:00"),
            IFNULL(tab_3.datetime,"0000-00-00 00:00:00"),
            IFNULL(tab_4.datetime,"0000-00-00 00:00:00"),
            IFNULL(tab_5.datetime,"0000-00-00 00:00:00"),
            IFNULL(tab_6.datetime,"0000-00-00 00:00:00"),
            IFNULL(tab_7.datetime,"0000-00-00 00:00:00")) as dt ';

        // echo "SELECT * 
        // FROM
        // (
        //     SELECT $select
        //     FROM substances s
        //     $joins
        // ) as tab
        // WHERE tab.score > 0
        // ORDER BY score DESC, dt ASC
        // LIMIT $limit OFFSET $offset";
        // die;

        $data = $this->queryAll(" SELECT * 
            FROM
            (
                SELECT $select
                FROM substances s
                $joins
            ) as tab
            WHERE tab.score > 0
            ORDER BY score DESC, dt ASC
            LIMIT $limit OFFSET $offset
        ");

        $result = [];

        foreach($data as $row)
        {
            $result[] = new Substances($row->id);
        }

        return $result;
    }
}