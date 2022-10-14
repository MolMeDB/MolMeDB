<?php

/**
 * Validator identifiers model
 * 
 * @property int $id
 * @property int $id_substance
 * @property Substances $substance
 * @property int $id_dataset_passive
 * @property Datasets $dataset_passive
 * @property int $id_dataset_active
 * @property Transporter_datasets $dataset_active
 * @property int $id_source
 * @property Validator_identifiers $source
 * @property int $server
 * @property int $identifier
 * @property string $value
 * @property int $id_user
 * @property Users $user
 * @property int $flag
 * @property int $state
 * @property string $state_msg - Used just for saving explanatory message
 * @property int $active
 * @property string $active_msg - Used just for saving explanatory message
 * @property string $create_date
 * 
 * @author Jakub Juracka
 */
class Validator_identifiers extends Db
{
    /**
     * Constructor
     * 
     * @param int $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'validator_identifiers';
        parent::__construct($id);
    }

    /**
     * @return Validator_identifiers
     */
    public static function instance()
    {
        return parent::instance();
    }

    /**
     * Links to other tables
     */
    protected $has_one = array
    (
        'id_substance',
        'id_user',
        'id_source' => array
        (
            'var' => 'source',
            'class' => 'Validator_identifiers'
        ),
        'id_dataset_active' => array
        (
            'var' => 'dataset_active',
            'class' => 'Transporter_datasets'
        ),
        'id_dataset_passive' => array
        (
            'var' => 'dataset_passive',
            'class' => 'Datasets'
        ),
    );

    /** IDENTIFIERS CONSTANTS */
    const ID_NAME = 1;
    const ID_SMILES = 2;
    const ID_INCHIKEY = 3;
    const ID_PUBCHEM = 4;
    const ID_DRUGBANK = 5;
    const ID_CHEBI = 6;
    const ID_PDB = 7;
    const ID_CHEMBL = 8;

    /** Servers constants */
    const SERVER_PUBCHEM = self::ID_PUBCHEM;
    const SERVER_DRUGBANK = self::ID_DRUGBANK;
    const SERVER_CHEBI = self::ID_CHEBI;
    const SERVER_PDB = self::ID_PDB;
    const SERVER_CHEMBL = self::ID_CHEMBL;
    const SERVER_UNICHEM = 9;
    const SERVER_RDKIT = 10;
    const SERVER_MOLMEDB = 99;

    /** ACTIVE STATES */
    const INACTIVE = 0;
    const ACTIVE = 1;

    /** STATES */
    const STATE_NEW = Validator_identifier_validations::STATE_NEW;
    const STATE_VALIDATED = Validator_identifier_validations::STATE_VALIDATED;
    const STATE_INVALID = Validator_identifier_validations::STATE_INVALID;

    /**
     * FLAGS
     */
    const SMILES_FLAG_CANONIZED = 1;
    const SMILES_FLAG_CANONIZATION_ERROR = 2;

    /**
     * Enum types of identifiers
     */
    private static $enum_identifiers = array
    (
        self::ID_NAME => 'name',
        self::ID_SMILES => 'smiles',
        self::ID_INCHIKEY => 'inchikey',
        self::ID_PUBCHEM => 'pubchem',
        self::ID_DRUGBANK => 'drugbank',
        self::ID_CHEBI  => 'chebi',
        self::ID_PDB    => 'pdb',
        self::ID_CHEMBL => 'chembl',
    );

    /**
     * Enum types of servers
     */
    private static $enum_servers = array
    (
        self::SERVER_PUBCHEM => 'Pubchem server',
        self::SERVER_DRUGBANK => 'Drugbank server',
        self::SERVER_CHEBI  => 'ChEBI server',
        self::SERVER_PDB    => 'PDB server',
        self::SERVER_CHEMBL => 'ChEMBL server',
        self::SERVER_UNICHEM => 'Unichem server',
        self::SERVER_RDKIT => 'RDkit software',
        self::SERVER_MOLMEDB => 'MolMeDB server',
    );

