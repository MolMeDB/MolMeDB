<?php

/**
 * Config class
 */
class Config
{
    // DB instance holder
    private static $db;


    /**
     * Init connection to the DB
     *
     * @return void
     */
    private static function init()
    {
        self::$db = new Configs();
    }

    /**
     * Sets new value to the given attribute
     * 
     * @param string $attr
     * @param string $value
     * 
     */
    public static function set($attr, $value)
    {
        if(!self::$db)
        {
            self::init();
        }

        $id = self::attr_exists($attr);

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
    public static function get($attr)
    {
        if(!self::$db)
        {
            self::init();
        }

        $id = self::attr_exists($attr);

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
    private static function attr_exists($attr)
    {
        $id = self::$db->where('attribute', $attr)
            ->select_list('id')
            ->get_one()
            ->id;

        return $id;
    }

}