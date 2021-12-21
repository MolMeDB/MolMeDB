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
    private $prep_params;
    private $group_nrs;

    /**
     * @var Validator_value
     */
    protected $params;

    /**
     * @var Validator
     */
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
    const FLAG_SOURCE         = 10;
    const FLAG_FILE           = 11;
    const FLAG_DESCRIPTION    = 12;

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
        self::FLAG_LOGP       => 'LogP',
        self::FLAG_SOURCE     => 'source',
        self::FLAG_FILE       => 'file',
        self::FLAG_DESCRIPTION => 'group description'
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
    const VAR_DESCRIPTION               = '@DESCRIPTION';


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
     * Returns string value of given record
     * 
     * @param int $group_number - If not set, returns last message
     * 
     * @return string
     */
    public function __toStr()
    {
        $this->prepare_message();
        $this->prepare_params();

        if(!isset($this->prep_params[$group]))
        {
            return '';
        }

        // Fill variables
        preg_match_all(self::PARAM_REGEXP, $this->message, $matches);

        if(count($matches) && $group)
        {
            $params = $this->prep_params;

            foreach($matches[0] as $m)
            {
                $m = ltrim(rtrim($m, ']'), '[');
                $replace_text = '{INVALID}';
                switch($m)
                {
                    case self::VAR_SUBSTANCE1_LINK:
                        if(isset($params['substance_1']))
                        {
                            $s = $params['substance_1'];
                            $replace_text = Html::anchor('mol/' . $s->identifier, $s->identifier, TRUE);
                        }
                        break;

                    case self::VAR_SUBSTANCE2_LINK:
                        if(isset($params['substance_2']))
                        {
                            $s = $params['substance_2'];
                            $replace_text = Html::anchor('mol/' . $s->identifier, $s->identifier, TRUE);
                        }
                        break;

                    case self::VAR_SUBSTANCE1_NAME:
                        if(isset($params['substance_1']))
                        {
                            $s = $params['substance_1'];
                            $replace_text = $s->name;
                        }
                        break;

                    case self::VAR_SUBSTANCE2_NAME:
                        if(isset($params['substance_2']))
                        {
                            $s = $params['substance_2'];
                            $replace_text = $s->name;
                        }
                        break;

                    case self::VAR_SUBSTANCE1_IDENTIFIER:
                        if(isset($params['substance_1']))
                        {
                            $s = $params['substance_1'];
                            $replace_text = $s->identifier;
                        }
                        break;

                    case self::VAR_SUBSTANCE2_IDENTIFIER:
                        if(isset($params['substance_2']))
                        {
                            $s = $params['substance_2'];
                            $replace_text = $s->identifier;
                        }
                        break;
                    
                    case self::VAR_DB_VALUE:
                        $t = &$params[$group];

                        if(isset($t['value']))
                        {
                            $replace_text = "`" . array_shift($t['value'])['value'] . "`";
                        }
                        break;
                    
                    case self::VAR_DESCRIPTION:
                        $t = &$params[$group];

                        if(isset($t[self::FLAG_DESCRIPTION]) && count($t[self::FLAG_DESCRIPTION]))
                        {
                            $replace_text = "`" . array_shift($t[self::FLAG_DESCRIPTION])['description'] . "`";
                        }
                        break;

                    case self::VAR_DB_IDENTIFIER_FLAG_VALUE:
                        $r = self::get_identifier_flag_value($params[$group]);

                        if(is_array($r))
                        {
                            $replace_text = "`" . self::$enum_flags[$r['flag']] . "` = `" . $r['value'] . "`";
                        }

                        break;

                    case self::VAR_FILE:
                        $t = &$params[$group];

                        if(isset($t[self::FLAG_FILE]))
                        {
                            $f = new File(array_shift($t[self::FLAG_FILE])['description']);

                            if($f->exists())
                            {
                                $replace_text = Html::anchor($f->path, 'FILE', TRUE);
                            }
                            else
                            {
                                $replace_text = '`FILE NOT FOUND`';
                            }
                        }
                        break;

                    case self::VAR_SOURCE:
                        $t = &$params[$group];

                        if(isset($t[self::FLAG_SOURCE]))
                        {
                            $t = array_shift($t[self::FLAG_SOURCE]);

                            if($t['description'] && Url::is_valid($t['description']))
                            {
                                $replace_text = Html::anchor($t['description'], self::get_enum_source($t['value']), TRUE); 
                            }
                            else
                            {
                                $replace_text = self::get_enum_source($t['value']);
                            }
                        }
                        break;

                    default:
                        break;
                }
                $this->message = preg_replace(self::PARAM_REGEXP, $replace_text, $this->message, 1);
            }
        }   

        if(strlen($this->record->description))
        {
            $this->message = $this->message . ' Record info: ' . $this->record->description;
        }

        return $this->message;
    }

    /**
     * Prints type message for given record
     * 
     * @return string
     */
    public function __toString()
    {
        // Prints only the newest message
        return $this->__toStr();
    }

    /**
     * Prepares message to print
     */
    private function prepare_message()
    {
        $msg = $this->message;

        if(isset($this->message_params_types))
        {
            foreach($this->message_params_types as $type)
            {
                $msg = preg_replace('/\[@\]/', "[$type]", $msg, 1);
            }
        }

        // Other are invalid
        $msg = preg_replace('/\[@\]/', "[@INVALID]", $msg);

        $this->message = $msg;
    }

    /**
     * Prepares parameters to result message
     */
    private function prepare_params()
    {
        if($this->prep_params)
        {
            return;
        }

        $result = [];

        if($this->record && $this->record->id)
        {
            $result['substance_1'] = (object)array
            (
                'id'            => $this->record->substance_1->id,
                'identifier'    => $this->record->substance_1->identifier,
                'name'          => $this->record->substance_1->name
            );

            if($this->record->substance_2)
            {
                $result['substance_2'] = (object)array
                (
                    'id'            => $this->record->substance_2->id,
                    'identifier'    => $this->record->substance_2->identifier,
                    'name'          => $this->record->substance_2->name,
                );
            }
        }

        $this->group_nrs = [];

        foreach($this->params as $p)
        {
            $this->group_nrs[] = $p->group_nr;

            if(!$p->group_nr)
            {
                continue;
            }

            if($p->flag)
            {
                $k = $p->flag;
            }
            else
            {
                $k = 'value';
            }

            $g = 'g_' . $p->group_nr;

            if(!array_key_exists($g, $result))
            {
                $result[$g] = [];
            }

            if(!array_key_exists($k, $result[$g]))
            {
                $result[$g][$k] = [];
            }

            $result[$g][$k][] = array
            (
                'value' => $p->value,
                'description' => $p->description,
                'flag'      => $p->flag,
                'order_nr'  => $p->order_nr
            );
        }

        $this->group_nrs = array_unique($this->group_nrs, SORT_NUMERIC);
        $this->prep_params = $result;
    }

    /**
     * Returns current identifier flag value
     * 
     * @param array|object $data
     * 
     * @return array
     */
    private static function get_identifier_flag_value(&$data)
    {
        $temp = null;
        $min = 9999999;
        $f = $k = null;

        foreach($data as $flag => $rows)
        {
            foreach($data[$flag] as $key => $row)
            {
                if($row['order_nr'] < $min && self::is_valid_flag($flag))
                {
                    $min = $row['order_nr'];
                    $temp = $row;
                    $f=$flag;
                    $k=$key;
                }
            }
        }

        if(!$temp)
        {
            return null;
        }

        unset($data[$f][$k]);

        if(!count($data[$f]))
        {
            unset($data[$f]);
        }

        return array
        (
            'flag'  => $f,
            'value' => $temp['value'],
            'description' => $temp['description']
        );
    }

    /**
     * Adds source value
     * 
     * @param int $validation_id
     * @param int $group_nr
     * @param string|int|double $value
     * @param string $url
     * 
     * @return Validator_value
     */
    public static function add_source($validation_id, $group_nr, $value, $url = NULL)
    {
        if(!$value)
        {
            throw new Exception("Invalid validation value.");
        }

        $v = new Validator_value();
        $v->id_validation = $validation_id;
        $v->group_nr = $group_nr;
        $v->value = $value;
        $v->description = $url;
        $v->flag = self::FLAG_SOURCE;
        $v->save();

        return $v;
    }

    /**
     * Adds group description
     * 
     * @param int $validation_id
     * @param int $group_nr
     * @param string $description
     * 
     * @return Validator_value
     */
    public static function add_group_description($validation_id, $group_nr, $description)
    {
        if(!$description)
        {
            return false;
        }

        $v = new Validator_value();
        $v->id_validation = $validation_id;
        $v->group_nr = $group_nr;
        $v->value = NULL;
        $v->description = $description;
        $v->flag = self::FLAG_DESCRIPTION;
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
        if(!$substance || !$substance->id)
        {
            throw new Exception('Invalid substance.');
        }

        if(!self::is_valid_flag($attribute_flag))
        {
            throw new Exception('Invalid attribute flag.');
        }

        // Get all changes
        $rows = Validator::instance()->where(
            array
            (
                'id_substance_1'    => $substance->id,
                'type'              => self::TYPE_VALUE_EDITED
            ))
            ->get_all();

        $result = [];

        foreach($rows as $r)
        {
            if(count($r->values) != 3)
            {
                continue;
            }

            $val1 = $r->values[0];

            if(!$val1 || $val1->flag != $attribute_flag)
            {
                continue;
            }

            $result[] = array
            (
                'old'   => $r->values[0]->value,
                'new'   => $r->values[1]->value,
            );
        }

        return $result;
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
        if(!$substance || !$substance->id)
        {
            throw new Exception('Invalid substance.');
        }

        if(!self::is_valid_flag($attribute_flag))
        {
            throw new Exception('Invalid attribute flag.');
        }

        $rows = Validator::instance()->queryAll('
            SELECT *
            FROM validations v
            JOIN validation_values vv ON vv.id_validation = v.id
            WHERE v.id_substance_1 = ? AND v.type = ? 
                AND vv.flag = ? AND vv.value = ?
        ', array($substance->id, self::TYPE_VALUE_EDITED, $attribute_flag, $value));

        return count($rows) ? TRUE : FALSE;
    }
}