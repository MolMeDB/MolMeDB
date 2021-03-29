<?php

/**
 * Enum type links model
 * 
 * @property integer $id
 * @property string $id_enum_type
 * @property string $id_enum_type_parent
 * @property string $id_parent_link
 * @property string $data
 * @property string $reg_exp
 * @property Enum_types $enum_type
 * @property Enum_types $enum_type_parent
 * @property Enum_type_links $parent_link
 */
class Enum_type_links extends Db
{
    public $has_one = array
    (
        'id_enum_type' => array
		(
			'var' => 'enum_type',
			'class' => 'Enum_types'		
        ),
        'id_enum_type_parent' => array
		(
			'var' => 'enum_type_parent',
			'class' => 'Enum_types'		
        ),
        'id_parent_link' => array
        (
            'var' => 'parent_link',
            'class' => 'Enum_type_links'
        )
    );

    /**
     * constructor
     */
    public function __construct($id = NULL)
    {
        $this->table = 'enum_type_links';
        parent::__construct($id);
    }

    /**
     * Return record by id_enum_type - IS UNIQUE
     * 
     * @return Enum_type_links
     */
    public function get_by_enum_type_id($id_enum_type)
    {
        return $this->where('id_enum_type', $id_enum_type)->get_one();
    }

    /**
     * Redefine save
     */
    public function save_check()
    {
        // First check, if new link can be created
        if(!$this->id_enum_type || !$this->id_enum_type_parent)
        {
            throw new Exception('Cannot save - invalid instance.');
        }

        if($this->id_enum_type === $this->id_enum_type_parent)
        {
            throw new Exception('Element cannot be in relation to itself.');
        }


        // Get all possible childrens from current enum_type
        $children = [];
        $el = new Enum_types($this->id_enum_type_parent);
        $parent = $el->parent_link;

        while($parent && $parent->id)
        {
            $children[] = $parent->id;
            $parent = $parent->parent_link;
        }

        if(in_array($this->id_enum_type, $children))
        {
            throw new Exception('Cannot add section link - Danger of loop formation.');
        }

        $this->save();
    }

    /**
     * @return array
     */
    private static function get_children($children)
    {
        $ids = array();

        foreach($children as $ch)
        {
            $et = new Enum_types();

            $ids[$ch->id] = $ch->id;
            $ids =  $ids + self::get_children($et->get_enum_children($ch->id));
        }

        return $ids;
    }

    /**
     * Returns direct children links
     * 
     * @
     */
    public function get_direct_children_links($id = NULL)
    {
        if($this->id && !$id)
        {
            $id = $this->id;
        }

        return $this->queryAll('
            SELECT *
            FROM enum_type_links etl
            WHERE etl.id_parent_link = ?
        ', array($id));
    }

    /**
     * Returns items
     * 
     * @return Iterable_object
     */
    public function get_items()
    {
        if(!$this)
        {
            return new Iterable_object();
        }

        if($this->enum_type->type == Enum_types::TYPE_MEMBRANE_CATS)
        {
            $table = 'membrane';
        }
        else if($this->enum_type->type == Enum_types::TYPE_METHOD_CATS)
        {
            $table = 'method';
        }
        else if($this->enum_type->type == Enum_types::TYPE_TRANSPORTER_CATS)
        {
            $table = 'transporter_target';
        }

        return $this->queryAll("    
            SELECT tab.id, tab.name, " . $this->enum_type->type . " as type
            FROM " . $table . "s tab
            JOIN " . $table . "_enum_type_links metl ON metl.id_" . $table . " = tab.id AND metl.id_enum_type_link = ?
            JOIN enum_type_links etl ON etl.id = metl.id_enum_type_link
        ", array($this->id)); 
    }

    /**
     * Moves items to the new link
     * 
     * @param int $new_link_id
     */
    public function move_items($new_link_id)
    {
        $new = new Enum_type_links($new_link_id);

        if(!$this->id || !$new->id)
        {
            throw new Exception('Cannot move items.');
        }

        if($this->enum_type->type == Enum_types::TYPE_MEMBRANE_CATS)
        {
            return $this->query('
                UPDATE `membrane_enum_type_links`
                SET `id_enum_type_link` = ?
                WHERE `id_enum_type_link` = ?
            ', array($new->id, $this->id));
        }
        else if($this->enum_type->type == Enum_types::TYPE_METHOD_CATS)
        {
            return $this->query('
                UPDATE `method_enum_type_links`
                SET `id_enum_type_link` = ?
                WHERE `id_enum_type_link` = ?
            ', array($new->id, $this->id));
        }
        else if($this->enum_type->type == Enum_types::TYPE_TRANSPORTER_CATS)
        {
            return $this->query('
                UPDATE `transporter_target_enum_type_links`
                SET `id_enum_type_link` = ?
                WHERE `id_enum_type_link` = ?
            ', array($new->id, $this->id));
        }
    }

    /**
     * Returns all regexps of given type
     * 
     * @param int $type
     */
    public function get_regexps($type)
    {
        if(!Enum_types::is_type_valid($type))
        {
            throw new Exception('invalid type.');
        }

        return $this->queryAll('
            SELECT etl.id, etl.reg_exp
            FROM enum_type_links etl
            JOIN enum_types et ON et.id = etl.id_enum_type
            WHERE et.type = ? AND etl.reg_exp IS NOT NULL
            ORDER BY etl.id_parent_link DESC
        ', array($type));
    }
}