    /** 
     * Holds info about preffered servers for getting given identifiers
     * 
     * PERSIST PRIORITY!
     */
    public static $identifier_servers = array
    (
        self::ID_NAME => array
        (
            self::SERVER_PDB,
            self::SERVER_PUBCHEM,
        ),
        self::ID_SMILES => array
        (
            self::SERVER_CHEMBL, // 8
            self::SERVER_PUBCHEM, // 4
            self::SERVER_PDB, // 7
        ),
        self::ID_INCHIKEY => array
        (
            self::SERVER_RDKIT
        ),
        self::ID_PUBCHEM => array
        (
            self::SERVER_UNICHEM
        ),
        self::ID_DRUGBANK => array
        (
            self::SERVER_PDB,
            self::SERVER_UNICHEM
        ),
        self::ID_CHEBI  => array
        (
            self::SERVER_UNICHEM
        ),
        self::ID_PDB    => array
        (
            self::SERVER_UNICHEM
        ),
        self::ID_CHEMBL => array
        (
            self::SERVER_UNICHEM
        ),
    );

    /**
     * Holds info about what servers are idependent on MolMeDB
     */
    public static $independent_servers = array
    (
        self::SERVER_PUBCHEM,
        self::SERVER_PDB,
        self::SERVER_CHEMBL,
        self::SERVER_CHEBI,
        self::SERVER_DRUGBANK 
    );

    /**
     * Credible sources
     */
    public static $credible_sources = array
    (
        self::SERVER_PUBCHEM,
        self::SERVER_PDB,
        self::SERVER_CHEMBL,
        self::SERVER_CHEBI,
        self::SERVER_DRUGBANK,
        self::SERVER_RDKIT,
        self::SERVER_MOLMEDB
    );

    
    /**
     * Enum active states
     */
    private static $enum_active_states = array
    (
        self::INACTIVE => "inactive",
        self::ACTIVE => 'active'
    );

    /**
     * Checks, if source is credible
     * 
     * @param int $server_id
     * 
     * @return boolean
     */
    public function is_credible($server_id)
    {
        if(!$this || !$this->id)
        {
            return false;
        }

        return in_array($server_id, self::$credible_sources) && $this->state === self::STATE_VALIDATED;
    }

    /**
     * Returns source by identifier type for given substance
     * 
     * @param int $id_substance
     * @param int $identifier_type
     * 
     * @return Validator_identifiers
     */
    public function find_source($id_substance, $identifier_type)
    {
        if(!self::is_valid_identifier($identifier_type))
        {
            return null;
        }

        $where = array
        (
            'id_substance'  => $id_substance,
            'active'        => self::ACTIVE,
            'identifier'    => $identifier_type,
            'state !='      => self::STATE_INVALID
        );

        $record = self::instance()->where($where)
            ->order_by('create_date', 'DESC')
            ->get_one();

        return $record;
    }

    /**
     * Returns source by identifier type for given substance
     * 
     * @param int $id_substance
     * @param string $value
     * 
     * @return Validator_identifiers|null - Null if not exists
     */
    public function find_source_by_value($id_substance, $value)
    {
        $where = array
        (
            'id_substance'  => $id_substance,
            'active'        => self::ACTIVE,
            'value LIKE'    => $value,
            'state !='      => self::STATE_INVALID
        );

        $record = self::instance()->where($where)
            ->order_by('state DESC, create_date', 'DESC')
            ->get_one();

        if($record->id)
        {
            return $record;
        }

        return null;
    }


    /**
     * Checks, if identifier type is valid
     * 
     * @param int $identifier
     * 
     * @return bool
     */
    public static function is_valid_identifier($identifier)
    {
        return array_key_exists($identifier, self::$enum_identifiers);
    }


    /**
     * Checks, if server_id is valid
     * 
     * @param int $server_id
     * 
     * @return bool
     */
    public static function is_valid_server($server_id)
    {
        return array_key_exists($server_id, self::$enum_servers);
    }
    
    /**
     * Returns enum identifier
     * 
     * @param int $identifier
     * 
     * @return string|null
     */
    public static function get_enum_identifier($identifier)
    {
        if(!self::is_valid_identifier($identifier))
        {
            return null;
        }
        return self::$enum_identifiers[$identifier];
    }
    
    /**
     * Returns enum server
     * 
     * @param int $server_id
     * 
     * @return string|null
     */
    public static function get_enum_server($server_id)
    {
        if(!self::is_valid_server($server_id))
        {
            return null;
        }
        return self::$enum_servers[$server_id];
    }

