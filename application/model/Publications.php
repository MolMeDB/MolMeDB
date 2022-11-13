<?php

/**
 * Publications model
 * 
 * @property integer $id
 * @property integer $type
 * @property string $citation
 * @property string $doi
 * @property integer $pmid
 * @property string $title
 * @property string $authors
 * @property string $journal
 * @property string $volume
 * @property string $issue
 * @property string $page
 * @property string $year
 * @property date $publicated_date
 * @property integer $user_id
 * @property string $pattern
 * @property int $total_passive_interactions
 * @property int $total_active_interactions
 * @property int $total_substances
 * @property datetime $createDateTime
 * @property datetime $editDateTime
 * 
 */
class Publications extends Db
{
    /** TYPES */
    const PUBCHEM = 1;
    const CHEMBL = 2;

    /** Valid types */
    private static $valid_types = array
    (
        self::PUBCHEM,
        self::CHEMBL
    );

    /**
     * Constructor
     * 
     * @param integer $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'publications';
        parent::__construct($id);
    }

    /**
     * Get publication by type
     * 
     * @param int $type
     * 
     * @return Publications
     */
    public function get_by_type($type)
    {
        if(!in_array($type, self::$valid_types))
        {
            return new Iterable_object([]);
        }

        return $this->where('type', $type)
            ->get_one();
    }

