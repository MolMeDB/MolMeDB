<?php
/**
 * Iterable object
 */
class Iterable_object implements ArrayAccess, Iterator, Countable
{   
    protected $old_data = [];

    protected $data = [];

    protected $_position = 0;

    protected $size;

    protected $keys = [];

    protected $max_pos;

    private $debug = False;

    const T_HAS_ONE = 1;
    const T_HAS_MANY = 2;
    const T_HAS_MANY_AND_BELONGS_TO = 3;

    private $valid_link_types = array
    (
        self::T_HAS_ONE => 'has_one',
        self::T_HAS_MANY => 'has_many',
        self::T_HAS_MANY_AND_BELONGS_TO => 'has_many_and_belongs_to',
    );

    /**
     * MAIN CONSTRUCTOR for iterable_objects
     * Used also for making DB object instances returned by DB::QUERY_ONE/QUERY_ALL methods
     * 
     * @param array $data
     * @param boolean $multi_array - Instance of new classes
     */

    public function __construct($data = array(), $multi_array = False)
    {
        if(!is_array($data))
        {
            $this->data = $this->old_data = array($data);
        }

        if(!$multi_array)
        {
            $this->data = $this->old_data = $data;
        }
        else
        {
            $this->data = $this->old_data = $this->unify_data($data, $multi_array);
        }
        
        $this->size = count($this->data);
        $this->keys = array_keys(is_array($this->data) ? $this->data : []);
        $this->max_pos = count($this->keys);

        $this->get_links();
    }

