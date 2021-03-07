<?php

/**
 * Transporters model
 * 
 * @property integer $id
 * @property integer $id_dataset
 * @property integer $id_substance
 * @property integer $id_target
 * @property integer $type
 * @property string $note
 * @property float $Km
 * @property float $Km_acc
 * @property float $EC50
 * @property float $EC50_acc
 * @property float $Ki
 * @property float $Ki_acc
 * @property float $IC50
 * @property float $IC50_acc
 * @property integer $id_reference
 * @property integer $id_user
 * @property datetime $create_datetime
 * 
 * @property Substances $substance
 * @property Transporter_datasets $dataset
 * @property Transporter_targets $transporter_target
 * @property Users $user
 * @property Publications $reference
 * 
 */
class Transporters extends Db
{
    /** Transporter types */
    const T_SUBSTRATE = 1;
    const T_NON_SUBSTRATE = 2;
    const T_INHIBITOR = 3;
    const T_NON_INHIBITOR = 4;
    const T_NA = 5;
    const T_INTERACTS = 6;
    const T_SUBST_INHIB = 7;
    const T_SUBST_NONINHIB = 8;
    const T_NONSUBST_INHIB = 9;
    const T_NONSUBST_NONINHIB = 10;
    const T_ACTIVATOR = 11;
    const T_AGONIST = 12;
    const T_ANTAGONIST = 13;

    /** Holds valid transporter types */
    private static $valid_types = array
    (
        self::T_INHIBITOR,
        self::T_NON_INHIBITOR,
        self::T_SUBSTRATE,
        self::T_NON_SUBSTRATE,
        self::T_NA,
        self::T_INTERACTS,
        self::T_SUBST_INHIB,
        self::T_SUBST_NONINHIB,
        self::T_NONSUBST_INHIB,
        self::T_NONSUBST_NONINHIB,
        self::T_ACTIVATOR,
        self::T_AGONIST,
        self::T_ANTAGONIST
    );

    /** Enum types */
    private static $enum_types = array
    (
        self::T_INHIBITOR => 'Inhibitor',
        self::T_SUBSTRATE => 'Substrate',
        self::T_NON_SUBSTRATE => 'Non-substrate',
        self::T_NON_INHIBITOR => 'Non-inhibitor',
        self::T_NA => 'N/A',
        self::T_INTERACTS => 'Interacts',
        self::T_SUBST_INHIB => 'Substrate + inhibitor',
        self::T_SUBST_NONINHIB => 'Substrate + Noninhibitor',
        self::T_NONSUBST_INHIB => 'Nonsubstate + inhibitor',
        self::T_NONSUBST_NONINHIB => 'Nonsubtrate + noninhibitor',
        self::T_ACTIVATOR => 'Activator',
        self::T_AGONIST => 'Agonist',
        self::T_ANTAGONIST => 'Antagonist'

    );

    /** Valid types in uploader form */
    private static $upload_shortcuts = array
    (
        self::T_INHIBITOR => 'INH',
        self::T_SUBSTRATE => 'SUB',
        self::T_NON_SUBSTRATE => 'NSUB',
        self::T_NON_INHIBITOR => 'NINH',
        self::T_NA => 'NA',
        self::T_INTERACTS => 'INT',
        self::T_SUBST_INHIB => 'SUBINH',
        self::T_SUBST_NONINHIB => 'SUBNINH',
        self::T_NONSUBST_INHIB => 'NSUBINH',
        self::T_NONSUBST_NONINHIB => 'NSUBNINH',
        self::T_ACTIVATOR => 'ACT',
        self::T_AGONIST => 'AGN',
        self::T_ANTAGONIST => 'ANGN'
    );

    /** Links to another tables */
    public $has_one = array
    (
        'id_substance',
        'id_user',
        'id_reference' => array
        (
            'var' => 'reference',
            'class' => 'Publications'
        ),
        'id_dataset' => array
        (
            'var' => 'dataset',
            'class' => 'Transporter_datasets'
        ),
        'id_target' => array
        (
            'var' => 'target',
            'class' => 'Transporter_targets'
        ),
    );


    /**
     * Constructor
     * 
     * @param integer $id - Transporter ID
     */
    function __construct($id = NULL)
    {
        $this->table = 'transporters';
        parent::__construct($id);
    }

    /**
     * Check, if transporter detail already exists 
     * 
     * @param integer $id_substance
     * @param integer $id_target
     * @param integer $id_reference
     * @param integer $id_secondary_reference
     * @param integer $type
     * @param string $note
     * 
     * @return Transporters
     */
    public function search($id_substance, $id_target, $id_reference, $id_secondary_reference, $note)
    {
        $where = "WHERE t.id_substance = ? AND t.id_target = ?";
        $params = array
        (
            $id_substance,
            $id_target
        );

        if($id_reference)
        {
            $where .= " AND t.id_reference = ?";
            $params[] = $id_reference;
        }
        else
        {
            $where .= " AND t.id_reference IS NULL";
        }

        if($id_secondary_reference)
        {
            $where .= " AND td.id_reference = ?";
            $params[] = $id_secondary_reference;
        }
        else
        {
            $where .= " AND td.id_reference IS NULL";
        }

        if($note)
        {
            $where .= " AND t.note LIKE ?";
            $params[] = $note;
        }
        else
        {
            $where .= " AND t.note IS NULL";
        }
        
        $t = $this->queryOne("
            SELECT t.id 
            FROM transporters t
            JOIN transporter_datasets td ON td.id = t.id_dataset
            $where
            LIMIT 1
        ", $params);
             
        return new Transporters($t->id);
    }

    /**
     * Checks if given type is valid
     * 
     * @param integer $type
     * 
     * @return boolean
     */
    public static function is_valid_type($type)
    {
        return in_array($type, self::$valid_types);
    }

    /**
     * Returns shortcut for given type
     * 
     * @param integer $type
     * 
     * @return string
     */
    public static function get_shortcut($type)
    {
        if(!self::is_valid_type($type))
        {
            return NULL;
        }

        return self::$upload_shortcuts[$type];
    }

    /**
     * Returns all shortcuts
     * 
     * @return array
     */
    public static function get_all_shortcuts()
    {
        return self::$upload_shortcuts;
    }

    /**
     * Checks if given shortcut is valid
     * 
     * @param string $shortcut
     * 
     * @return boolean
     */
    public static function is_valid_shortcut($shortcut)
    {
        if(!$shortcut)
        {
            return FALSE;
        }

        $shortcut = strtoupper(trim($shortcut));

        return in_array($shortcut, self::$upload_shortcuts) || $shortcut === '';
    }

    /**
     * Returns enum transporter type
     * 
     * @param integer $type
     * 
     * @return string|null
     */
    public function get_enum_type($type = NULL)
    {
        if(!$type && $this)
        {
            $type = $this->type;
        }

        if(!in_array($type, self::$valid_types))
        {
            return NULL;
        }

        return self::$enum_types[$type];
    }

    /**
     * Gets type for given shortcut or NULL if not exists
     * 
     * @param string $shortcut
     * 
     * @return null|integer
     */
    public static function get_type_from_shortcut($shortcut)
    {
        $shortcut = strtoupper(trim($shortcut));

        if(!self::is_valid_shortcut($shortcut))
        {
            return self::T_NA;
        }

        return array_search($shortcut, self::$upload_shortcuts);
    }
}   