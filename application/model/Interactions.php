<?php

/**
 * @property integer $id
 * @property integer $id_dataset
 * @property integer $visibility
 * @property string $CAM
 * @property integer $id_membrane
 * @property integer $id_method
 * @property integer $id_substance
 * @property float $temperature
 * @property float $charge
 * @property float $id_reference
 * @property string $comment
 * @property float $Position
 * @property float $Position_acc
 * @property float $Penetration
 * @property float $Penetration_acc
 * @property float $Water
 * @property float $Water_acc
 * @property float $LogK
 * @property float $LogK_acc
 * @property float $LogPerm
 * @property float $LogPerm_acc
 * @property float $theta
 * @property float $theta_acc
 * @property float $abs_wl
 * @property float $abs_wl_acc
 * @property float $fluo_wl
 * @property float $fluo_wl_acc
 * @property float $QY_wl
 * @property float $QY_wl_acc
 * @property float $lt
 * @property float $lt_acc
 * @property integer $user_id
 * @property integer $validated
 * @property datetime $createDateTime
 * @property datetime $editDateTime
 * 
 * @property Substances $substance
 * @property Membranes $membrane
 * @property Datasets $dataset
 * @property Methods $method
 * @property Publications $publication
 * 
 */
class Interactions extends Db 
{
	/** VISIBILITY CONSTANTS */
	const VISIBLE = 1;
	const INVISIBLE = 2;

	/** VALIDATION CONSTS */
	const NOT_VALIDATED = Validator::NOT_VALIDATED;
	const VALID = Validator::VALID;
	const INVALID = Validator::INVALID;
	

	private $enum_visibilities = array
	(
		self::VISIBLE => 'Visible',
		self::INVISIBLE => 'Invisible'
	);


	public $param = 1;


	public $has_one = array
	(
		'id_substance',
		'id_membrane',
		'id_method',
		'id_dataset',
		'id_reference' => array
		(
			'var' => 'reference',
			'class' => 'Publications'		
		)
	);

	/**
	 * Constructor
	 * 
	 * @param integer $id
	 * 
	 */
	function __construct($id = NULL)
	{
		$this->table = 'interaction';
		parent::__construct($id);
	}

	/**
	 * Gets enum visibility by const number
	 * 
	 * @param integer $visibility
	 * 
	 * @return string
	 */
	public function get_enum_visibility($visibility = NULL)
	{
		if(!$visibility && !$this)
		{
			return '';
		}

		if(!$visibility)
		{
			$visibility = $this->visibility;
		}

		if(!array_search($visibility, $this->enum_visibilities))
		{
			return $this->enum_visibilities[$visibility];
		}

		return '';
	}


