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
     * @param int $source
     * @param string $source_detail
     * @param string|null $additional_info
     * 
     * @return Validator - Returns instance of Validator record
     * 
     * @author Jakub Juracka
     */
    public static function add($substance_1, $substance_2, $type, $dup_value, $source, $source_detail = NULL, $additional_info = NULL)
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

        if(!self::is_valid_source($source))
        {
            throw new Exception('Invalid source.');
        }

        $record = new Validator();

        try
        {
            $record->beginTransaction();

            // Check if exists
            $record = $record->where(array
                (
                    'id_substance_1'    => $substance_1->id,
                    'id_substance_2'    => $substance_2->id,
                    'type'              => self::TYPE,
                ))
                ->get_one();

            if($record && $record->id)
            {
                $record->dateTime = date('Y-m-d H:i:s');
                $record->state = Validator::STATE_OPENED;
                $record->save();
            }
            else
            {
                $record->id_substance_1 = $substance_1->id;
                $record->id_substance_2 = $substance_2->id;
                $record->type = self::TYPE;
                $record->state = Validator::STATE_OPENED;
                $record->description = $additional_info;
                $record->save();
            }

            // Check, if record already exists
            $r = Validator_value::instance()->queryOne('
                SELECT *
                FROM(
                    SELECT id, COUNT(id) as c, group_nr
                    FROM validation_values
                    WHERE id_validation = ? AND 
                        ((value LIKE ? AND flag = ?) 
                        OR (value = ? AND flag = ? AND description LIKE ?))
                    GROUP BY group_nr) as tab
                WHERE c = 2
                LIMIT 1
            ', array
            (
               $record->id,
               $dup_value,
               self::$duplicity_links[$type],
               $source,
               self::FLAG_SOURCE,
               $source_detail
            ));

            if(!$r || !$r->id)
            {
                // Add duplicity value
                $r = new Validator_value();
                $r->id_validation = $record->id;
                $r->value = $dup_value;
                $r->flag = self::$duplicity_links[$type];
                $r->save();

                // Add source info
                self::add_source($record->id, $r->group_nr, $source, $source_detail);
            }
            else // Update datetime
            {
                Validator_value::instance()->query('
                    UPDATE validation_values
                    SET dateTime = ?
                    WHERE id_validation = ? AND group_nr = ?
                ', array(date('Y-m-d H:i:s'), $record->id, $r->group_nr));
            }

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