<?php

/**
 * Main abstract class for holding required functionality of validator state classes
 * 
 * @author Jakub Juracka 
 */
abstract class Validator_state
{
    /**
     * REGEXP for finding params in text
     */
    const PARAM_REGEXP = '/\[@[^\]\s\.,]+\]/';

    /**
     * Required parameters for each validator state
     */
    private $count_params_required;
    protected $message;
    protected $message_params_types;
    protected $params;
    protected $record;

    /**
     * GLOBAL record types
     */
    const TYPE_DUPLICITY            = 1;
    const TYPE_VALUE_EDITED         = 2;
    const TYPE_FRAGMENTED           = 3;
    const TYPE_FRAGMENT_ERROR       = 4;
    const TYPE_3D_STRUCTURE_ADDED   = 5;
    const TYPE_3D_STRUCTURE_EDITED  = 6;

    /**
     * GLOBAL parameter flags
     */
    const FLAG_PUBCHEM        = 1;
    const FLAG_CHEMBL         = 2;
    const FLAG_PDB            = 3;
    const FLAG_CHEBI          = 4;
    const FLAG_DRUGBANK       = 5;
    const FLAG_SMILES         = 6;
    const FLAG_INCHIKEY       = 7;
    const FLAG_NAME           = 8;
    const FLAG_LOGP           = 9;

    /** 
     * Data sources 
     */
    const SOURCE_PUBCHEM    = 1;
    const SOURCE_CHEMBL     = 2;
    const SOURCE_USER_INPUT = 3;
    const SOURCE_DRUGBANK   = 4;
    const SOURCE_CHEBI      = 5;
    const SOURCE_PDB        = 6;
    const SOURCE_RDKIT      = 7;
    
    /**
     * Enum identifiers
     */
    private static $enum_flags = array
    (
        self::FLAG_PUBCHEM    => 'pubchem_id',
        self::FLAG_CHEMBL     => 'chembl_id',
        self::FLAG_PDB        => 'pdb_id',
        self::FLAG_CHEBI      => 'chebi_id',
        self::FLAG_DRUGBANK   => 'drugbank_id',
        self::FLAG_SMILES     => 'smiles',
        self::FLAG_INCHIKEY   => 'inchikey',
        self::FLAG_NAME       => 'name',
        self::FLAG_LOGP       => 'LogP'
    );

    /**
     * Enum sources
     */
    private static $enum_sources = array
    (
        self::SOURCE_CHEBI      => 'CHEBI',
        self::SOURCE_CHEMBL     => 'CHEMBL',
        self::SOURCE_PUBCHEM    => 'PUBCHEM',
        self::SOURCE_PDB        => 'PDB',
        self::SOURCE_DRUGBANK   => 'DRUGBANK',
        self::SOURCE_RDKIT      => 'RDKIT',
        self::SOURCE_USER_INPUT => 'USER_INPUT'
    );

    /**
     * SPECIAL VARIABLES which can be used for easy printing in message
     */
    const VAR_DB_VALUE                  = '@DB_VAL';
    const VAR_DB_IDENTIFIER_FLAG_VALUE  = '@DB_ID_VAL';
    const VAR_SUBSTANCE1_NAME           = '@SUBSTANCE_1_NAME';
    const VAR_SUBSTANCE1_IDENTIFIER     = '@SUBSTANCE_1_IDENTIFIER';
    const VAR_SUBSTANCE1_LINK           = '@SUBSTANCE_1_LINK';
    const VAR_SUBSTANCE2_NAME           = '@SUBSTANCE_2_NAME';
    const VAR_SUBSTANCE2_IDENTIFIER     = '@SUBSTANCE_2_IDENTIFIER';
    const VAR_SUBSTANCE2_LINK           = '@SUBSTANCE_2_LINK';
    const VAR_FILE                      = '@FILE';
    const VAR_SOURCE                    = '@SOURCE';


    /**
     * Returns enum source
     * 
     * @param int $source
     * 
     * @return string|null
     */
    public static function get_enum_source($source)
    {
        if(!self::is_valid_source($source))
        {
            return null;
        }
        return self::$enum_sources[$source];
    }

    /**
     * Checks, if source exists
     * 
     * @param int $source
     * 
     * @return boolean
     */
    public static function is_valid_source($source)
    {
        return array_key_exists($source, self::$enum_sources);
    }

