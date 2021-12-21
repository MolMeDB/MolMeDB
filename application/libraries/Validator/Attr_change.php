<?php

/**
 * Class for handling validator VALUE EDIT state items
 * 
 * @author Jakub Juracka
 */
class Validator_attr_change extends Validator_state
{
    /**
     * Object handler
     */
    protected $record;

    /**
     * VALIDATOR TYPE
     */
    const TYPE = parent::TYPE_VALUE_EDITED;

    /**
     * Printable message
     */
    protected $message = "Value edited. Old [@], New [@], Source [@].";

    /** 
     * Specify message params types
     */
    protected $message_params_types = array
    (
        self::VAR_DB_IDENTIFIER_FLAG_VALUE,
        self::VAR_DB_IDENTIFIER_FLAG_VALUE,
        self::VAR_SOURCE
    );

    /**
     * Message parameters
     * @var Validation_values[]
     */
    protected $params = [];

    /**
     * Constructor
     * 
     * @param Validator $record
     */
    public function __construct($record = NULL)
    {
        if($record && $record->id && $record->type == self::TYPE)
        {
            $this->params = $record->values;
            $this->record = $record;
        }
    }

    /**
     * Adds new record
     * 
     * @param Substances $substance
     * @param int $attr_flag
     * @param string $value - If not set, old value is loaded from $substance->old_data and new from $subtance->[attribute]
     * @param int $source
     * @param string $source_detail
     * @param string|null $additional_info
     * 
     * @return Validator_attr_change - Returns instance of Validator attribute change record
     * 
     * @author Jakub Juracka
     */
    public static function add($substance, $attr_flag, $value = FALSE, $source = self::SOURCE_USER_INPUT, $source_detail = NULL, $additional_info = NULL)
    {
        if(!self::is_valid_flag($attr_flag))
        {
            throw new Exception('Invalid attribute.');
        }

        if(!self::is_valid_source($source))
        {
            throw new Exception('Invalid source type.');
        }

        if(!$substance->id)
        {
            throw new Exception('Invalid substance record.');
        }

        // Get old value
        switch($attr_flag)
        {
            case self::FLAG_LOGP:
                $old_value = $substance->old_data['LogP'];
                $value = $value === FALSE ? $substance->LogP : $value;
                break;
            case self::FLAG_NAME:
                $old_value = $substance->old_data['name'];
                $value = $value === FALSE ? $substance->name : $value;
                break;
            case self::FLAG_CHEBI:
                $old_value = $substance->old_data['chEBI'];
                $value = $value === FALSE ? $substance->chEBI : $value;
                break;
            case self::FLAG_CHEMBL:
                $old_value = $substance->old_data['chEMBL'];
                $value = $value === FALSE ? $substance->chEMBL : $value;
                break;
            case self::FLAG_INCHIKEY:
                $old_value = $substance->old_data['inchikey'];
                $value = $value === FALSE ? $substance->inchikey : $value;
                break;
            case self::FLAG_PDB:
                $old_value = $substance->old_data['pdb'];
                $value = $value === FALSE ? $substance->pdb : $value;
                break;
            case self::FLAG_PUBCHEM:
                $old_value = $substance->old_data['pubchem'];
                $value = $value === FALSE ? $substance->pubchem : $value;
                break;
            case self::FLAG_SMILES:
                $old_value = $substance->old_data['SMILES'];
                $value = $value === FALSE ? $substance->SMILES : $value;
                break;
            case self::FLAG_DRUGBANK:
                $old_value = $substance->old_data['drugbank'];
                $value = $value === FALSE ? $substance->drugbank : $value;
                break;
        }

        // No change? Return...
        if($old_value === $value)
        {
            return TRUE;
        }

        $record = new Validator();

        try
        {
            $record->beginTransaction();

            // exists?
            $record = $record->where(array
            (
                'id_substance_1'    => $substance->id,
                'type'              => self::TYPE,
            ))->get_one();

            if(!$record || !$record->id)
            {
                $record->id_substance_1 = $substance->id;
                $record->id_substance_2 = NULL;
                $record->type = self::TYPE;
                $record->state = Validator::STATE_OPENED;
                $record->description = $additional_info;

                $record->save();
            }
            else
            {
                $record->dateTime = date('Y-m-d H:i:s');
                $record->state = Validator::STATE_OPENED;
                $record->save();
            }

            // Add old value info
            $r = new Validator_value();
            $r->id_validation = $record->id;
            $r->value = $old_value;
            $r->flag = $attr_flag;
            $r->save();

            // Add new value info
            $r2 = new Validator_value();
            $r2->id_validation = $record->id;
            $r2->group_nr = $r->group_nr;
            $r2->value = $value;
            $r2->flag = $attr_flag;
            $r2->save();

            // Add source info
            self::add_source($record->id, $r->group_nr, $source, $source_detail);

            $record->commitTransaction();
        }
        catch(Exception $e)
        {
            $record->rollbackTransaction();
            throw new Exception($e->getMessage());
        }

        return new Validator_attr_change(new Validator($record->id));
    }
}