    /**
     * Get links to other tables
     * 
     */
    private function get_links()
    {
        $db = new Db();
        $r = new ReflectionClass($this);
        $props = $r->getProperties();

        $data = array();

        foreach($props as $p)
        {
            if($p->class !== get_class($this) || 
                !in_array($p->name, $this->valid_link_types))
            {
                continue;
            }

            $attr = $p->name;

            $data[$attr] = $this->$attr;
        }

        // process data
        foreach($data as $type => $values)
        {
            // HAS ONE parsing
            if($type == $this->valid_link_types[self::T_HAS_ONE])
            {
                foreach($values as $key => $vals)
                {
                    if(is_array($vals))
                    {
                        // IS STRUCTURE VALID ?
                        if(!isset($vals['var']) || !isset($vals['class']))
                        {
                            $this->$key = NULL;
                            continue;
                        }

                        $class_name = $vals['class'];
                        $new_key = $vals['var'];
                        $k = $key;
                    }
                    else // Is set only value
                    {
                        $val = $vals;
                        $key = $val;

                        $new_key = ltrim($val, 'id_');
                        $k = 'id_' . $new_key;

                        $class_name = ucwords($new_key . 's');
                    }

                    
                    // Not valid input, set value to NULL
                    if (!class_exists($class_name) || !$this->$k) 
                    {
                        $this->$new_key = NULL;
                    } 
                    else 
                    {
                        $this->$new_key = new $class_name($this->$k);
                    }
                
                }
            }
            // HAS MANY AND BELONGS TO parsing
            else if($type == $this->valid_link_types[self::T_HAS_MANY_AND_BELONGS_TO])
            {
                foreach($values as $table => $vals)
                {
                    // IS STRUCTURE VALID ?
                    if(!is_array($vals) || !isset($vals['var']) || !isset($vals['class']) || 
                        !isset($vals['own']) || !isset($vals['remote']))
                    {
                        continue;
                    }

                    $class_name = $vals['class'];
                    $new_key = $vals['var'];
                    $remote_key = $vals['remote'];
                    $own_key = $vals['own'];

                    // Not valid input, set value to NULL
                    if (!class_exists($class_name) || !$this->id) 
                    {
                        $this->$new_key = NULL;
                        continue;
                    } 
                    
                    // Get data from M:N table
                    $rows = $db->queryAll("
                        SELECT $remote_key as id
                        FROM $table
                        WHERE $own_key = '$this->id'
                    ");

                    $ids = [];

                    foreach($rows as $r)
                    {
                        $ids[] = $r->id;
                    }

                    $new_class = new $class_name();
                    $this->$new_key = $new_class->in('id', $ids)->get_all();
                }
            }
            // HAS MANY parsing
            else if($type == $this->valid_link_types[self::T_HAS_MANY])
            {
                foreach($values as $table => $vals)
                {
                    // IS STRUCTURE VALID ?
                    if(!is_array($vals) || !isset($vals['var']) || !isset($vals['class']) || 
                        !isset($vals['own']))
                    {
                        continue;
                    }

                    $class_name = $vals['class'];
                    $new_key = $vals['var'];
                    $own_key = $vals['own'];


                    // Not valid input, set value to NULL
                    if (!class_exists($class_name) || !$this->id) 
                    {
                        $this->$new_key = NULL;
                        continue;
                    } 

                    $new_class = new $class_name();
                    $this->$new_key = $new_class->where($own_key, $this->id)->get_all();
                }
            }
        }
    }

    /**
     * Implemented count method
     * 
     * @return int
     */
    public function count()
    {
        if(is_object($this->data))
        {
            return count($this->data->as_array());
        }

        return count($this->data);
    }


    /**
     * 
     */
    private function unify_data($data, $class_instance = NULL)
    {
        if(!is_array($data))
        {
            return array($data);
        }

        $result = array();

        foreach($data as $key => $row)
        {
            if(is_array($row))
            {
                if(!class_exists($class_instance))
                {
                    $class_instance = get_class($this);
                }

                $result[$key] = new $class_instance($row);
                continue;
            }

            $result[$key] = $this->unify_data($row, $class_instance);
        }

        return $result;
    }

    // START of methods required by Iterator interface

    /**
     * Returns current element
     */
    public function current()
    {
        if($this->debug)
        {
            echo __METHOD__ . ' / ';
        }

        if($this->offsetExists($this->keys[$this->_position]))
        {
            return $this->data[$this->keys[$this->_position]];
        }

        return $this->data[$this->_position];
    }

    /**
     * Returns key of current element
     */
    public function key()
    {
        if ($this->debug) {
            echo __METHOD__ . ' -> ' . $this->_position . ' -> ';
        }

        if(isset($this->keys[$this->_position]))
        {
            if($this->debug)
            {
                echo 'T1: ' .  $this->keys[$this->_position] . ' / ';
            }
            return $this->keys[$this->_position];
        }


        if ($this->debug) 
        {
            echo 'T2:' . $this->_position . ' / ';
        }

        return $this->_position;
    }

    /**
     * Moves current position to next element
     */
    public function next()
    {
        if ($this->debug) {
            echo __METHOD__ . ' / ';
        }
        $this->_position++;
    }

    /**
     * Rewinds back to the first element
     */
    public function rewind()
    {
        if ($this->debug) {
            echo __METHOD__ . ' / ';
        }
        $this->_position = 0;
    }

    /**
     * Checks if current position is valid
     */
    public function valid()
    {
        if ($this->debug) {
            echo __METHOD__ . ' - ' . $this->_position . ' / ';
        }
        return $this->offsetExists($this->_position); //null !== $this->data[$this->_position];
    }
    // END of methods required by Iterator interface

    /**
     * Get a data by key
     *
     * @param string The key data to retrieve
     * @access public
     */
    public function __get($key)
    {
        if ($this->debug) {
            echo __METHOD__ . ' / ';
        }
        return $this->offsetExists($key) ? (isset($this->data[$key]) ? $this->data[$key] : $this->data[$this->keys[$key]]) : NULL;
    }

    /**
     * Assigns a value to the specified data
     *
     * @param string The data key to assign the value to
     * @param mixed  The value to set
     * @access public
     */
    public function __set($key, $value)
    {
        if ($this->debug) {
            echo __METHOD__ . ' / ';
        }

        $this->data[$key] = $value;
        $this->keys[] = $key;
    }

    /**
     * Whether or not an data exists by key
     *
     * @param string An data key to check for
     * @access public
     * @return boolean
     * @abstracting ArrayAccess
     */
    public function __isset($key)
    {
        if ($this->debug) {
            echo __METHOD__ . ' / ';
        }
        return isset($this->data[$key]) || (isset($this->keys[$key]) && isset($this->data[$this->keys[$key]]));
    }

    /**
     * Unsets an data by key
     *
     * @param string The key to unset
     * @access public
     */
    public function __unset($key)
    {
        unset($this->data[$key]);
        array_pop($this->keys[$key]);
    }

    /**
     * Assigns a value to the specified offset
     *
     * @param string The offset to assign the value to
     * @param mixed  The value to set
     * @access public
     * @abstracting ArrayAccess
     */
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) 
        {
            $this->data[] = $value;
        } 
        else
        {
            $this->data[$offset] = $value;
        }
    }

    /**
     * Whether or not an offset exists
     *
     * @param string An offset to check for
     * @access public
     * @return boolean
     * @abstracting ArrayAccess
     */
    public function offsetExists($offset)
    {
        return isset($this->data[$offset]) || (isset($this->keys[$offset]) && isset($this->data[$this->keys[$offset]]));
    }

    /**
     * Unsets an offset
     *
     * @param string The offset to unset
     * @access public
     * @abstracting ArrayAccess
     */
    public function offsetUnset($offset)
    {
        if ($this->offsetExists($offset)) 
        {
            unset($this->data[$offset]);
        }
    }

    /**
     * Returns the value at specified offset
     *
     * @param string The offset to retrieve
     * @access public
     * @return mixed
     * @abstracting ArrayAccess
     */
    public function offsetGet($offset)
    {
        if($this->debug)
        {
            echo __METHOD__;
        }
        return $this->offsetExists($offset) ? (isset($this->data[$offset]) ? $this->data[$offset] : $this->data[$this->keys[$offset]]) : null;
    }

    /**
     * 
     */
    public function as_array($params = Null, $remove_inner_objects = False)
    {
        if ($params && !is_object($params) && !is_array($params)) 
        {
            return (array) $params;
        }

        $result = [];
        $iterable = $params ? $params : $this->data;

        foreach($iterable as $key => $row)
        {
            if(is_a($row, 'Iterable_object', False))
            {
                if($remove_inner_objects)
                {
                    continue;
                }
                
                $result[$key] = $row->as_array();
            }
            else
            {
                $result[$key] = $row;
            }
        }

        return $result;
    }

    /**
     * Returns changes in object
     * 
     * @return Iterable_object|array
     */
    public function get_changes($as_array = FALSE)
    {
        $prev_data = $this->old_data;
        $curr_data = new Iterable_object($this->data);
        $diff = array();
        $mss = "x@#sd#";

        foreach($prev_data as $p_key => $p_val)
        {
            if(is_array($p_val) || is_object($p_val))
            {
                continue;
            }

            $str_val = $p_val === NULL ? "NULL" : trim(strval($p_val));
            $curr_str_val = !is_array($curr_data->$p_key) && !is_object($curr_data->$p_key) ? ($curr_data->$p_key === NULL || trim(strval($curr_data->$p_key)) === '' ? "NULL" : trim(strval($curr_data->$p_key))) : $mss;

            // echo $p_key . ' - ' . $str_val . ' / ' . $curr_str_val . '<br/>';

            if($curr_str_val !== $mss && $curr_str_val !== $str_val)
            {
                $diff[$p_key] = array
                (
                    'prev' => $str_val,
                    'curr' => $curr_str_val
                );
            }
        }

        return $as_array ? $diff : new Iterable_object($diff, true);
    }
}


