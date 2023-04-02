<?php

/**
 * Transporter targets model
 * 
 * @param integer $id
 * @param string $name
 * @param string $uniprot_id
 * @param integer $id_user
 * @param datetime $create_datetime
 * 
 * @param Users $id_user 
 * 
 * @method Transporter_targets instance
 */
class Transporter_targets extends Db
{
    /**
     * Constructor
     * 
     * @param integer $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'transporter_targets';
        parent::__construct($id);
    }

    /**
     * Finds transporter targets
     * 
     * @param string $query
     * 
     * @return Transporter_targets
     */
    public function find($name, $uniprot)
    {
        $t = $this->queryOne(
            'SELECT id 
            FROM transporter_targets
            WHERE name LIKE ? OR uniprot_id LIKE ?
            LIMIT 1'
            ,array
            (
                $name,
                $uniprot
            )
        );
        
        return new Transporter_targets($t->id);
    }

    /**
     * Tries to find target by id/name or category 
     * - Can be set category structure also 
     * 
     * @return Transporter_targets[]
     */
    public function find_all($query)
    {
        $result = [];

        $by_identifier = $this->find($query, $query);

        if($by_identifier->id)
        {
            $result = [$by_identifier];
        }
        else
        {
            // Try to find category by path
            $cats = explode('.', $query);
            $cats_objects = [];

            foreach($cats as $c)
            {
                $exists = Enum_types::instance()->where(array
                (
                    'name LIKE' => $c,
                    'type'      => Enum_types::TYPE_TRANSPORTER_CATS
                ))->get_one();

                if(!$exists->id)
                {
                    ResponseBuilder::not_found('Transporter target/category `' . $c .'` not found.');
                }

                $cats_objects[] = $exists;
            }

            $current = array_shift($cats_objects);
            $curr_link_id = null;

            // Check category structure validity
            foreach($cats_objects as $c)
            {
                $link = Enum_type_links::instance()->where(array
                (
                    'id_enum_type' => $c->id,
                    'id_enum_type_parent' => $current->id,
                ) + ($curr_link_id ? ['id_parent_link' => $curr_link_id] : ['id_parrent_link IS NULL' => NULL]))
                ->get_one();

                if(!$link->id)
                {
                    ResponseBuilder::not_found('Category `' . $c->name . '` does not have parent category `' . $current->name . '`');
                }

                $current = $c;
                $curr_link_id = $link->id;
            }

            $link_ids = [];

            // Only one category provided
            if(!$curr_link_id)
            {
                $links = Enum_type_links::instance()->where('id_enum_type_parent', $current->id)->get_all();
                $link_ids = arr::get_values($links, 'id');
                $last_total = 0;

                while($last_total != count($link_ids))
                {
                    $last_total = count($link_ids);
                    $links = Enum_type_links::instance()->in('id_parent_link', $link_ids)->get_all();
                    $link_ids = array_unique(array_merge($link_ids, arr::get_values($links, 'id')));
                }
            }
            else
            {
                $links = Enum_type_links::instance()
                    ->where('id_parent_link', $curr_link_id)
                    ->get_all();
                $link_ids = array_merge(arr::get_values($links, 'id'), $curr_link_id);
            }

            if(count($link_ids))
            {
                $result = Transporter_targets::instance()
                    ->join('JOIN transporter_target_enum_type_links etl ON etl.id_transporter_target = transporter_targets.id')
                    ->in('etl.id_enum_type_link', $link_ids)
                    ->get_all();
            }
        }

        return $result;
    }

