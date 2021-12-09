<?php

/**
 * Class for handling validator DUPLICITY state items
 * 
 * @author Jakub Juracka
 */
class Validator_duplicity extends Validator_state
{
    /** Duplicity TYPES */
    const DUPLICITY_PUBCHEM = 1;
    const DUPLICITY_CHEMBL = 2;
    const DUPLICITY_SMILES = 3;
    const DUPLICITY_DRUGBANK = 4;
    const DUPLICITY_CHEBI = 5;
    const DUPLICITY_PDB = 6;
    const DUPLICITY_NAME = 7;
    const DUPLICITY_INCHIKEY = 8;

    /**
     * Duplicity links
     */
    private static $duplicity_links = array
    (
        self::DUPLICITY_CHEMBL   => self::FLAG_CHEMBL,
        self::DUPLICITY_CHEBI    => self::FLAG_CHEBI,
        self::DUPLICITY_DRUGBANK => self::FLAG_DRUGBANK,
        self::DUPLICITY_PUBCHEM  => self::FLAG_PUBCHEM,
        self::DUPLICITY_PDB      => self::FLAG_PDB,
        self::DUPLICITY_SMILES   => self::FLAG_SMILES,
        self::DUPLICITY_NAME     => self::FLAG_NAME,
        self::DUPLICITY_INCHIKEY => self::FLAG_INCHIKEY
    );

    /**
     * Object handler
     */
    protected $record;

    /**
     * VALIDATOR TYPE
     */
    const TYPE = parent::TYPE_DUPLICITY;

    /**
     * Printable message
     */
    protected $message = "Found possible duplicity between molecules [@] and [@]. Duplicate [@].";

    /** 
     * Specify message params types
     */
    protected $message_params_types = array
    (
        self::VAR_SUBSTANCE1_LINK,
        self::VAR_SUBSTANCE2_LINK,
        self::VAR_DB_IDENTIFIER_FLAG_VALUE
    );

    /**
     * Message parameters
     * @var Validation_values[]
     */
    protected $params = [];

    /**
     * Checks, if ducplicity type is valid
     * 
     * @param int $dupl_type
     * 
     * @return boolean
     */
    public static function is_valid_type($dupl_type)
    {
        return array_key_exists($dupl_type, self::$duplicity_links);
    }

    /**
     * Constructor
     * 
     * @param Validator $record
     */
    public function __construct($record = NULL)
    {
        if($record->id && $record->type == self::TYPE)
        {
            $this->params = $record->values;
            $this->record = $record;
        }
    }

    /**
     * Adds new duplicity info
     * 
     * @param Substances $substance_1
     * @param Substances $substance_2
     * @param int $type
     * @param string $dup_value
     * @param string|null $additional_info
     * 
     * @return Validator - Returns instance of Validator record
     * 
     * @author Jakub Juracka
     */
    public static function add($substance_1, $substance_2, $type, $dup_value, $additional_info = NULL)
    {
        if(!self::is_valid_type($type))
        {
            throw new Exception('Invalid duplicity type.');
        }

        if(!$substance_1->id || 
            !$substance_2->id || 
            $substance_1->id === $substance_2->id)
        {
            throw new Exception('Invalid substance records.');
        }

        if(!$dup_value)
        {
            throw new Exception('Empty duplicity value.');
        }

        $record = new Validator();

        try
        {
            $record->beginTransaction();

            $record->id_substance_1 = $substance_1->id;
            $record->id_substance_2 = $substance_2->id;
            $record->type = self::TYPE;
            $record->state = Validator::STATE_OPENED;
            $record->description = $additional_info;

            $record->save();

            // Add param
            $r = new Validator_value();

            $r->id_validation = $record->id;
            $r->value = $dup_value;
            $r->flag = self::$duplicity_links[$type];

            $r->save();

            $record->commitTransaction();
        }
        catch(Exception $e)
        {
            $record->rollbackTransaction();
            throw new Exception($e->getMessage());
        }

        return new Validator_duplicity(new Validator($record->id));
    }
}