    /**
     * Instance maker
     * 
     * @param int|Validator $r
     * 
     * @return Validator_fragmentation|null
     */
    public static function instance($r)
    {
        if($r && !($r instanceof Validator))
        {
            if($r instanceof Iterable_object && $r->id)
            {
                $record = new Validator($r->id);
            }
            else if(is_numeric($r))
            {
                $record = new Validator($r);
            }
        }
        else 
        {
            $record = $r;    
        }
        
        if(!$record || !$record->id)
        {
            return null;
        }

        if($record->type == self::TYPE_FRAGMENT_ERROR || 
            $record->type == self::TYPE_FRAGMENTED)
        {
            return new Validator_fragmentation($record);
        }
        else if($record->type == self::TYPE_DUPLICITY)
        {
            return new Validator_duplicity($record);
        }
        else if($record->type == self::TYPE_3D_STRUCTURE_ADDED || 
            $record->type == self::TYPE_3D_STRUCTURE_EDITED)
        {
            return new Validator_3D_structure($record);   
        }
        else if($record->type == self::TYPE_VALUE_EDITED)
        {
            return new Validator_attr_change($record);
        }
    }

    /**
     * Checks, if flag is valid
     * 
     * @param int $flag
     * 
     * @return boolean
     */
    public static function is_valid_flag($flag)
    {
        return array_key_exists($flag, self::$enum_flags);
    }

    /**
     * Prints type message for given record
     */
    public function __toString()
    {
        $this->prepare_message();

        // if(count($this->params) !== $this->count_params_required)
        // {
        //     $t = count($this->params);
        //     return "INVALID PARAMETER NUMBER: [Required: $this->count_params_required / Available: $t].";
        // }

        // Fill variables
        preg_match_all(self::PARAM_REGEXP, $this->message, $matches);

        if($this->params instanceof Iterable_object)
        {
            $params = $this->params->as_array();
        }
        else if(is_array($this->params))
        {
            $params = $this->params;
        }
        else
        {
            return 'CANNOT GENERATE INFO MESSAGE';
        }

        if(count($matches))
        {
            foreach($matches[0] as $m)
            {
                $m = ltrim(rtrim($m, ']'), '[');
                switch($m)
                {
                    case self::VAR_SUBSTANCE1_LINK:
                        if($this->record && $this->record->id_substance_1 && $this->record->substance_1->id)
                        {
                            $s = $this->record->substance_1;
                            $this->message = preg_replace(self::PARAM_REGEXP, Html::anchor('mol/' . $s->identifier, $s->identifier, TRUE), $this->message, 1);
                        }
                        else
                        {
                            return 'INVALID PARAMETER VALUE FOR PARAMETER ' . $m;
                        }
                        break;

                    case self::VAR_SUBSTANCE2_LINK:
                        if($this->record && $this->record->id_substance_2 && $this->record->substance_2->id)
                        {
                            $s = $this->record->substance_2;
                            $this->message = preg_replace(self::PARAM_REGEXP, Html::anchor('mol/' . $s->identifier, $s->identifier, TRUE), $this->message, 1);
                        }
                        else
                        {
                            return 'INVALID PARAMETER VALUE FOR PARAMETER ' . $m;
                        }
                        break;

                    case self::VAR_SUBSTANCE1_NAME:
                        if($this->record && $this->record->id_substance_1 && $this->record->substance_1->id)
                        {
                            $s = $this->record->substance_1;
                            $this->message = preg_replace(self::PARAM_REGEXP, $s->name, $this->message, 1);
                        }
                        else
                        {
                            return 'INVALID PARAMETER VALUE FOR PARAMETER ' . $m;
                        }
                        break;

                    case self::VAR_SUBSTANCE2_NAME:
                        if($this->record && $this->record->id_substance_2 && $this->record->substance_2->id)
                        {
                            $s = $this->record->substance_2;
                            $this->message = preg_replace(self::PARAM_REGEXP, $s->name, $this->message, 1);
                        }
                        else
                        {
                            return 'INVALID PARAMETER VALUE FOR PARAMETER ' . $m;
                        }
                        break;

                    case self::VAR_SUBSTANCE1_IDENTIFIER:
                        if($this->record && $this->record->id_substance_1 && $this->record->substance_1->id)
                        {
                            $s = $this->record->substance_1;
                            $this->message = preg_replace(self::PARAM_REGEXP, $s->identifier, $this->message, 1);
                        }
                        else
                        {
                            return 'INVALID PARAMETER VALUE FOR PARAMETER ' . $m;
                        }
                        break;

                    case self::VAR_SUBSTANCE2_IDENTIFIER:
                        if($this->record && $this->record->id_substance_2 && $this->record->substance_2->id)
                        {
                            $s = $this->record->substance_2;
                            $this->message = preg_replace(self::PARAM_REGEXP, $s->identifier, $this->message, 1);
                        }
                        else
                        {
                            return 'INVALID PARAMETER VALUE FOR PARAMETER ' . $m;
                        }
                        break;
                    
                    case self::VAR_DB_VALUE:
                        $t = array_shift($params);
                        $this->message = preg_replace(self::PARAM_REGEXP, "`" . $t['value'] . "`", $this->message, 1);
                        break;

                    case self::VAR_DB_IDENTIFIER_FLAG_VALUE:
                        $t = array_shift($params);
                        
                        if(!self::is_valid_flag($t['flag']))
                        {
                            return 'Invalid flag value.';
                        }

                        $text = "`" . self::$enum_flags[$t['flag']] . "` = `" . $t['value'] . "`";

                        $this->message = preg_replace(self::PARAM_REGEXP, $text, $this->message, 1);
                        break;
                    case self::VAR_FILE:
                        $t = array_shift($params);
                        $f = new File($t['value']);

                        if(!$f->exists())
                        {
                            $this->message = preg_replace(self::PARAM_REGEXP, '`FILE NOT FOUND`', $this->message, 1);
                        }
                        else
                        {
                            $this->message = preg_replace(self::PARAM_REGEXP, Html::anchor($f->path, 'FILE', TRUE), $this->message, 1);
                        }
                        break;

                    case self::VAR_SOURCE:
                        $t = array_shift($params);

                        if($t['description'] && Url::is_valid($t['description']))
                        {
                            $text = Html::anchor($t['description'], self::get_enum_source($t['value']), TRUE); 
                        }
                        else
                        {
                            $text = self::get_enum_source($t['value']);
                        }

                        $this->message = preg_replace(self::PARAM_REGEXP, $text, $this->message, 1);
                        break;

                    default:
                        $this->message = preg_replace(self::PARAM_REGEXP, "`INVALID`", $this->message, 1);
                        break;
                }
            }
        }   

        if(strlen($this->record->description))
        {
            $this->message = $this->message . ' Explanation: ' . $this->record->description;
        }

        return $this->message;
    }

