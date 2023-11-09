<?php

/**
 * Model for working with DB
 */
class Db extends Iterable_object
{
    private static $connection;

    private static $active_transaction = false;
    
    private static $setting = array
    (
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",
        PDO::ATTR_EMULATE_PREPARES => false,
    );

    /** MYSQL QUERY BUILDER ATTRIBUTES */
    protected $table;
    private $select_list = '*';
    private $alias = '';
    private $limit = '';
    private $offset = '';
    private $where = '';
    private $group_by = '';
    private $order_by = '';
    private $distinct = false;
    private $join = '';

    private $debug = false;

    /**
     * MAIN CONSTRUCTOR
     * 
     * @param integer $id
     */
    public function __construct($id = NULL)
    {
        if(is_array($id))
        {
            return parent::__construct($id);
        }

        if ($id && $this->table) 
        {
            return parent::__construct($this->queryOne("
                SELECT * 
                FROM `$this->table`
                WHERE id = ? 
                ", array($id), False));
        }
    }

    /**
     * Returns instance of called class
     * 
     * @return Db|null
     */
    public static function factory()
    {
        $class = get_called_class();

        if($class && class_exists($class))
        {
            return new $class();
        }

        return null;
    }

    /**
     * Fetch DB table structure
     * 
     * @return array|null
     */
    private function fetch_structure()
    {
        // If old values are presented, return
        if(!empty($this->old_values))
        {
            return array_keys($this->old_values);
        }

        $table = $this->table;

        if(!$table)
        {
            return null;
        }

        $q = self::$connection->prepare("DESCRIBE " . $table);
        $q->execute();
        $structure = $q->fetchAll(PDO::FETCH_COLUMN);

        return $structure;
    }

    /**
     * Static constructor
     * 
     * @return Db
     */
    public static function instance()
    {
        $class_name = get_called_class();

        if(class_exists($class_name))
        {
            return new $class_name();
        }

        return null;
    }

    /** Function for connection to the DB
     * 
     * @param string host 
     * @param string user 
     * @param string password 
     * @param string database 
     * 
     * @author Jakub Juračka
     */
    public static function connect($host, $user, $password, $database)
    {
        if (!isset(self::$connection)) 
        {
            self::$connection = @new PDO(
                "mysql:host=$host;dbname=$database",
                $user,
                $password,
                self::$setting
            );
        }
    }

    /**
     * Returns DB version stored in DB
     * 
     * @return string
     */
    public function get_db_version()
    {
        return $this->queryOne('
            SELECT version 
            FROM version
            LIMIT 1')->version;
    }

    /**
     * Set new db version
     * 
     * @param string $version
     */
    public function set_db_version($version)
    {
        return $this->query('
            UPDATE `version`
            SET `version` = ?
        ', array($version));
    }

    /**
     * Checks, if GROUP BY is not restricted
     * 
     * @throws MmdbException
     */
    public function check_group_by_restrictions($throw = false)
    {
        try
        {
            $this->query('
                SELECT CONCAT(id, attribute) as attr, COUNT(id), value
                FROM config
                GROUP BY (attr)');
            return true;
        }
        catch(Exception $e)
        {
            if($throw)
            {
                throw $e;
            }
            // If error, try to update
            $this->query("SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");
        }
    }

    /**
     * Return array without numeric keys
     * 
     * Recommended to use only for Mysql Results
     * 
     * @param array $arr
     * 
     * @return array
     */
    private function remove_numeric_keys($arr)
    {
        $result = array();

        if(!isset($arr[0]))
        {
            return $arr;
        }

        foreach ($arr as $key => $val) 
        {
            if (is_array($val)) 
            {
                $result[$key] = $this->remove_numeric_keys($val);
                continue;
            }

            if (is_numeric($key)) 
            {
                continue;
            }

            $result[$key] = $val;
        }

        return $result;
    }


    /**
     * Sets nonfilled values to null
     * 
     * @return Db
     */
    public function set_empty_vals_to_null()
    {
        foreach($this->data as $key => $val)
        {
            if(trim($val ?? "") === '')
            {
                $this->data[$key] = NULL;
            }
        }

        return $this;
    }

    /**
     * Execute query to DB and returns one result
     * 
     * @param string $query
     * @param array $parameters
     * 
     * @return array|object|Iterable_object
     */
    public function queryOne($query, $parameters = array(), $as_object = True)
    {
        try
        {
            $return = self::$connection->prepare($query);
            $return->execute($parameters);

            $result = $return->fetch();

            $result = $this->remove_numeric_keys($result);

            if(!$result)
            {
                $result = array();
            }

            if (!$as_object) 
            {
                return $result;
            } 
            else 
            {
                return new Iterable_object($result);
            }
        }
        catch(Exception $e)
        {
            throw new DbException($e->getMessage(), $query, $e->getCode(), $e);
        }
    }

    /**
     * Execute query to DB and returns all results
     * 
     * @param string $query
     * @param array $parameters
     * 
     * @return array|object
     */
    public function queryAll($query, $parameters = array(), $as_object = True)
    {
        try
        {
            $return = self::$connection->prepare($query);
            $return->execute($parameters);

            $result = $return->fetchAll();

            $result = $this->remove_numeric_keys($result);

            if (!$as_object) 
            {
                return $result;
            } 
            else 
            {
                return new Iterable_object($result, True);
            }
        }
        catch(Exception $e)
        {
            throw new DbException($e->getMessage(), $query, $e->getCode(), $e);
        }
    }

    /**
     * Execute query to DB
     * 
     * @param string $query
     * @param array $parameters
     * 
     * @return integer
     * @throws DbException
     */
    public function query($query, $parameters = array(), $ignore_exception = false)
    {
        try
        {
            $return = self::$connection->prepare($query);
            $return->execute($parameters);
            return $return->rowCount();
        }
        catch(Exception $e)
        {
            if($ignore_exception)
            {
                return;
            }
            throw new DbException($e->getMessage(), $query, $e->getCode(), $e);
        }
        
    }


    /**
     * Get all rows from given table
     * 
     * @return Iterable_object
     */
    public function get_all($reinit = true)
    {
        try
        {
            if (!$this->table) 
            {
                throw new MmdbException('No table name was set.');
            }

            if(!$this->select_list)
            {
                $this->select_list = '*';
            }

            // Prepare query
            $query = '
                SELECT ' . ($this->distinct ? ' DISTINCT ' : '') . 
                $this->select_list . ' 
                FROM ' . $this->table . ($this->alias ? ' as ' . $this->alias . ' ' : '') .
                ' ' . $this->join .
                ' ' . $this->where . ' ' .
                ' ' . $this->group_by .
                $this->order_by
                . $this->limit;

            if($this->debug) // DEBUG
            {
                echo($query);
                die;
            }

            $data =  $this->queryAll($query, array(), False);

            if($reinit)
            {
                $this->reload();
            }
            
            return new Iterable_object($data, get_class($this));
        }
        catch(DbException $e)
        {
            throw $e;
        }
        catch(MmdbException $e)
        {
            throw new DbException($e->getMessage(), 'Error occured during processing database query. Please, contact server administrator.', $e->getCode(), $e);
        }
    }

    /**
     * COunt all rows from given table
     * 
     * @return int
     */
    public function count_all()
    {
        try
        {
            if (!$this->table) 
            {
                throw new Exception('No table name was set.');
            }

            if(!$this->select_list)
            {
                $this->select_list = '*';
            }

            // Prepare query
            $query = '
                SELECT COUNT(*) as count 
                FROM (
                    SELECT ' . ($this->distinct ? ' DISTINCT ' : '') . $this->select_list .
                    ' FROM '. $this->table . ($this->alias ? ' as ' . $this->alias . ' ' : '') .
                    ' ' . $this->join .
                    ' ' . $this->where . ' ' .
                    ' ' . $this->group_by
                . ') as tab';

            if($this->debug) // DEBUG
            {
                echo($query);
                die;
            }

            $data = $this->queryOne($query);

            $this->reload();

            return $data->count;
        }
        catch(DbException $e)
        {
            throw $e;
        }
        catch(MmdbException $e)
        {
            throw new DbException($e->getMessage(), 'Error occured during processing database query. Please, contact server administrator.', $e->getCode(), $e);
        }
    }

    /**
     * Get one row from given table
     * 
     * @return Db
     */
    public function get_one()
    {
        try
        {
            if (!$this->table) {
                throw new Exception('No table name was set.');
            }

            if (!$this->select_list) 
            {
                $this->select_list = '*';
            }

            $query = '
                SELECT ' . $this->select_list . ' 
                FROM ' . $this->table . ($this->alias ? ' as ' . $this->alias . ' ' : '') .
                ' ' . $this->join .
                ' ' . $this->where . ' ' .
                ' ' . $this->group_by .
                $this->order_by
                . ' LIMIT 1';

            if($this->debug)
            {
                echo($query);
                die;
            }

            $data =  $this->queryOne($query, array(), False);

            $class = get_class($this);

            $this->reload();

            return new $class($data);
        }
        catch(DbException $e)
        {
            throw $e;
        }
        catch(MmdbException $e)
        {
            throw new DbException($e->getMessage(), 'Error occured during processing database query. Please, contact server administrator.', $e->getCode(), $e);
        }
    }

    /**
     * Sets params to default values
     * 
     */
    public function reload()
    {
        $this->select_list = '*';
        $this->limit = '';
        $this->where = '';
        $this->group_by = '';
        $this->order_by = '';
        $this->distinct = false;
        $this->join = '';
    }

    /**
     * Turn on debug mode
     * 
     * @param boolean $flag
     */
    public function debug($flag = TRUE)
    {
        $this->debug = $flag;

        return $this;
    }

    /**
     * Deletes actual instance of object
     * 
     */
    public function delete()
    {
        $query = "
            DELETE FROM $this->table
            WHERE id = $this->id
        ";

        try
        {
            if (!$this->table) 
            {
                throw new MmdbException('No table name was set.');
            }

            if (!$this->id) 
            {
                throw new MmdbException('Invalid class instance.');
            }

            // Delete record
            $this->query($query);

            // Destroy object data
            $this->data = [];
            $this->old_data = [];
            $this->keys = [];

        }
        catch(Exception $e)
        {
            throw new DbException($e->getMessage(), $query, $e->getCode(), $e);
        }
    }

    /**
     * Sets table alias for shorting queries
     * 
     * @param string $alias
     * 
     * @return Db
     */
    public function alias($alias)
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * SELECT LIST for MYSQL QUERY BUILDER
     * 
     * @param array $data
     */
    public function select_list($data)
    {
        if(empty($data))
        {
            return $this;
        }

        if(is_string($data))
        {
            $this->select_list = $data;
            return $this;
        }

        $select_list = '';

        foreach ($data as $attr) 
        {
            $select_list .= $attr . ',';
        }

        $select_list = rtrim($select_list, ',');

        $this->select_list = $select_list;

        return $this;
    }

    /**
     * JOIN for MYSQL QUERY BUILDER
     * 
     * @param string|array $string
     */
    public function join($string)
    {
        $join_str = '';

        if(is_string($string))
        {
            $string = [$string];
        }

        foreach ($string as $val) 
        {
            $val = trim($val);

            if(strtoupper(substr($val, 0, 4)) == 'JOIN' || 
                strtoupper(substr($val, 0, 4)) == 'LEFT' || 
                strtoupper(substr($val, 0, 5)) == 'RIGHT' || 
                strtoupper(substr($val, 0, 7)) == 'NATURAL')
            {
                $join_str .= $val;
            }
            else
            {
                $join_str .= ' JOIN ' . $val;
            }
        }

        $this->join .= " $join_str ";

        return $this;
    }

    /**
     * IN for MYSQL QUERY BUILDER
     * 
     * @param $attr
     * @param $vals
     */
    public function in($attr, $vals, $not = false)
    {
        if($this->where == '')
        {
            $this->where = 'WHERE ';
        } 
        else
        {
            $this->where .= ' AND ';
        }

        $op = $not ? 'NOT IN' : "IN";

        if(!(is_array($vals) && empty($vals)))
        {
            if(is_array($vals))
            {
                $n = false;

                if(in_array('NULL', $vals))
                {
                    $n = True;
                    $this->where .= " ( $attr IS NULL OR ";

                    $k = array_search("NULL", $vals);
                    unset($vals[$k]);
                }

                $this->where .= " $attr $op ('" . implode("','", $vals) . "') ";

                if($n)
                {
                    $this->where .= ')';
                }

            }
            else if($vals == 'NULL' || !$vals)
            {
                $this->where .= " $attr IS NULL ";
            }
            else
            {
                $this->where .= " $attr = $vals";
            }
        }

        $this->where = rtrim(trim($this->where), 'WHERE');
        $this->where = rtrim($this->where, 'AND');

        return $this;
    }

    /**
     * WHERE for MYSQL QUERY BUILDER
     * 
     * @param array|string $attr
     * @param string $val Optional
     * 
     */
    public function where($attr, $val = NULL)
    {
        if($this->where == '')
        {
            $this->where = 'WHERE ';
        }
        else if (substr(trim($this->where), -1) != '(')
        {
            $this->where .= ' AND ';
        }

        if(!$val && is_string($attr))
        {
            $this->where .= $attr;

            return $this;
        }

        if($val)
        {
            $attr = trim($attr);
            $multiparams = explode(' ', $attr);

            if(count($multiparams) > 1)
            {
                if($val == "NULL")
                {
                    $this->where .= $attr . ' NULL';
                }
                else if(trim($val) === '')
                {
                    $this->where .= '(' . $attr . ' NULL OR ' . $attr . ' "")';
                }
                else if(strtolower($multiparams[1]) == 'regexp')
                {
                    $this->where .= $attr . ' "' . $val . '"';
                }
                else
                {
                    if(is_string($val))
                    {
                        $val = preg_replace('/[\\\]{1}/', '\\\\\\\\\\\\\\', $val);
                    }
                    $this->where .= $attr . ' "' . $val . '"';
                }
            }
            else if(strtoupper($val) === 'NULL')
            {
                $this->where .= $attr . ' IS NULL';
            }
            else if(trim($val) === '')
            {
                $this->where .= '(' . $attr . ' IS NULL OR ' . $attr . ' LIKE "")';
            }
            else
            {
                if(is_string($val))
                {
                    $val = preg_replace('/[\\\]{1}/', '\\\\\\\\\\\\\\', $val);
                }
                $this->where .= $attr . ' = "' . $val . '"';
            }

            return $this;
        }

        if(is_array($attr) && count($attr))
        {
            $where = $attr;

            foreach($where as $attr => $val)
            {
                $attr = trim($attr);

                if(is_string($val))
                {
                    $val = preg_replace('/[\\\]{1}/', '\\\\\\\\\\\\\\', $val);
                }

                if (count(explode(' ', $attr)) > 1) 
                {
                    if ($val == "NULL" || trim($val) === '') {
                        $this->where .= $attr . ' NULL';
                    } 
                    else 
                    {
                        $this->where .= $attr . ' "' . $val . '"';
                    }
                } 
                else if($val === NULL || strtoupper($val) === 'NULL')
                {
                    $this->where .=  $attr . ' IS NULL';
                }
                else if(trim($val) === '')
                {
                    $this->where .= '(' . $attr . ' IS NULL OR ' . $attr . ' LIKE "")';
                }
                else if(is_numeric($attr))
                {
                    if(trim($val) == ")" || trim($val) == "OR" || trim($val) == "AND")
                    {
                        $this->where = rtrim(trim($this->where), 'AND');
                        $this->where = rtrim(trim($this->where), 'OR');
                    }
                    
                    $this->where .= " $val";
                    // echo($this->where . '<br/>');
                }
                else 
                {
                    $this->where .= $attr . ' = "' . $val . '"';
                    // echo($this->where . '<br/>');
                }

                if(substr(trim($this->where), -2) != "OR" && substr(trim($this->where), -1) != '(')
                {
                    $this->where .= ' AND ';
                    // echo($this->where . '<br/>');
                }
                else
                {
                    $this->where .= ' ';
                    // echo($this->where . '<br/>');
                }
            }
        }

        $this->where = rtrim(trim($this->where), 'AND');
        $this->where = rtrim($this->where, 'WHERE');

        return $this;
    }

    /**
     * LIMIT for MYSQL QUERY BUILDER
     */ 
    public function distinct()
    {
        $this->distinct = True;
        return $this;
    }

    /**
     * GROUP BY for MYSQL QUERY BUILDER
     * 
     * @param string $column
     * 
     */
    public function group_by($column)
    {
        $this->group_by = "GROUP BY $column ";

        return $this;
    }

    /**
     * LIMIT for MYSQL QUERY BUILDER
     * 
     * @param integer $par1 - Pagination/limit
     * @param integer $par2 - Limit if set pagination
     */
    public function limit($par1, $par2 = NULL)
    {
        if(!$par1)
        {
            return $this;
        }

        $limit_str = '';

        if ($par1 && $par2) 
        {
            $limit_str = " LIMIT " . ($par1 - 1) * $par2 . ',' . $par2;
        } 
        else if ($par1) 
        {
            $limit_str = " LIMIT $par1 ";
        }

        $this->limit = $limit_str;

        return $this;
    }

    /**
     * ORDER BY for MYSQL QUERY BUILDER
     * 
     * @param string $column
     * @param string $direction
     */
    public function order_by($column, $direction = 'asc')
    {
        if(!$column || trim($column) == '')
        {
            return $this;
        }

        if(strtolower($direction) != 'asc')
        {
            $direction = 'desc';
        }

        $this->order_by = " ORDER BY $column $direction ";

        return $this;
    }

    /**
     * Get count of items in table
     */
    public function get_all_count()
    {
        if (!$this->table) 
        {
            throw new Exception('No table name was set.');
        }
        
        $count = $this->queryOne('
            SELECT COUNT(*) as count
            FROM ' . $this->table .
            ' ' . $this->where .
            ' ' . $this->group_by)->count;

        $this->reload();

        return intval($count);
    }


    /**
     * Returns changes of attribte values
     * 
     * @return array
     */
    public function get_attribute_changes()
    {
        if(!$this || !$this->table || !$this->id)
        {
            return [];
        }

        $result = [];
        $data = $this->as_array();
        $old_data = $this->old_data;
        $allowed_attrs = $this->fetch_structure();

        foreach ($data as $key => $val) 
        {
            if (is_numeric($key) || $key == '' ||
                $key == 'id')
            {
                continue;
            }

            if(!is_object($val) && !is_array($val) && trim(strval($val)) === '')
            {
                $val = NULL;
            }

            // If change is recognized
            if (in_array($key, $allowed_attrs) && 
                $old_data[$key] !== $val && 
                !is_object($val) && 
                !is_array($val))
            {
                $result[$key] = array
                (
                    'new' => $val === NULL ? NULL : trim($val),
                    'old' => $old_data[$key]
                );
            }
        }

        return $result;
    }

    /**
     * Save new instance of object
     */
    public function save()
    {
        if (!$this || !$this->table) 
        {
            throw new Exception('Object instance is not properly set.');
        }

        $data = $this->as_array();
        $old_data = $this->old_data;
        $new_data = array();
        $allowed_attrs = $this->fetch_structure();

        if(!count($allowed_attrs))
        {
            throw new Exception('Invalid table structure. Cannot load list of attributes.');
        }

        // Remove numeric values
        foreach ($data as $key => $val) 
        {
            if (is_numeric($key) || $key == '' ||
                $key == 'id')
            {
                continue;
            }

            // If new record, just add
            if(!$this->id && in_array($key, $allowed_attrs))
            {
                $new_data[$key] = $val;
                continue;
            }

            if(!is_object($val) && !is_array($val) && trim(strval($val)) === '')
            {
                $val = NULL;
            }

            // If change is recognized
            if (in_array($key, $allowed_attrs) && 
                $old_data[$key] !== $val && 
                !is_object($val) && 
                !is_array($val))
            {
                $new_data[$key] = $val === NULL ? NULL : trim($val);
            }
        }

        if (empty($new_data)) 
        {
            return True;
        }

        // Values for insert/update
        $values = array();
        $attr_str = '';

        foreach ($new_data as $key => $val) 
        {
            $attr_str .= " `$key` = ?,";
            $values[] = $val;
        }

        $attr_str = rtrim(rtrim($attr_str), ',');

        // If set ID, then UPDATE
        if ($this->id) 
        {
            $values[] = $this->id;

            if($this->debug)
            {
                echo '
                    UPDATE ' . $this->table .  '
                    SET ' . $attr_str . ' 
                    WHERE id = ?';

                print_r($values);
                die;
            }

            $this->query('
                UPDATE ' . $this->table .  '
                SET ' . $attr_str . ' 
                WHERE id = ?
            ', $values);

            self::__construct($this->id);
        } 
        else 
        {
            if($this->debug)
            {
                print_r($new_data);
                die;
            }
            $last_id = $this->insert($this->table, $new_data);
            $this->id = $last_id;
            self::__construct($this->id);
        }
    }


    /**
     * Insert row to the DB
     * 
     * @param string $table
     * @param array $parameters
     * @param string $onDuplicate What to do if duplicate key entry
     * @param array $onDuplicateParams
     * 
     * @return integer
     */
    protected function insert($table, $parameters = array(), $onDuplicate = "", $onDuplicateParams = array())
    {
        $query = "INSERT INTO `$table` (`" .
            implode('`, `', array_keys($parameters)) .
            "`) VALUES (" . str_repeat('?,', sizeOf($parameters) - 1) . "?) " . $onDuplicate;

        try
        {
            $params = array_merge(array_values($parameters), $onDuplicateParams);
            $this->query($query,$params);
        }
        catch(Exception $e)
        {
            throw new DbException($e->getMessage(), $query, $e->getCode(), $e, "Invalid database request - cannot save new item.");
        }

        try
        {
            $id =  $this->get_max_id($table);

            return $id;
        }
        catch(Exception $e)
        {
            return NULL;
        }
    }

    /**
     * Get max ID in table
     * 
     * @param string $table - Name of table
     * 
     * @return integer 
     */
    private function get_max_id($table)
    {
        return $this->queryOne('SELECT MAX(id) as max FROM ' . $table)->max;
    }
    
    /**
     * Returns last inserted/edited substance ID
     * 
     * @return integer
     */
    public function getLastIdSubstance_time()
    {
        return $this->queryOne('SELECT `id` FROM `substances` 
                                ORDER BY `editDateTime` DESC LIMIT 1');
    }

    /**
     * Gets last ID
     * 
     * @return integer
     */
    public function getLastId()
    {
        return $this->queryOne('SELECT LAST_INSERT_ID()');
    }
    
    /**
     * Gets max id from substances
     * 
     * @return integer
     */
    public function getLastIdSubstance()
    {
        return $this->queryOne('SELECT MAX(id) as id FROM substances')['id'];
    }
    
    /**
     * Gets last membrane ID
     * 
     * @return integer
     */
    public function getLastIdMembrane()
    {
        return $this->queryOne('SELECT MAX(id) as id FROM membranes')['id'];
    }
    
    /**
     * Gets last ID of interaction
     * 
     * @return $integer
     */
    public function getLastIdInteraction()
    {
        return $this->queryOne('
            SELECT id
            FROM interaction
            ORDER BY `editDateTime` DESC LIMIT 1');
    }
    
    /**
     * Gets ID of last added dataset
     * 
     * @return integer
     */
    public function getLastIdDataset()
    {
        return $this->queryOne('
            SELECT MAX(id) as id
            FROM datasets');
    }
    
    /**
     * Starts DB transaction
     */
    public static function beginTransaction()
    {
        if(self::$active_transaction)
        {
            return;
        }

        self::$connection->beginTransaction();
        self::$active_transaction = true;
    }
    
    /**
     * Commits DB transaction
     */
    public static function commitTransaction()
    {
        if(!self::$active_transaction)
        {
            return;
        }

        self::$connection->commit();
        self::$active_transaction = false;
    }
    
    /**
     * Rollbacks DB transaction
     */
    public static function rollbackTransaction()
    {
        if(!self::$active_transaction)
        {
            return;
        }

        self::$connection->rollBack();
        self::$active_transaction = false;
    }
    
    /**
     * Checks user's access
     * 
     * @param string $type
     * @param array $data
     * 
     * @return boolean
     */
    public function verify_user($type, $data = array())
    {
        $id_user = session::user_id();
        $id_groups = $this->queryAll("SELECT DISTINCT id_group FROM gp_usr WHERE id_user = ?", array($id_user));

        switch($type)
        {
            case 'edit_dataset':
                // Check user rights
                $verify_usr = $this->queryOne("SELECT * FROM access_ds_usr WHERE id_dataset = ? AND id_user = ?", array($data['id_dataset'], $id_user));
                if($verify_usr)
                {
                    return true;
                }
                
                // Check group rights
                foreach($id_groups as $id_g)
                {
                    $verify_gr = $this->queryOne("SELECT * FROM access_ds_gp WHERE id_gp = ? AND id_dataset = ?", array($id_g['id_group'], $data['id_dataset']));
                    if($verify_gr)
                    {
                        return true;
                    }
                }
                
                return false;
                break;
        }
    }
}


