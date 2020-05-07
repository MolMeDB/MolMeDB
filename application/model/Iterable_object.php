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

    private $valid_link_types = array
    (
        self::T_HAS_ONE => 'has_one'
    );

    /**
     * MAIN CONSTRUCTOR for iterable_objects
     * Used also for making DB object instances returned by DB::QUERY_ONE/QUERY_ALL methods
     * 
     * @param array $data
     * @param boolean $multi_array - Instance of new classes
     */
    public function __construct($data, $multi_array = False)
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
                foreach($values as $key => $values)
                {
                    if(is_array($values))
                    {
                        // IS STRUCTURE VALID ?
                        if(!isset($values['var']) || !isset($values['class']))
                        {
                            $this->$key = NULL;
                            continue;
                        }

                        $class_name = $values['class'];
                        $new_key = $values['var'];
                    }
                    else // Is set only value
                    {
                        $val = $values;

                        $key = $val;

                        $new_key = str_replace('id_', "", $val);

                        $class_name = ucwords($new_key . 's');
                    }

                    // Not valid input, set value to NULL
                    if (!class_exists($class_name) || !$this->$key) 
                    {
                        $this->$new_key = NULL;
                    } 
                    else 
                    {
                        $this->$new_key = new $class_name($this->$key);
                    }
                
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
}


