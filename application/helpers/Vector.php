<?php

/**
 * Class for handling with vectors
 * 
 * @author Jakub Juracka
 */
class Vector implements Countable
{
    /**
     * @var array
     */
    private $data = [];

    /**
     * Constructor
     */
    public function __construct($data = [], $as_int = false)
    {
        if($as_int)
        {
            $data = array_map('intval', $data);
        }

        $this->data = $data;
    }

    /**
     * Count number of elements
     * 
     * @return int
     */
    public function count() : int
    {
        return count($this->data);
    }

    /**
     * Checks, if current Vector is valid fingerprint
     * 
     * @return boolean
     */
    public function is_mol_fingerprint()
    {
        return Mol_fingerprint::is_valid_decoded($this->__toString());
    }

    /**
     * Vector dot product
     * 
     * @param Vector $vector_1
     * @param Vector $vector_2
     * 
     * @return int|null - Null if error
     */
    public static function dot($vector_1, $vector_2)
    {
        $v1 = $vector_1->as_array();
        $v2 = $vector_2->as_array();
        
        if(count($v1) !== count($v2))
        {
            return null;
        }

        $result = 0;

        for($i = 0; $i < count($v1); $i++)
        {
            $result += $v1[$i]*$v2[$i];
        }

        return $result;
    }

    /**
     * @return array
     */
    public function as_array() : array
    {
        return $this->data;
    }

    /**
     * @return string
     */
    function __toString()
    {
        return implode('', $this->data);
    }
}

