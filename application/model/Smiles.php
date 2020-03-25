<?php

/**
 * SMILES model
 * 
 * @property integer $id
 * @property string $name
 * @property string $SMILES
 * @property integer $user_id
 * @property datetime $createDateTime
 * @property datetime $editDateTime
 * 
 */
class Smiles extends Db
{
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'smiles';
        parent::__construct($id);
    }

    /**
     * Loads data from CSV file
     */
    public function loadData($filename)
    {
        $csvFile = file($filename);
        $data = [];
        foreach ($csvFile as $line) {
            $data[] = str_getcsv($line, ";");
        }
        return $data;
    }
    
    /**
     * Checks SMILES in DB
     */
    public function checkSmiles()
    {
        $substance_model = new Substances();

        $data = $substance_model->select_list(array
            (
                'uploadName',
                'SMILES'  
            ))
            ->get_all();
        
        foreach($data as $row)
        {
            if($row->SMILES == null)
            {
                continue;
            }

            // Is already stored?
            $check = $this->where("SMILES LIKE \"$row->SMILES\" OR name LIKE \"$row->uploadName\"")
                ->get_one();

            if($check->id)
            {
                continue;
            }
            
            //If not, then save
            $new = new Smiles();

            $new->name = $row->uploadName;
            $new->SMILES = $row->SMILES;
            $new->user_id = $_SESSION['user']['id'];

            $new->save();
        }

        // Update substance table
        $data = $this->get_all();
        
        foreach($data as $row)
        {
            if(empty($row->name))
            {
                continue;
            }

            $substance = $substance_model->where(
                    "(name LIKE '$row->name' OR uploadName LIKE '$row->name') AND SMILES IS NOT NULL"
                )
                ->get_one();

            if($substance->id)
            {
                $s = new Substances($substance->id);

                $s->SMILES = $row->SMILES;

                $s->save();
            }
        }
    }
    
    /**
     * Loads whisper for search engine
     * 
     * @param string $q
     * 
     * @return array
     */
    public function loadWhisperCompounds($q)
    {
        $data = Db::queryAll('
            SELECT CONCAT(name, " [SMILES: ", SMILES, "]") name
            FROM substances
            WHERE SMILES LIKE ? LIMIT 10
        ', array($q));
        
        $res = array();
        
        foreach($data as $row)
        {
            $res[] = array
            (
                    'name'      => $row['name'],
                    'pattern'   => ''
            );
        }
        
        return $res;
    }
}