	/**
	 * 
	 * @param integer $id
	 * @param integer $idInteraction
	 * @param integer $idMethod
	 * @param integer $idMembrane
	 *
	 * @return type
	 */
    public function returnDetail($id, $idInteraction = false, $idMethod, $idMembrane)
    {
        if(!$idInteraction){
        $res = $this->queryOne('SELECT *
	                             FROM interaction as i
	                             JOIN substances as s ON i.id_substance = ?
	                             AND s.id = ?', array($id, $id));
        
        $res += $this->queryOne('SELECT * FROM membranes
                                WHERE id = ?', array($res->id_membrane));
        }
		else
		{
            $res = $this->queryOne('SELECT *
	                         FROM interaction
								 WHERE id_substance = ? AND id_method = ? AND id_membrane = ?', array($id, $idMethod, $idMembrane));
								 
			if(empty($res))
			{
				throw new ErrorUser;
			}
            $res += $this->queryOne('SELECT *
                                  FROM substances
                                  WHERE id = ?', array($res->id_substance));
        }
        return $res;
    }
    
    
	/**
	 * Inserts new interaction
	 * protected against ON DUPLICATE KEY 
	 * 
	 * @param integer $id_dataset
	 * @param integer $reference
	 * @param float $temperature
	 * @param float $Q
	 * @param integer $idMembrane
	 * @param integer $idSubstance
	 * @param integer $idMethod
	 * @param float $Position
	 * @param float $position_acc
	 * @param float $penetration
	 * @param float $penetration_acc
	 * @param float $Water
	 * @param float $water_acc
	 * @param float $LogK
	 * @param float $LogK_acc
	 * @param integer $user_id
	 * @param string $cam
	 * @param float $LogPerm
	 * @param float $LogPerm_acc
	 * @param float $Theta
	 * @param float $Theta_acc
	 * @param float $abs_wl
	 * @param float $abs_wl_acc
	 * @param float $fluo_wl
	 * @param float $fluo_wl_acc
	 * @param float $QY
	 * @param float $QY_acc
	 * @param float $lt
	 * @param float $lt_acc
	 * 
	 * @return integer|null
	 */
    public function insert_interaction($id_dataset, $reference, $temperature, $Q, $idMembrane, $idSubstance, $idMethod, $Position,$position_acc, $penetration, $penetration_acc, $Water, $water_acc, 
                            $LogK, $LogK_acc, $user_id, $cam, $LogPerm, $LogPerm_acc, $Theta, $Theta_acc, $abs_wl, $abs_wl_acc,
									$fluo_wl, $fluo_wl_acc, $QY, $QY_acc, $lt, $lt_acc)
	{
        $this->query("INSERT INTO `interaction`
                          VALUES(NULL, ?, ?, ?, ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,DEFAULT, DEFAULT)
                          ON DUPLICATE KEY
                          UPDATE `Position` = IF(? = '', `Position`, ?), `Penetration` = IF(? = '', `Penetration`, ?),
                                 `Water` = IF(? = '', `Water`, ?), `LogK` = IF(? = '', `LogK`, ?), `LogPerm` = IF(? = '', `LogPerm`, ?),
                                 `theta` = IF(? = '', `theta`, ?), `abs_wl` = IF(? = '', `abs_wl`, ?),
                                 `fluo_wl` = IF(? = '', `fluo_wl`, ?), `QY` = IF(? = '', `QY`, ?), `lt` = IF(? = '', `lt`, ?),
                                 `theta_acc` = IF(? = 0, `theta_acc`, ?), `abs_wl_acc` = IF(? = 0, `abs_wl_acc`, ?), `fluo_wl_acc` = IF(? = 0, `fluo_wl_acc`, ?),
                                 `QY_acc` = IF(? = 0, `QY_acc`, ?), `lt_acc` = IF(? = 0, `lt_acc`, ?),
                                 `Position_acc` = IF(? = 0, `Position_acc`, ?), `Penetration_acc` = IF(? = 0, `Penetration_acc`, ?),
                                 `Water_acc` = IF(? = 0, `Water_acc`, ?), `LogK_acc` = IF(? = 0, `LogK_acc`, ?), `LogPerm_acc` = IF(? = 0, `LogPerm_acc`, ?)",
                
                array($id_dataset,self::INVISIBLE,$cam, $idMembrane,$idSubstance,$idMethod,$temperature, $Q, $reference, $Position, $position_acc,$penetration,$penetration_acc,$Water, $water_acc,$LogK, $LogK_acc,
                            $LogPerm, $LogPerm_acc, $Theta, $Theta_acc, $abs_wl, $abs_wl_acc, $fluo_wl, $fluo_wl_acc, $QY, $QY_acc, $lt, $lt_acc, $user_id,
                      $Position,$Position, $penetration,$penetration, $Water,$Water, $LogK,$LogK, $LogPerm,$LogPerm,
                      $Theta,$Theta,$abs_wl,$abs_wl,$fluo_wl,$fluo_wl,$QY,$QY,$lt,$lt,$Theta_acc,$Theta_acc,$abs_wl_acc,$abs_wl_acc,$fluo_wl_acc,$fluo_wl_acc,$QY_acc,$QY_acc,$lt_acc,$lt_acc,
					  $position_acc,$position_acc, $penetration_acc,$penetration_acc, $water_acc,$water_acc, $LogK_acc,$LogK_acc, $LogPerm_acc,$LogPerm_acc));

		return $this->getLastIdInteraction();
    }
    
    
    /**
	 * 
	 * @param array $substances
	 * @param array $membranes
	 * @param array $methods
	 * @param array $charges
	 */
    public function getChargesOfInteractions($substances, $membranes = array(), $methods = array(), $charges = array())
    {
            $s_cond = implode(',', $substances);
            $mem_cond = count($membranes) ? 'AND i.id_membrane IN (' . implode(',', $membranes) . ') ' : ' ';
            $met_cond = count($methods) ? 'AND i.id_method IN (' . implode(',', $methods) . ') ' : ' ';
            $ch_cond = count($charges) ? 'AND i.charge IN (' . implode(',', $charges) . ') ' : ' ';
            
            return $this->queryAll('SELECT DISTINCT i.charge
                FROM interaction i
                JOIN membranes mem ON mem.id = i.id_membrane
                JOIN methods met ON met.id = i.id_method
                JOIN substances s ON s.id = i.id_substance
                WHERE visibility = 1 AND
                    i.id_substance IN (' . $s_cond . ')'
                    . $mem_cond
                    . $met_cond
                    . $ch_cond);
    }
}
