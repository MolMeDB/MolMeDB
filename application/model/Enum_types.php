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

    /** TYPE - FUNCTIONAL GROUPS of chemical compounds */
    const TYPE_FRAGMENT_CATS = 11;

    public static $valid_types = array
    (
        self::TYPE_MEMBRANE_CATS,
        self::TYPE_METHOD_CATS,
        self::TYPE_TRANSPORTER_CATS,
        self::TYPE_FRAGMENT_CATS
    );

    /**
     * @return Enum_types
     */
    public static function instance()
    {
        return parent::instance();
    }

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
     * Returns enum types by type
     * 
     * @return Enum_types[]|null
     */
    public static function get_by_type($type)
    {
        if(!self::is_type_valid($type))
        {
            return null;
        }

        return self::instance()->where('type', $type)->get_all();
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
            JOIN enum_types et ON et.id = etl.id_enum_type AND etl.id_parent_link IS NULL AND et.type IN (?,?,?)
            ORDER BY et.name ASC
        ', array(Enum_types::TYPE_MEMBRANE_CATS, Enum_types::TYPE_METHOD_CATS, Enum_types::TYPE_TRANSPORTER_CATS));

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


    public function get_items($link_id, $suffix)
    {
        $et = new Enum_types();

        $items = $et->queryAll("
            SELECT DISTINCT t.*
            FROM " . $suffix . "s as t
            JOIN " . $suffix . "_enum_type_links tetl ON tetl.id_" . $suffix . " = t.id AND tetl.id_enum_type_link = ?
        ", array($link_id))->as_array();

        $children = $et->get_enum_children($link_id);

        foreach($children as $ch)
        {
            $s = $this->get_items($ch->link_id, $suffix);
            $items = array_merge($items, $s);
        }

        return $items;
    }


    /**
     * Returns all links elemenets
     * 
     * @return Iterable_object
     */
    public function get_link_elements($link_id, $offset = NULL, $LIMIT = NULL)
    {
        $link = new Enum_type_links($link_id);

        if(!$link->id)
        {
            throw new Exception('Invalid link_id.');
        }

        $limit = '';

        if($LIMIT && $offset !== NULL)
        {
            $limit = "LIMIT $offset,$LIMIT";
        }
        else if($LIMIT)
        {
            $limit = "LIMIT $LIMIT";
        }

        $type = $link->enum_type->type;

        if($type == self::TYPE_MEMBRANE_CATS)
        {
            $suffix = 'membrane';
        }
        else if($type == self::TYPE_METHOD_CATS)
        {
            $suffix = 'method';
        }
        else if($type == self::TYPE_TRANSPORTER_CATS)
        {
            $suffix = 'transporter_target';
        }
        else
        {
            throw new Exception('Invalid enum_type.');
        }

        $items = $this->get_items($link->id, $suffix);
        $item_ids = [];

        foreach($items as $i)
        {
            $item_ids[] = $i['id'];
        }

        $item_ids = array_unique($item_ids);

        if(!count($item_ids))
        {
            return new Iterable_object();
        }

        $item_ids_string = "'" . implode("','", $item_ids) . "'";

        if($type == self::TYPE_MEMBRANE_CATS)
        {
            // return $this->queryAll('
            //     SELECT DISTINCT s.* 
            //     FROM substances as s
            //     JOIN interactions i 
            // ');
        }
        else if($type == self::TYPE_METHOD_CATS)
        {
            $suffix = 'method';
        }
        else if($type == self::TYPE_TRANSPORTER_CATS)
        {
            $result = $this->queryAll('
                SELECT DISTINCT s.* 
                FROM substances as s
                JOIN transporters t ON t.id_substance = s.id AND t.id_target IN (' . $item_ids_string . ')
                 ' . $limit . '
            ');
            $total = $this->queryOne('
                SELECT COUNT(*) as count
                FROM (
                    SELECT DISTINCT s.* 
                    FROM substances as s
                    JOIN transporters t ON t.id_substance = s.id AND t.id_target IN (' . $item_ids_string . ')
                ) as t
            ')->count;
        }

        return (object)array
        (
            'data' => $result,
            'total' => $total
        );
    }


    /**
     * Returns all links interactions
     * 
     * @return Iterable_object
     */
    public function get_link_interactions($link_id)
    {
        $link = new Enum_type_links($link_id);

        if(!$link->id)
        {
            throw new Exception('Invalid link_id.');
        }

        $type = $link->enum_type->type;

        if($type == self::TYPE_MEMBRANE_CATS)
        {
            $suffix = 'membrane';
        }
        else if($type == self::TYPE_METHOD_CATS)
        {
            $suffix = 'method';
        }
        else if($type == self::TYPE_TRANSPORTER_CATS)
        {
            $suffix = 'transporter_target';
        }
        else
        {
            throw new Exception('Invalid enum_type.');
        }

        $items = $this->get_items($link->id, $suffix);
        $item_ids = [];

        foreach($items as $i)
        {
            $item_ids[] = $i['id'];
        }

        $item_ids = array_unique($item_ids);

        if(!count($item_ids))
        {
            return new Iterable_object();
        }

        $item_ids_string = "'" . implode("','", $item_ids) . "'";

        if($type == self::TYPE_MEMBRANE_CATS)
        {
            // return $this->queryAll('
            //     SELECT DISTINCT s.* 
            //     FROM substances as s
            //     JOIN interactions i 
            // ');
        }
        else if($type == self::TYPE_METHOD_CATS)
        {
            $suffix = 'method';
        }
        else if($type == self::TYPE_TRANSPORTER_CATS)
        {
            return $this->queryAll('
                SELECT DISTINCT s.name, s.identifier, s.SMILES, s.inchikey, s.MW, s.LogP, s.pubchem, s.drugbank, s.pdb, s.chEMBL,
                    tt.name as target, tt.uniprot_id as uniprot, t.type, t.note, t.Km, t.Km_acc, t.EC50, t.EC50_acc, t.Ki, t.Ki_acc,
                    t.IC50, t.IC50_acc, p1.citation as primary_reference, p2.citation as secondary_reference
                FROM substances s
                JOIN transporters t ON t.id_substance = s.id AND t.id_target IN (' . $item_ids_string . ')
                JOIN transporter_targets tt ON tt.id = t.id_target
                JOIN transporter_datasets td ON td.id = t.id_dataset
                LEFT JOIN publications p1 ON p1.id = t.id_reference
                LEFT JOIN publications p2 ON p2.id = td.id_reference
            ');
        }
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

    /**
     * Returns categories for given type
     * 
     * @param int $type
     * 
     * @return array
     */
    public function get_categories($type)
    {
        if(!self::is_type_valid($type))
        {
            return [];
        }

        if(!function_exists('get_inner_categories'))
        {
            function get_inner_categories($link_id, $fixed = false)
            {
                $link = new Enum_type_links($link_id);
                $res = [];

                $items = $link->where(array
                    (
                        'id_parent_link' => $link_id,
                        'et.type' => $link->enum_type->type
                    ))
                    ->join('enum_types et ON et.id = enum_type_links.id_enum_type')
                    ->select_list('enum_type_links.id as id, et.id as id_enum_type, et.name as name, et.type as type')
                    ->order_by('name')
                    ->get_all();

                if($link->enum_type->type == Enum_types::TYPE_MEMBRANE_CATS)
                {
                    $model = new Membranes();
                }
                elseif($link->enum_type->type == Enum_types::TYPE_METHOD_CATS)
                {
                    $model = new Methods();
                }
                elseif($link->enum_type->type == Enum_types::TYPE_TRANSPORTER_CATS)
                {
                    $model = new Transporter_targets();
                    $t_model = new Transporters();
                }

                foreach($items as $i)
                {
                    $ch = get_inner_categories($i->id);

                    // Get membrane/methods.... 
                    $ms = [];
                    $total = 1;

                    if(isset($model))
                    {
                        $ms = $model->get_linked_data($i->id);
                    }

                    $d = array
                    (
                        'name' => $i->name,
                        'id_element' => $i->id,
                        'id_enum_type' => $i->id_enum_type,
                        'fixed' => $fixed,
                        'children' => []
                    );

                    if(count($ch))
                    {
                        $ch = Arr::sort_by_key($ch, 'name');
                        $d['children'] = $ch;
                    }

                    if(count($ms))
                    {
                        foreach($ms as $m)
                        {
                            if($i->type == Enum_types::TYPE_TRANSPORTER_CATS)
                            {
                                $total = $t_model->where('id_target', $m->id)->count_all();
                            }

                            $d['children'][] = array
                            (
                                'name' => $m->name,
                                'id_element' => $m->id,
                                'id_enum_type' => $i->id_enum_type,
                                'value' => $total,
                                'last' => 1
                            );  
                        }
                    }

                    $d['children'] = Arr::sort_by_key($d['children'], 'name');
                    $res[] = $d;
                }

                return $res;
            }
        }

        // Get init ids
        $e = $this->queryOne('
                SELECT DISTINCT etl.id, et.name, et.id as id_enum_type 
                FROM enum_type_links etl
                JOIN enum_types et ON et.id = etl.id_enum_type 
                WHERE id_enum_type = ? AND id_enum_type = id_enum_type_parent
            ', array($type));

        $result = get_inner_categories($e->id, True);

        $link = new Enum_type_links($e->id);

        // Add uncategorized data
        if($link->enum_type->type == Enum_types::TYPE_MEMBRANE_CATS)
        {
            $model = new Membranes();
        }
        elseif($link->enum_type->type == Enum_types::TYPE_METHOD_CATS)
        {
            $model = new Methods();
        }
        elseif($link->enum_type->type == Enum_types::TYPE_TRANSPORTER_CATS)
        {
            $model = new Transporter_targets();
            $t_model = new Transporters();
        }

        $unclass = $model->get_all_without_links();

        if(count($unclass))
        {
            
            $d = array 
            (
                'name' => 'Unclassified',
                'id_element' => NULL,
                'fixed' => True,
                'children' => []
            );

            foreach($unclass as $u)
            {
                if($link->enum_type->type == Enum_types::TYPE_TRANSPORTER_CATS)
                {
                    $total = $t_model->where('id_target', $u->id)->count_all();
                }
                else
                {
                    $total = 1;
                }

                $d['children'][] = array
                (
                    'name' => $u->name,
                    'id_element' => $u->id,
                    'id_enum_type' => $u->id_enum_type,
                    'value' => $total,
                    'last' => true
                );
            }

            $result[] = $d;
        }

        return $result;
    }

}