    /**
     * Returns assigned substances
     * 
     * @param int $pagination
     * 
     * @return Iterable_object
     */
    public function get_assigned_substances($pagination)
    {
        if($pagination < 1)
        {
            $pagination = 1;
        }

       return $this->queryAll('
            SELECT *
            FROM substances
            WHERE id IN
            (
                SELECT i.id_substance
                FROM interaction i 
                JOIN datasets d ON d.id = i.id_dataset AND d.visibility = 1
                WHERE i.id_reference = ? OR d.id_publication = ?
                
                UNION

                SELECT t.id_substance
                FROM transporters t
                JOIN transporter_datasets td ON td.id = t.id_dataset AND td.visibility = 1
                WHERE td.id_reference = ? OR t.id_reference = ?
            ) 
            LIMIT ?,10
        ', array($this->id, $this->id, $this->id, $this->id, ($pagination-1)*10));
    }

    /**
     * Counts assigned substances
     * 
     * @return int
     */
    public function count_assigned_substances()
    {
        return $this->queryOne('
            SELECT COUNT(*) as count
            FROM
            (SELECT DISTINCT id
                FROM substances
                WHERE id IN
                (
                    SELECT DISTINCT i.id_substance
                    FROM interaction i 
                    JOIN datasets d ON d.id = i.id_dataset AND d.visibility = 1
                    WHERE i.id_reference = ? OR d.id_publication = ?
                    
                    UNION

                    SELECT DISTINCT t.id_substance
                    FROM transporters t
                    JOIN transporter_datasets td ON td.id = t.id_dataset AND td.visibility = 1
                    WHERE td.id_reference = ? OR t.id_reference = ?
                )
            ) as t2
            ', array($this->id, $this->id, $this->id, $this->id))->count;
    }


    /**
     * Returns only references with assigned some interaction
     * 
     * @return Iterable
     */
    public function get_nonempty_refs()
    {
        return $this->queryAll('
            SELECT DISTINCT *
            FROM publications
            WHERE id IN (
                SELECT id_reference
                FROM interaction as i
                WHERE id_reference > 0 AND i.visibility = 1

                UNION 

                SELECT id_publication 
                FROM datasets
                WHERE id_publication > 0 AND visibility = 1

                UNION 

                SELECT t.id_reference
                FROM transporters t
                JOIN transporter_datasets td ON td.id = t.id_dataset
                WHERE td.visibility = 1 AND t.id_reference > 0

                UNION 

                SELECT id_reference
                FROM transporter_datasets
                WHERE visibility = 1 AND id_reference > 0
            )
            ORDER BY citation
        ');
    }

    /**
     * Returns empty publications
     * 
     * @return Iterable_object
     */
    public function get_empty_publications()
    {
        $data =  $this->queryAll('
            SELECT DISTINCT p.*
            FROM publications p
            LEFT JOIN interaction i ON i.id_reference = p.id
            LEFT JOIN datasets d ON d.id_publication = p.id
            LEFT JOIN transporters t ON t.id_reference = p.id
            LEFT JOIN transporter_datasets td ON td.id_reference = p.id
            WHERE i.id IS NULL AND d.id IS NULL AND t.id IS NULL AND td.id IS NULL
        ');

        $res = [];

        foreach($data as $row)
        {
            $res[] = new Publications($row->id);
        }

        return new Iterable_object($res);
    }

    /**
     * Load data from file
     * 
     * deprecated
     */
    public function loadData($filename)
    {
        return file($filename);    
    }

    /**
     * Returns passive interaction for given publication
     * 
     * @return Iterable_object
     */
    public function get_passive_transport_data()
    {
        if(!$this->id)
        {
            return NULL;
        }

        return $this->queryAll('
            SELECT DISTINCT s.name, s.identifier, s.SMILES, s.inchikey, s.MW, s.LogP, s.pubchem, s.drugbank, s.pdb, s.chEMBL,
                mem.name as membrane, met.name as method, i.temperature, i.charge, i.comment as note, i.Position as X_min,
                i.Position_acc as X_min_acc, i.Penetration as G_pen, i.Penetration_acc as G_pen_acc, i.Water as G_wat, i.Water_acc as G_wat_acc,
                i.LogK, i.LogK_acc, i.LogPerm, i.LogPerm_acc, i.theta, i.theta_acc, i.abs_wl, i.abs_wl_acc, i.fluo_wl, i.fluo_wl_acc, i.QY, i.QY_acc, i.lt, i.lt_acc,
                p1.citation as primary_reference, p2.citation as secondary_reference
            FROM substances s 
            JOIN interaction i ON i.id_substance = s.id
            JOIN membranes mem ON mem.id = i.id_membrane
            JOIN methods met ON met.id = i.id_method
            JOIN datasets d ON d.id = i.id_dataset
            LEFT JOIN publications p1 ON p1.id = i.id_reference
            LEFT JOIN publications p2 ON p2.id = d.id_publication
            WHERE i.id_reference = ? OR d.id_publication = ?
        ', array($this->id, $this->id));
    }

    /**
     * Returns active interaction for given publication
     * 
     * @return Iterable_object
     */
    public function get_active_transport_data()
    {
        if(!$this->id)
        {
            return NULL;
        }

        return $this->queryAll('
            SELECT DISTINCT s.name, s.identifier, s.SMILES, s.inchikey, s.MW, s.LogP, s.pubchem, s.drugbank, s.pdb, s.chEMBL,
                tt.name as target, tt.uniprot_id as uniprot, t.type, t.note, t.Km, t.Km_acc, t.EC50, t.EC50_acc, t.Ki, t.Ki_acc,
                t.IC50, t.IC50_acc, p1.citation as primary_reference, p2.citation as secondary_reference
            FROM substances s 
            JOIN transporters t ON t.id_substance = s.id
            JOIN transporter_datasets td ON td.id = t.id_dataset
            JOIN transporter_targets tt ON tt.id = t.id_target
            LEFT JOIN publications p1 ON p1.id = t.id_reference
            LEFT JOIN publications p2 ON p2.id = td.id_reference
            WHERE t.id_reference = ? OR td.id_reference = ?
        ', array($this->id, $this->id));
    }

    /**
     * Return reference for given $query
     * 
     * @param string $query
     * 
     * @return Publications
     */
    public function get_by_query($query)
    {
        if(!$query || $query == '')
        {
            return new Publications();
        }

        $row = $this->queryOne(
            'SELECT id 
            FROM publications
            WHERE pmid LIKE ? OR doi LIKE ? OR citation LIKE ?'
            , array
            (
                $query,
                $query,
                $query
            ));

        return new Publications($row['id']);
    }

    /**
     * Returns citation for given object params
     * 
     * @return string
     */
    public function make_citation()
    {
        $citation = $this->authors . ': ' . trim($this->title) . ' ';

        if($this->journal && $this->journal != '')
        {
            $citation = $citation . $this->journal . ", ";

            if($this->volume && $this->volume != '')
            {
                $citation = $citation . "Volume " . $this->volume;

                if($this->issue)
                {
                    $citation = $citation . " (" . $this->issue . ")";
                }

                $citation = $citation . ", ";
            }

            if($this->pages)
            {
                $citation = $citation . $this->pages . ", ";
            }
        }

        $citation = $citation . $this->year;

       return trim($citation);
    }

    /**
     * 
     */
    public function update_ref_stats()
    {
        if(!$this->id)
        {
            return null;
        }

        $total_passive = $this->queryOne('
            SELECT COUNT(*) as count
            FROM interaction i
            JOIN datasets d ON d.id = i.id_dataset
            WHERE i.id_reference = ? OR d.id_publication = ?
        ', array($this->id, $this->id))->count;

        $total_active = $this->queryOne('
            SELECT COUNT(*) as count
            FROM transporters t
            JOIN transporter_datasets td ON td.id = t.id_dataset
            WHERE t.id_reference = ? OR td.id_reference = ?
        ', array($this->id, $this->id))->count;

        $total_substances = $this->queryOne('
            SELECT COUNT(*) as count
            FROM (
                SELECT DISTINCT id
                FROM (
                    SELECT DISTINCT i.id_substance as id
                    FROM interaction i
                    JOIN datasets d ON d.id = i.id_dataset
                    WHERE i.id_reference = ? OR d.id_publication = ?

                    UNION

                    SELECT DISTINCT t.id_substance as id
                    FROM transporters t
                    JOIN transporter_datasets td ON td.id = t.id_dataset
                    WHERE t.id_reference = ? OR td.id_reference = ?
                ) as t
            ) as t
        ', array($this->id, $this->id, $this->id, $this->id))->count;

        $this->total_passive_interactions = $total_passive;
        $this->total_active_interactions = $total_active;
        $this->total_substances = $total_substances;

        $this->save();

        return TRUE;
    }
}