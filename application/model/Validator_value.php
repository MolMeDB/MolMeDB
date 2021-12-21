<?php

/**
 * Validator values class
 * 
 * @property int $id
 * @property int $id_validation
 * @property string $value
 * @property int $flag
 * @property int $order_nr
 * @property string $description
 * 
 * @author Jakub Juracka
 */
class Validator_value extends Db
{
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'validation_values';
        parent::__construct($id);
    }

    /**
     * Redefines save method for protection against missing required params
     * 
     * @author Jakub Juracka
     */
    public function save()
    {
        // Check, if order number is set
        if(!$this->order_nr)
        {
            $this->order_nr = self::generate_order_number($this->id_validation, $this->group_nr);
        }
        if(!$this->state)
        {
            $this->state = Validator::STATE_OPENED;
        }
        
        parent::save();
    }

    /**
     * Generates order number for new record
     * 
     * @param int $id_validation
     * 
     * @return int
     */
    public static function generate_order_number($id_validation)
    {
        if(!$id_validation)
        {
            throw new Exception('Cannot generate order number. Missing id_validation value.');
        }

        $t = self::instance()->queryOne('
            SELECT MAX(order_nr) as n
            FROM validation_values
            WHERE id_validation = ?
        ', array($id_validation));

        if(!$t || !$t->n)
        {
            return 1;
        }

        return $t->n+1;
    }

}