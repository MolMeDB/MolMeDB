<?php

/**
 * Class for handling validator VALUE EDIT state items
 * 
 * @author Jakub Juracka
 */
class Validator_attr_change extends Validator_state
{
    /** value TYPES */
    const VALUE_PUBCHEM = 1;
    const VALUE_CHEMBL = 2;
    const VALUE_SMILES = 3;
    const VALUE_DRUGBANK = 4;
    const VALUE_CHEBI = 5;
    const VALUE_PDB = 6;
    const VALUE_NAME = 7;
    const VALUE_INCHIKEY = 8;
    const VALUE_LOGP = 9;

    /**
     * VALUE links
     */
    private static $value_links = array
    (
        self::VALUE_CHEMBL      => self::FLAG_CHEMBL,
        self::VALUE_CHEBI       => self::FLAG_CHEBI,
        self::VALUE_DRUGBANK    => self::FLAG_DRUGBANK,
        self::VALUE_PUBCHEM     => self::FLAG_PUBCHEM,
        self::VALUE_PDB         => self::FLAG_PDB,
        self::VALUE_SMILES      => self::FLAG_SMILES,
        self::VALUE_NAME        => self::FLAG_NAME,
        self::VALUE_INCHIKEY    => self::FLAG_INCHIKEY,
        self::VALUE_LOGP        => self::FLAG_LOGP
    );

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
     * Checks, if type is valid
     * 
     * @param int $type
     * 
     * @return boolean
     */
    public static function is_valid_type($type)
    {
        return array_key_exists($type, self::$value_links);
    }

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
     * @param int $type
     * @param string $value
     * @param int $source
     * @param string|null $additional_info
     * 
     * @return Validator_attr_change - Returns instance of Validator attribute change record
     * 
     * @author Jakub Juracka
     */
    public static function add($substance, $type, $value, $source, $additional_info = NULL)
    {
        if(!self::is_valid_type($type))
        {
            throw new Exception('Invalid edit type.');
        }

        if(!self::is_valid_source($source))
        {
            throw new Exception('Invalid source type.');
        }

        if(!$substance->id)
        {
            throw new Exception('Invalid substance record.');
        }

        if(!$value)
        {
            throw new Exception('Empty value.');
        }

        // Get old value
        switch($type)
        {
            case self::VALUE_LOGP:
                $old_value = $substance->LogP;
                break;
            case self::VALUE_NAME:
                $old_value = $substance->name;
                break;
            case self::VALUE_CHEBI:
                $old_value = $substance->chEBI;
                break;
            case self::VALUE_CHEMBL:
                $old_value = $substance->chEMBL;
                break;
            case self::VALUE_INCHIKEY:
                $old_value = $substance->inchikey;
                break;
            case self::VALUE_PDB:
                $old_value = $substance->pdb;
                break;
            case self::VALUE_PUBCHEM:
                $old_value = $substance->pubchem;
                break;
            case self::VALUE_SMILES:
                $old_value = $substance->SMILES;
                break;
            case self::VALUE_DRUGBANK:
                $old_value = $substance->drugbank;
                break;
            default:
                $old_value = NULL;
        }

        if(!$old_value || $old_value == $value)
        {
            $old_value = "[NULL]";
        }

        $record = new Validator();

        try
        {
            $record->beginTransaction();

            $record->id_substance_1 = $substance->id;
            $record->id_substance_2 = NULL;
            $record->type = self::TYPE;
            $record->state = Validator::STATE_OPENED;
            $record->description = $additional_info;

            $record->save();

            // Add old value info
            $r = new Validator_value();
            $r->id_validation = $record->id;
            $r->value = $old_value;
            $r->flag = self::$value_links[$type];
            $r->save();

            // Add new value info
            $r = new Validator_value();
            $r->id_validation = $record->id;
            $r->value = $value;
            $r->flag = self::$value_links[$type];
            $r->save();

            // Add source info
            $r = new Validator_value();
            $r->id_validation = $record->id;
            $r->value = $source;
            $r->save();

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