<?php

/**
 * Config class
 */
class Config
{
    // DB instance holder
    private $db;

    /**
     * Constructor
     */
    function __construct()
    {
        $this->db = new Configs();
    }

    /**
     * Sets new value to the given attribute
     * 
     * @param string $attr
     * @param string $value
     * 
     */
    public function set($attr, $value)
    {
        if(!$this->db->is_valid_attribute($attr))
        {
            throw new Exception("Invalid config attribute: $attr");
        }

        $id = $this->attr_exists($attr);

        $detail = new Configs($id);

        $detail->attribute = $attr;
        $detail->value = $value;

        $detail->check_value();

        $detail->save();
    }

    /**
     * Returns attr value
     * 
     * @param string $attr
     * 
     * @return string|null
     */
    public function get($attr)
    {
        $id = $this->attr_exists($attr);

        $detail = new Configs($id);

        // Not exists
        if(!$detail->id)
        {
            return NULL;
        }

        // If exists
        return $detail->value;
    }

    /**
     * Checks, if attribute exists
     * Returns id|null
     * 
     * @param string $attr
     * 
     * @return integer|null
     */
    private function attr_exists($attr)
    {
        $id = $this->db->where('attribute', $attr)
            ->select_list('id')
            ->get_one()
            ->id;

        return $id;
    }

}