    /**
     * Returns active and validated value of identifier for given substance
     * 
     * @param int $id_substance
     * @param int $id_type
     * 
     * @return Validator_identifiers
     */
    public function get_active_substance_value($id_substance, $id_type)
    {
        if(!$id_substance)
        {
            throw new Exception('Invalid substance id.');
        }

        if(!self::is_valid_identifier($id_type))
        {
            return null;
        }

        return $this->where(array
            (
                'id_substance' => $id_substance,
                'identifier'    => $id_type,
                'active'        => self::ACTIVE
            ))
            ->get_one();
    }

    /**
     * Returns all substance identifiers
     * 
     * @param int $id_substance
     * 
     * 
     * @return array
     */
    public function get_all_active_substance_values($id_substance)
    {
        $result = [];

        foreach(self::$enum_identifiers as $id => $non)
        {
            $result[$id] = $this->get_active_substance_value($id_substance, $id);
        }

        return $result;
    }

    /**
     * Returns possible values for attribute and substance
     * 
     * @param int $id_substance
     * @param int $id_type
     * 
     * @return array
     */
    public function get_alternatives($id_substance, $id_type)
    {
        if(!$id_substance)
        {
            throw new Exception('Invalid substance id.');
        }

        if(!self::is_valid_identifier($id_type))
        {
            return [];
        }

        $v = $this->where(array
            (
                'id_substance' => $id_substance,
                'identifier'    => $id_type,
            ))
            ->order_by('active', 'desc')
            ->get_all();

        $result = [];

        foreach($v as $row)
        {
            $result[] = array
            (
                'id' => $row->id,
                'is_active' => $row->active,
                'is_validated' => $row->state,
                'identifier' => $row->identifier,
                'identifier_enum' => self::$enum_identifiers[$row->identifier],
                'value' => $row->value,
                'id_source' => $row->id_source,
                'source' => !$row->source ? [] : array
                (
                    'identifier' => $row->source->identifier,
                    'is_active' => $row->source->active,
                    'is_validated' => $row->source->state,
                    'identifier_enum' => self::$enum_identifiers[$row->source->identifier],
                    'value' => $row->source->value
                )
            );
        }

        return $result;
    }

    /**
     * Validates record
     * 
     * @param int $id
     */
    public function validate($id = NULL)
    {
        if(!$id && $this)
        {
            $id = $this->id;
        }

        $r = new Validator_identifiers($id);

        if(!$r->id)
        {
            throw new Exception('Invalid ID. Cannot validate record.');
        }

        $r->state = self::STATE_VALIDATED;
        $r->save();
    }

    /**
     * Invalidates record
     * 
     * @param int $id
     */
    public function invalidate($id = NULL)
    {
        if(!$id && $this)
        {
            $id = $this->id;
        }

        $r = new Validator_identifiers($id);

        if(!$r->id)
        {
            throw new Exception('Invalid ID. Cannot validate record.');
        }

        $r->state = self::STATE_NEW;
        $r->save();
    }

    /**
     * Activates record and deactivates old value
     * 
     * @param int $id
     */
    public function activate($id = NULL)
    {
        if(!$id && $this)
        {
            $id = $this->id;
        }

        $r = new Validator_identifiers($id);

        if(!$r->id)
        {
            throw new Exception('Invalid ID. Cannot validate record.');
        }

        if($r->active == self::ACTIVE)
        {
            return;
        }

        $r->state = self::STATE_VALIDATED;
        $r->active = self::ACTIVE;
        $r->save(); // Disabling old in save
    }

