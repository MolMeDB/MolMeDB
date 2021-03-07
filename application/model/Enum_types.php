<?php

/**
 * Enum types model
 * 
 * @property integer $id
 * @property string $name
 * @property string $content
 * @property string $type
 * 
 */
class Enum_types extends Db
{
    /** TYPE - MEMBRANE CATEGORIES */
    const TYPE_MEMBRANE_CATS = 1;

    /** TYPE - METHOD CATEGORIES */
    const TYPE_METHOD_CATS = 2;

    /** TYPE - TRANSPORTER CATEGORIES */
    const TYPE_TRANSPORTER_CATS = 3;

    public static $valid_types = array
    (
        self::TYPE_MEMBRANE_CATS,
        self::TYPE_METHOD_CATS,
        self::TYPE_TRANSPORTER_CATS
    );

    /**
     * Check, if type is valid
     */
    public static function is_type_valid($type)
    {
        return in_array($type, self::$valid_types);
    }

    /**
     * constructor
     */
    public function __construct($id = NULL)
    {
        $this->table = 'enum_types';
        parent::__construct($id);
    }

    /**
     * Returns enum type structure for tree
     * 
     * @return Iterable_object
     */
    public function get_structure()
    {
        function get_item($item)
        {
            $et = new Enum_types();
            $etl = new Enum_type_links($item->link_id);
            $children = $et->get_enum_children($item->link_id);
            $items = $etl->get_items();

            $res = array
            (
                'name' => $item->name,
                'id_link'   => $item->link_id,
                'type'      => $etl->enum_type->type,
                'regexp'    => $etl->reg_exp,
                'children' => array()
            );

            foreach($children as $ch)
            {
                $res['children'][] = get_item($ch);
            }

            foreach($items as $i)
            {
                $res['children'][] = new Iterable_object(array
                (
                    'name' => $i->name,
                    'id'   => $i->id,
                    'type' => $i->type,
                    'regexp'    => $i->reg_exp,
                    'children' => NULL
                ));
            }

            $res['children'] = new Iterable_object($res['children']);

            // return $res;
            return new Iterable_object($res);
        }

        $top_sections = $this->queryAll('
            SELECT DISTINCT et.*, etl.id as link_id, etl.reg_exp
            FROM enum_type_links etl
            JOIN enum_types et ON et.id = etl.id_enum_type AND etl.id_parent_link IS NULL
            ORDER BY et.name ASC
        ');

        $result = array();

        foreach($top_sections as $section)
        {
            $result[] = get_item($section);
        }

        return new Iterable_object($result);
    }


    /**
     * Returns children for enum_type
     * 
     * @return Iterable_object
     */
    public function get_enum_children($link_id = NULL)
    {
        return $this->queryAll('
            SELECT DISTINCT et.*, etl.id as link_id, etl.reg_exp
            FROM enum_type_links etl
            JOIN enum_types et ON et.id = etl.id_enum_type AND etl.id_parent_link = ?
            ORDER BY et.name ASC
        ', array($link_id));
    }

    /**
     * Returns parent element
     * 
     * @return Enum_types
     */
    public function get_parent($id = NULL)
    {
        if($this->id && !$id)
        {
            $id = $this->id;
        }

        $parent = $this->queryOne('
            SELECT et.*
            FROM enum_type_links etl
            JOIN enum_types et ON et.id = etl.id_enum_type_parent
            WHERE etl.id_enum_type = ?
        ', array($id));

        return new Enum_types($parent->id);
    }

    /**
     * Checks, if given enum_type has link
     * 
     * @return boolean
     */
    public function has_link()
    {
        $exists = $this->queryAll('
            SELECT *
            FROM enum_type_links
            WHERE id_enum_type = ? OR id_enum_type_parent = ?
        ', array($this->id, $this->id));
        
        return count($exists);
    }
}
