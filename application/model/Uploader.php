<?php

class Uploader extends Db
{
	public function loadData($filename)
	{
        $csvFile = file($filename);
        $data = [];
        foreach ($csvFile as $line) {
            $data[] = str_getcsv($line, ";");
        }
        //print_r($data);
        return $data;    
	}
    
    public function checkArray($data){
        $res= array();
        $count = count($data);
        for($j=0; $j<$count; $j++){
            $count2 = count($data[$j]);
            for($i=0; $i<$count2; $i++){
                if($data[$j][$i] == NULL) continue; 
                $res[$j][$i] = $data[$j][$i]; 
            }
        }
        return $res;
    }
    
	/**
	 * Inserts new interaction record
	 * 
	 * @param Datasets $dataset
	 * @param integer $reference
	 * @param string $ligand
	 * @param string $uploadName
	 * @param float $MW
	 * @param float $position
	 * @param float $position_acc
	 * @param float $penetration
	 * @param float $penetration_acc
	 * @param float $water
	 * @param float $water_acc
	 * @param float $LogK
	 * @param float $LogK_acc
	 * @param float $Area
	 * @param float $Volume
	 * @param float $LogP
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
	 * @param float $Q
	 * @param string $SMILES
	 * @param string $DrugBank
	 * @param string $PubChem
	 * @param string $PDB
	 * @param Membranes $membrane
	 * @param Methods $method
	 * @param float $temperature
	 * 
	 * @return integer
	 * 
	 * @throws Exception
	 */
    public function insert_interaction($dataset_id, $reference, $ligand, $uploadName,$MW,$position,$position_acc, $penetration, $penetration_acc, $water, $water_acc, 
                            $LogK, $LogK_acc, $Area,$Volume, $LogP, $LogPerm, $LogPerm_acc, $Theta, $Theta_acc, $abs_wl, $abs_wl_acc, $fluo_wl, $fluo_wl_acc, 
                            $QY, $QY_acc, $lt, $lt_acc,
                            $Q, $SMILES, $DrugBank, $PubChem, $PDB, $membrane, $method, $temperature)
    {
        $pattern = '/\s*/m';
        $replace = '';
        $substanceModel = new Substances();
        $interactionModel = new Interactions();
        $smilesModel = new Smiles();
		$user_id = $_SESSION['user']['id'];
		
		try
		{
			if($SMILES == '')
			{        
				// try to find SMILES in DB
				$SMILES = $smilesModel->where('name', $ligand)
					->select_list('SMILES')
					->get_one()
					->name;
				$SMILES = $SMILES && $uploadName != '' ? $SMILES : 
						$smilesModel->where('name', $uploadName)
							->select_list('SMILES')
							->get_one()
							->name;
			}
			if($uploadName == "")
			{
				$uploadName = $ligand;
			}

			// Protect SMILES
			$SMILES = preg_replace($pattern, $replace, $SMILES);

			// Checks if substance already exists
			$idSubstance = $substanceModel->test_duplicates($ligand, $uploadName);

			// Protected insert 
			if(!$idSubstance)
			{
				// Add new one - protected db:ON_DUPLICATE_KEY
				$idSubstance = $substanceModel->insert_substance($ligand, $uploadName, $MW, $SMILES, $user_id, $Area, $Volume, $LogP, $PDB, $DrugBank, $PubChem);
			}
			else
			{
				// Update record
				$substance = new Substances($idSubstance);
				
				$substance->MW = $MW ? $MW : $substance->MW;
				$substance->SMILES = $SMILES != '' ? $SMILES : $substance->SMILES;
				$substance->LogP = $LogP ? $LogP : $substance->LogP;
				$substance->Area = $Area ? $Area : $substance->Area;
				$substance->Volume = $Volume ? $Volume : $substance->Volume;
				$substance->pdb = strlen($PDB) ? $PDB : $substance->pdb;
				$substance->pubchem = strlen($PubChem) ? $PubChem : $substance->pubchem;
				$substance->drugbank = strlen($DrugBank) ? $DrugBank : $substance->drugbank;

				$substance->save();
			}

			if($Q === '')
			{
				$Q = NULL;

				// try to find interaction
				$interaction_id = $interactionModel->where(array
					(
						'id_membrane'	=> $membrane->id,
						'id_method'		=> $method->id,
						'id_substance'	=> $idSubstance,
						'temperature'	=> $temperature,
						'charge IS'		=> NULL,
						'id_reference'	=> $reference
					))
					->select_list('id')
					->get_one()
					->id;
				if($interaction_id) // Exists ? Then update
				{
					$interaction = new Interactions($interaction_id);

					$interaction->Position = $position ? $position : $interaction->Position;
					$interaction->Position_acc = $position_acc ? $position_acc : $interaction->Position_acc;
					$interaction->Penetration = $penetration ? $penetration : $interaction->Penetration;
					$interaction->Penetration_acc = $penetration_acc ? $penetration_acc : $interaction->Penetration;
					$interaction->Water = $water ? $water : $interaction->Water;
					$interaction->Water_acc = $water_acc ? $water_acc : $interaction->Water_acc;
					$interaction->LogK = $LogK ? $LogK : $interaction->LogK;
					$interaction->LogK_acc = $LogK_acc ? $LogK_acc : $interaction->LogK_acc;
					$interaction->LogPerm = $LogPerm ? $LogPerm : $interaction->LogPerm;
					$interaction->LogPerm_acc = $LogPerm_acc ? $LogPerm_acc : $interaction->LogPerm_acc;
					$interaction->theta = $Theta ? $Theta : $interaction->theta;
					$interaction->theta_acc = $Theta_acc ? $Theta_acc : $interaction->theta_acc;
					$interaction->abs_wl = $abs_wl ? $abs_wl : $interaction->abs_wl;
					$interaction->abs_wl_acc = $abs_wl_acc ? $abs_wl_acc : $interaction->abs_wl_acc;
					$interaction->fluo_wl = $fluo_wl ? $fluo_wl : $interaction->fluo_wl;
					$interaction->fluo_wl_acc = $fluo_wl_acc ? $fluo_wl_acc : $interaction->fluo_wl_acc;
					$interaction->QY = $QY ? $QY : $interaction->QY;
					$interaction->QY_acc = $QY_acc ? $QY_acc : $interaction->QY_acc;
					$interaction->lt = $lt ? $lt : $interaction->lt;
					$interaction->lt_acc = $lt_acc ? $lt_acc : $interaction->lt_acc;

					$interaction->save();

					return $interaction->id;
				}
			}

			// Insert new interaction - protected db:ON_DUPLICATE_KEY
			return $interactionModel->insert_interaction($dataset_id, $reference, $temperature, $Q, $membrane->id, $idSubstance, $method->id, $position,$position_acc, $penetration, $penetration_acc, $water, $water_acc, 
								$LogK, $LogK_acc, $user_id, "", $LogPerm, $LogPerm_acc, $Theta, $Theta_acc, $abs_wl, $abs_wl_acc, $fluo_wl, $fluo_wl_acc, 
								$QY, $QY_acc, $lt, $lt_acc);

		} 
		catch (Exception $ex) 
		{
			throw new Exception($ligand . ' / ' . $ex->getMessage());
		}
    }
}