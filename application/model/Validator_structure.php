<?php

/**
 * Validator structure model
 * 
 * @property int $id
 * @property int $id_substance
 * @property Substances $substance
 * @property int $id_source
 * @property Validator_identifiers $source
 * @property int $id_user
 * @property Users $user
 * @property int $is_default
 * @property string $description
 * @property string $datetime
 * @property Files[] $files
 * 
 * @author Jakub Juracka
 */
class Validator_structure extends Db
{
    /**
     * Priority of sources for finding 3D structure
     */
    public static $priority_sources = array
    (
        Validator_identifiers::SERVER_PUBCHEM,
        Validator_identifiers::SERVER_RDKIT,
        Validator_identifiers::SERVER_PDB,
        Validator_identifiers::SERVER_DRUGBANK,
    );

    /**
     * Constructor
     * 
     * @param int $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'validator_structure';
        parent::__construct($id);
    }

    /**
     * Links to other tables
     */
    protected $has_one = array
    (
        'id_user',
        'id_substance',
        'id_source' => array
        (
            'var' => 'source',
            'class' => 'Validator_identifiers'
        ),
    );

    protected $has_many = array
    (
        [
            'var' => 'files',
            'own' => 'id_validator_structure',
            'class' => 'Files'
        ]
    );

    /**
     * Checks, if given substance has structure
     * 
     * @param int $id_substance
     * 
     * @return boolean
     */
    public function has_substance_structure($id_substance)
    {
        $rows = $this->where('id_substance', $id_substance)->get_all();

        $result = false;

        foreach($rows as $r)
        {
            if(!count($r->files))
            {
                // Delete rows without assigned files
                $r->delete();
                continue;
            }

            $result = true;
        }

        return $result;
    }
}