    /**
     * Get data to the whisper
     * 
     * @param string $query
     */
    public function loadSearchWhisper($query)
    {
        return $this->queryAll('
            SELECT DISTINCT tt.name, tt.uniprot_id
            FROM transporter_targets tt
            JOIN transporters t ON t.id_target = tt.id
            JOIN transporter_datasets td ON td.id = t.id_dataset
            WHERE (tt.name LIKE ? OR tt.uniprot_id LIKE ?) AND td.visibility = ?
            LIMIT 10
        ', array($query, $query, Transporter_datasets::VISIBLE), False);
    }


    
    /**
     * Returns enum_type_link for given transporter_targets
     * 
     * @return Enum_type_links
     */
    public function get_category_link()
    {
        if(!$this->id)
        {
            return null;
        }

        $row = $this->queryOne('
            SELECT etl.*
            FROM transporter_target_enum_type_links ml
            JOIN enum_type_links etl ON etl.id = ml.id_enum_type_link AND ml.id_transporter_targets = ?
        ', array($this->id));

        return new Enum_type_links($row->id);
    }

    /**
     * Set enum_type_link for given transporter_targets
     * 
     */
    public function set_category_link($link_id)
    {
        if(!$this->id || !$link_id)
        {
            throw new Exception('Invalid instance.');
        }

        return $this->query('
            INSERT INTO `transporter_target_enum_type_links` VALUES(?,?);
        ', array($link_id, $this->id));
    }


    /**
     * Returns methods without links
     */
    public function get_all_without_links()
    {
        return $this->queryAll('
            SELECT tt.*
            FROM transporter_targets tt
            LEFT JOIN transporter_target_enum_type_links metl ON metl.id_transporter_target = tt.id
            WHERE metl.id_enum_type_link IS NULL
            ORDER BY name
        ');
    }

    /**
     * Unlink category
     */
    public function unlink_category($id = NULL)
    {
        if(!$id && $this->id)
        {
            $id = $this->id;
        }

        return $this->query('
            DELETE FROM `transporter_target_enum_type_links`
            WHERE `id_transporter_target` = ?
        ', array($id));
    }

    /**
     * Returns targets linked to given category
     * 
     * @param int $enum_type_link_id
     * 
     * @return Iterable_object
     */
    public function get_linked_data($enum_type_link_id)
    {
        return $this->queryAll('
            SELECT tt.*
            FROM transporter_targets tt
            JOIN transporter_target_enum_type_links l ON l.id_transporter_target = tt.id AND l.id_enum_type_link = ?
        ', array($enum_type_link_id));
    }

    /**
     * Returns assigned substances
     * 
     * @return Iterable_object
     */
    public function get_substances($pagination = NULL, $per_page = 10)
    {
        $limit = '';

        if($pagination)
        {
            $limit = 'LIMIT ' . ($pagination-1)*$per_page . ',' . $per_page;
        }

        return $this->queryAll('
            SELECT DISTINCT s.*
            FROM transporters t
            JOIN substances s ON s.id = t.id_substance
            WHERE t.id_target = ?
            ' . $limit . '
        ', array($this->id));
    }

    /**
     * Counts assigned substances
     * 
     * @return int
     */
    public function count_substances()
    {
        return $this->queryOne('
            SELECT COUNT(*) as count
            FROM
            (
                SELECT DISTINCT s.*
                FROM transporters t
                JOIN substances s ON s.id = t.id_substance
                WHERE t.id_target = ?
            ) as t
        ', array($this->id))->count;
    }

    // /**
    //  * Returns substances for given transporter link
    //  * 
    //  * @return Iterable_object
    //  */
    // public function get_link_substances($link_id, $pagination = 1)
    // {
    //     return $this->queryAll('
    //         SELECT DISTINCT s.*
    //         FROM transporters t
    //         JOIN substances s ON s.id = t.id_substance
    //         JOIN transporter_targets tt ON tt.id = t.id_target AND tt.id = ?
    //         JOIN transporter_target_enum_type_links tetl ON tetl.id_transporter_target = tt.id
    //         JOIN enum_type_links etl ON etl.id = tetl.id_enum_type_link
    //         JOIN enum_types et ON et.id = etl.id_enum_type AND et.type = ?
    //         LIMIT ?,?
    //     ', array($link_id, Enum_types::TYPE_TRANSPORTER_CATS, ($pagination-1)*10, 10));
    // }

    /**
     * Counts substances for given transporter link
     * 
     * @return int
     */
    public function count_link_substances($link_id)
    {
        return $this->queryOne('
            SELECT COUNT(*) as count
            FROM
            (
                SELECT DISTINCT s.*
                FROM transporters t
                JOIN substances s ON s.id = t.id_substance
                JOIN transporter_targets tt ON tt.id = t.id_target AND tt.id = ?
                JOIN transporter_target_enum_type_links tetl ON tetl.id_transporter_target = tt.id
                JOIN enum_type_links etl ON etl.id = tetl.id_enum_type_link
                JOIN enum_types et ON et.id = etl.id_enum_type AND et.type = ?
            ) as tab
        ', array($link_id, Enum_types::TYPE_TRANSPORTER_CATS))->count;
    }
}