    /**
     * Prepares message to print
     */
    private function prepare_message()
    {
        $msg = $this->message;
        $total_required_db_values = 0;

        if(isset($this->message_params_types))
        {
            foreach($this->message_params_types as $type)
            {
                // if($type == self::VAR_DB_VALUE || $type == self::VAR_DB_IDENTIFIER_FLAG_VALUE)
                // {
                //     $total_required_db_values++;
                // }

                $msg = preg_replace('/\[@\]/', "[$type]", $msg, 1);
            }
        }

        $this->count_params_required = $total_required_db_values;
        $this->message = $msg;
    }

    /**
     * Adds source value
     * 
     * @param int $validation_id
     * @param string|int|double $value
     * @param string $url
     * 
     * @return Validator_value
     */
    public static function add_source($validation_id, $value, $url = NULL)
    {
        if(!$value)
        {
            throw new Exception("Invalid validation value.");
        }

        $v = new Validator_value();
        $v->id_validation = $validation_id;
        $v->value = $value;
        $v->description = $url;
        $v->flag = NULL;
        $v->save();

        return $v;
    }

    /**
     * Returns molecule's fragmentation records
     * 
     * @param Susbtances $substance
     * 
     * @return Validator_fragmentation[]|null - Null if error
     */
    public static function get_fragmentation_records($substance)
    {
        if(!$substance || !$substance->id)
        {
            return null;
        }

        $rows = Validator::factory()->queryAll('
            SELECT *
            FROM validations
            WHERE id_substance_1 = ? AND (type = ? OR type = ?)
            ORDER BY dateTime DESC
        ', array($substance->id, self::TYPE_FRAGMENT_ERROR, self::TYPE_FRAGMENTED));

        $res = [];

        foreach($rows as $r)
        {
            $res[] = self::instance($r);
        }

        return $res;
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
        if(!$substance || !$substance->id)
        {
            return null;
        }

        $rows = Validator::factory()->queryAll('
            SELECT *
            FROM validations
            WHERE id_substance_1 = ? AND (type = ? OR type = ?)
            ORDER BY dateTime DESC
        ', array($substance->id, self::TYPE_3D_STRUCTURE_ADDED, self::TYPE_3D_STRUCTURE_EDITED));

        foreach($rows as $r)
        {
            $res[] = self::instance($r);
        }

        return $res;
    } 
}