    /**
     * Returns duplicate identifiers for multiple compounds
     * 
     * @return object[]
     */
    public function get_duplicate_identifiers()
    {
        $valid = self::STATE_VALIDATED;

        $data = $this->queryAll("SELECT *
            FROM
            (
                SELECT *, COUNT(DISTINCT id_substance) as total_ids, GROUP_CONCAT(DISTINCT id_substance) as ids
                FROM (
                    SELECT *
                    FROM validator_identifiers
                    WHERE state = $valid
                    ) as t
                WHERE value IS NOT NULL
                GROUP BY value  
                ORDER BY `total_ids` DESC
            ) as t
            WHERE t.total_ids > 1");

        $result = [];

        foreach($data as $row)
        {
            $result[] = new Iterable_object([
                'value' => $row->value,
                'id_substances' => explode(',', $row->ids),
                'total' => $row->total_ids
            ]);
        }

        return $result;
    }

    /**
     * Overloading of save function
     */
    public function save()
    {
        // Adjust values to requested format for new downloaded records
        if(!$this->id && $this->id_source)
        {
            if($this->identifier == Validator_identifiers::ID_NAME && !Identifiers::is_identifier($this->value))
            {
                $name = trim($this->value);
                $uc = strtoupper($name);

                if($name === $uc)
                {
                    $name = ucfirst(strtolower($name));
                }

                $this->value = $name;
            }
        }

        $t = false;

        // Check first, if similar record already exists if new record
        if(!$this->id)
        {
            $t = true;

            $where = array
            (
                'id_substance'  => $this->id_substance,
                'identifier'    => $this->identifier,
                'value'         => $this->value,
            );

            if($this->id_source)
            {
                $where['id_source'] = $this->id_source;
            }
            else
            {
                $where[] = 'id_source IS NULL';    
            }
            if($this->server)
            {
                $where['server'] = $this->server;
            }
            else
            {
                $where[] = 'server IS NULL';
            }
            if($this->id_user)
            {
                $where['id_user'] = $this->id_user;
            }
            else
            {
                $where[] = 'id_user IS NULL';
            }

            $exists = $this->where($where)->get_one();

            if($exists->id)
            {
                $exists->state = $this->state;
                $exists->active = $this->active;
                $exists->state_msg = $this->state_msg;
                $exists->active_msg = $this->active_msg;
                $exists->id_dataset_passive = $this->id_dataset_passive;
                $exists->id_dataset_active = $this->id_dataset_active;
                
                $exists->save();
                return;
            }
        }
        else
        {
            $changes = $this->get_changes();

            // Save info about state change
            if($changes->state)
            {
                $n = new Validator_identifier_validations();
                $n->id_validator_identifier = $this->id;
                $n->id_user = session::user_id();
                $n->state = $this->state;
                $n->message = $this->state_msg;
                $n->save();
            }

            // Deactive old and save change
            if($changes->active && $changes->active->curr == self::ACTIVE)
            {
                // Deactive old
                $olds = $this->where(array
                (
                    'id_substance' => $this->id_substance,
                    'identifier'    => $this->identifier,
                ))->get_all();
                
                $old_id = NULL;

                foreach($olds as $old)
                {
                    if($old->active == self::ACTIVE)
                    {
                        $old_id = $old->id;
                    }

                    $old->active = self::INACTIVE;
                    $old->save();
                }

                // save change
                $n = new Validator_identifier_changes();

                $n->id_old = $old_id;
                $n->id_new = $this->id;
                $n->id_user = session::user_id();
                $n->message = $this->active_msg;
                $n->save();
            }
            else if($changes->active && $changes->active->curr == self::INACTIVE)
            {
                // save change
                $n = new Validator_identifier_changes();

                $n->id_old = $this->id;
                $n->id_new = NULL;
                $n->id_user = session::user_id();
                $n->message = $this->active_msg;
                $n->save();
            }
        }

        $active_msg = $this->active_msg;
        $state_msg = $this->state_msg;

        parent::save();

        if($t)
        {
            if($this->active == self::ACTIVE)
            {
                // Deactive old
                $olds = $this->where(array
                (
                    'id_substance'  => $this->id_substance,
                    'identifier'    => $this->identifier,
                    'active'        => self::ACTIVE,
                    'id !='         => $this->id
                ))->get_all();

                $old_id = NULL;

                foreach($olds as $old)
                {
                    $old_id = $old->id;
                    $old->active = self::INACTIVE;
                    $old->save();
                }

                // save change
                $n = new Validator_identifier_changes();

                $n->id_old = $old_id;
                $n->id_new = $this->id;
                $n->id_user = session::user_id();
                $n->message = $active_msg;
                $n->save();
            }

            if($this->state == self::STATE_VALIDATED)
            {
                $n = new Validator_identifier_validations();
                $n->id_validator_identifier = $this->id;
                $n->id_user = session::user_id();
                $n->state = $this->state;
                $n->message = $state_msg;
                $n->save();
            }
        }
    }
}