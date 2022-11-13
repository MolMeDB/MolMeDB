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
	 * @param string $Q
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
    public function insert_interaction($dataset_id, $reference_id, $ligand, $uploadName,$MW,$position,$position_acc, $penetration, $penetration_acc, $water, $water_acc, 
                            $LogK, $LogK_acc, $Area,$Volume, $LogP, $LogPerm, $LogPerm_acc, $Theta, $Theta_acc, $abs_wl, $abs_wl_acc, $fluo_wl, $fluo_wl_acc, 
                            $QY, $QY_acc, $lt, $lt_acc,
                            $Q, $comment, $SMILES, $DrugBank, $PubChem, $PDB, $CHEMBL, $CHEBI, $membrane, $method, $temperature)
    {
        $substanceModel = new Substances();
        $interactionModel = new Interactions();
		$user_id = session::user_id();
		$rdkit = new Rdkit();

		try
		{
			// Checks if substance already exists
			$substance = $substanceModel->exists($ligand, $SMILES, $PDB, $DrugBank, $PubChem, $CHEBI, $CHEMBL);

			// Canonize smiles
			$canonized_smiles = $rdkit->canonize_smiles($SMILES, false);

			if($canonized_smiles)
			{
				$SMILES = $canonized_smiles;
			}

			// Protected insert 
			if(!$substance->id)
			{
				// Add new one
				$substance->name = $ligand;
				$substance->uploadName = $ligand;
				$substance->SMILES = $SMILES;
				$substance->MW = $MW;
				$substance->pdb = $PDB;
				$substance->pubchem = $PubChem;
				$substance->drugbank = $DrugBank;
				$substance->chEMBL = $CHEMBL;
				$substance->chEBI = $CHEBI;
				$substance->user_id = $user_id;
				
				$substance->save($dataset_id);
			}
			else
			{
				// Add new values as valid identifiers
				$valid_attributes = array
				(
					Validator_identifiers::ID_NAME => $ligand,
					Validator_identifiers::ID_SMILES => $SMILES,
					Validator_identifiers::ID_CHEBI => $CHEBI,
					Validator_identifiers::ID_CHEMBL => $CHEMBL,
					Validator_identifiers::ID_PDB => $PDB,
					Validator_identifiers::ID_PUBCHEM => $PubChem,
					Validator_identifiers::ID_DRUGBANK => $DrugBank,
				);

				foreach($valid_attributes as $type => $value)
				{
					if(empty($value) || trim($value) === '')
					{
						continue;
					}

					$record = new Validator_identifiers();
					$record->identifier = $type;
					$record->id_substance = $substance->id;
					$record->value = $value;
					$record->id_source = NULL;
					$record->server = NULL;
					$record->id_user = session::user_id();
					$record->state = Validator_identifiers::STATE_VALIDATED;
					$record->active = Validator_identifiers::INACTIVE;
					$record->id_dataset_passive = $dataset_id;
					$record->flag = NULL;

					if($type === Validator_identifiers::ID_SMILES && $canonized_smiles)
					{
						$record->flag = Validator_identifiers::SMILES_FLAG_CANONIZED;
					}

					$record->save();
				}

				$substance->check_identifiers();

				// If missing identifier, then update
				if(!Identifiers::is_valid($substance->identifier))
				{
					$substance->identifier = Identifiers::generate_substance_identifier($substance->id);
				}

				$substance->save();
			}

			// try to find interaction
			$interaction_id = $interactionModel->where(array
				(
					'id_membrane'	=> $membrane->id,
					'id_method'		=> $method->id,
					'id_substance'	=> $substance->id,
					'temperature'	=> $temperature,
					'charge'		=> $Q,
					'id_reference'	=> $reference_id,
					'comment' 		=> $comment
				))
				->select_list('id')
				->get_one()
				->id;

			$interaction = new Interactions($interaction_id);

			// If not exists or has different comment, then add new interaction detail
			if(!$interaction->id) 
			{
				$interaction->id_dataset = $dataset_id;
				$interaction->id_membrane = $membrane->id;
				$interaction->id_method = $method->id;
				$interaction->id_substance = $substance->id;
				$interaction->id_reference = $reference_id;
				$interaction->charge = $Q;
				$interaction->temperature = $temperature;
				$interaction->comment = $comment;

				$interaction->Position = $position;
				$interaction->Position_acc = $position_acc;
				$interaction->Penetration = $penetration;
				$interaction->Penetration_acc = $penetration_acc;
				$interaction->Water = $water;
				$interaction->Water_acc = $water_acc;
				$interaction->LogK = $LogK;
				$interaction->LogK_acc = $LogK_acc;
				$interaction->LogPerm = $LogPerm;
				$interaction->LogPerm_acc = $LogPerm_acc;
				$interaction->theta = $Theta;
				$interaction->theta_acc = $Theta_acc;
				$interaction->abs_wl = $abs_wl;
				$interaction->abs_wl_acc = $abs_wl_acc;
				$interaction->fluo_wl = $fluo_wl;
				$interaction->fluo_wl_acc = $fluo_wl_acc;
				$interaction->QY = $QY;
				$interaction->QY_acc = $QY_acc;
				$interaction->lt = $lt;
				$interaction->lt_acc = $lt_acc;

				$interaction->save();
			}
			else // Exists and has the same comment, so try to fill missing data
			{
				$interaction->Position = self::check_int_val($interaction->Position, $position, 'Position', $interaction->id_dataset);
				$interaction->Position_acc = $interaction->Position === $position ? $position_acc : $interaction->Position_acc;
				$interaction->Penetration = self::check_int_val($interaction->Penetration, $penetration, 'Penetration', $interaction->id_dataset);
				$interaction->Penetration_acc = $interaction->Penetration === $penetration ? $penetration_acc : $interaction->Penetration_acc;
				$interaction->Water = self::check_int_val($interaction->Water, $water, 'Water', $interaction->id_dataset);
				$interaction->Water_acc = $interaction->Water === $water ? $water_acc : $interaction->Water_acc;
				$interaction->LogK = self::check_int_val($interaction->LogK, $LogK, 'LogK', $interaction->id_dataset);
				$interaction->LogK_acc = $interaction->LogK === $LogK ? $LogK_acc : $interaction->LogK_acc;
				$interaction->LogPerm = self::check_int_val($interaction->LogPerm, $LogPerm, 'LogPerm', $interaction->id_dataset);
				$interaction->LogPerm_acc =$interaction->LogPerm === $LogPerm ? $LogPerm_acc : $interaction->LogPerm_acc;
				$interaction->theta = self::check_int_val($interaction->theta, $Theta, 'theta', $interaction->id_dataset);
				$interaction->theta_acc = $interaction->theta === $Theta ? $Theta_acc : $interaction->theta_acc;
				$interaction->abs_wl = self::check_int_val($interaction->abs_wl, $abs_wl, 'abs_wl', $interaction->id_dataset);
				$interaction->abs_wl_acc = $interaction->abs_wl === $abs_wl ? $abs_wl_acc : $interaction->abs_wl_acc;
				$interaction->fluo_wl = self::check_int_val($interaction->fluo_wl, $fluo_wl, 'fluo_wl', $interaction->id_dataset);
				$interaction->fluo_wl_acc = $interaction->fluo_wl === $fluo_wl ? $fluo_wl_acc : $interaction->fluo_wl_acc;
				$interaction->QY = self::check_int_val($interaction->QY, $QY, 'QY', $interaction->id_dataset);
				$interaction->QY_acc = $interaction->QY === $QY ? $QY_acc : $interaction->QY_acc;
				$interaction->lt = self::check_int_val($interaction->lt, $lt, 'lt', $interaction->id_dataset);
				$interaction->lt_acc = $interaction->lt === $lt ? $lt_acc : $interaction->lt_acc;

				$interaction->save();
			}
		} 
		catch(UploadLineException $e)
		{
			throw new UploadLineException($ligand . ' / ' . $e->getMessage());
		}
		catch (Exception $ex) 
		{
			throw new UploadLineException($ligand . ' / ' . $ex->getMessage());
		}
	}

	/**
	 * Checks interaction detail values - ONLY FLOAT values!
	 * If OLD value is set and new value is different, then throws Exception
	 * 
	 * @param $old OLD VALUE
	 * @param $new NEW VALUE
	 * 
	 * @throws Exception
	 * @return float
	 * 
	 * @author Jakub Juračka
	 */
	private static function check_int_val($old, $new, $attr, $dataset_id)
	{
		if($old === NULL)
		{
			return $new;
		}

		if($old !== NULL && $new !== NULL && floatval($old) !== floatval($new))
		{
			throw new Exception('For given interaction already exists different value for attr [' . $attr  .']. Please, check dataset ID:' . $dataset_id);
		}

		return $old;
	}
	

	/**
	 * Insert new transporter record
	 * 
	 * @param Transporter_datasets $dataset
	 * @param string $ligand
	 * @param string $SMILES
	 * @param float $MW
	 * @param float $log_p
	 * @param string $pdb
	 * @param string $pubchem
	 * @param string $drugbank
	 * @param string $uniprot
	 * @param integer $id_reference - Primary reference
	 * @param integer $type
	 * @param string $target
	 * @param float $IC50
	 * @param float $EC50
	 * @param float $KI
	 * @param float $KM
	 * 
	 * @author Jakub Juracka
	 */
	public function insert_transporter($dataset, $ligand, $SMILES, $MW, $log_p, $pdb, $pubchem, $drugbank, $uniprot,
		$id_reference, $type, $target, $IC50, $EC50, $KI, $KM, $IC50_acc = NULL, $EC50_acc = NULL, $KI_acc = NULL, $KM_acc = NULL, $note = NULL)

	{
		$substanceModel = new Substances();
		$user_id = session::user_id();
		$rdkit = new Rdkit();

		try
		{
			// TODO
			// Move to file check !!!!!!!!!!!!!!!!!
			if(!$uniprot)
			{
				throw new Exception('Missing Uniprot_id value.');
			}

			if(!$target)
			{
				throw new Exception('Missing target value.');
			}
			// !!!!!!!!!!!!!!!!!!!

			if(!$IC50 && !$EC50 && !$KI && !$KM)
			{
				throw new Exception('Invalid interaction values.');
			}

			$substance = $substanceModel->exists($ligand, $SMILES, $pdb, $drugbank, $pubchem);

			// Canonize smiles
			$canonized_smiles = $rdkit->canonize_smiles($SMILES, false);

			if($canonized_smiles)
			{
				$SMILES = $canonized_smiles;
			}

			// Protected insert 
			if(!$substance->id)
			{
				// Add new one
				$substance->name = $ligand;
				$substance->uploadName = $ligand;
				$substance->SMILES = $SMILES;
				$substance->MW = $MW;
				$substance->pdb = $pdb;
				$substance->pubchem = $pubchem;
				$substance->drugbank = $drugbank;
				$substance->user_id = $user_id;
				
				$substance->save(NULL, $dataset->id);
			}
			else
			{
				// Add new values as valid identifiers
				$valid_attributes = array
				(
					Validator_identifiers::ID_NAME => $ligand,
					Validator_identifiers::ID_SMILES => $SMILES,
					Validator_identifiers::ID_PDB => $pdb,
					Validator_identifiers::ID_PUBCHEM => $pubchem,
					Validator_identifiers::ID_DRUGBANK => $drugbank,
				);

				foreach($valid_attributes as $type => $value)
				{
					if(empty($value) || trim($value) === '')
					{
						continue;
					}

					$record = new Validator_identifiers();
					$record->identifier = $type;
					$record->id_substance = $substance->id;
					$record->value = $value;
					$record->id_source = NULL;
					$record->server = NULL;
					$record->id_user = session::user_id();
					$record->state = Validator_identifiers::STATE_VALIDATED;
					$record->active = Validator_identifiers::INACTIVE;
					$record->id_dataset_active = $dataset->id;
					$record->flag = NULL;

					if($type === Validator_identifiers::ID_SMILES && $canonized_smiles)
					{
						$record->flag = Validator_identifiers::SMILES_FLAG_CANONIZED;
					}

					$record->save();
				}

				$substance->check_identifiers();

				// If missing identifier, then update
				if(!Identifiers::is_valid($substance->identifier))
				{
					$substance->identifier = Identifiers::generate_substance_identifier($substance->id);
				}

				$substance->save();
			}

			// Check if target already exists
			$transp_target_model = new Transporter_targets();

			$target_obj = $transp_target_model->find($target, $uniprot);

			// Not exists? Make new one
			if(!$target_obj->id)
			{
				$target_obj->name = $target;
				$target_obj->uniprot_id = $uniprot;
				$target_obj->id_user = $user_id;

				$target_obj->save();
			}

			// Save new transporter
			$transporter = new Transporters();

			// Check, if already exists
			$transporter = $transporter->search($substance->id, $target_obj->id, $id_reference, $dataset->id_reference, $note);

			// Check, if data can be filled
			if($transporter->id)
			{
				if($KM)
				{
					if($transporter->Km && $KM !== $transporter->Km)
					{
						throw new Exception('For given reference already exists detail with different "Km" value. Please, check dataset ID:' . $transporter->dataset->id);
					}
				}
				if($KI)
				{
					if($transporter->Ki && $KI !== $transporter->Ki)
					{
						throw new Exception('For given reference already exists detail with different "Ki" value. Please, check dataset ID:' . $transporter->dataset->id);
					}
				}
				if($EC50)
				{
					if($transporter->EC50 && $EC50 !== $transporter->EC50)
					{
						throw new Exception('For given reference already exists detail with different "EC50" value. Please, check dataset ID:' . $transporter->dataset->id);
					}
				}
				if($IC50)
				{
					if($transporter->IC50 && $IC50 !== $transporter->IC50)
					{
						throw new Exception('For given reference already exists detail with different "IC50" value. Please, check dataset ID:' . $transporter->dataset->id);
					}
				}
			}

			// Create/fill record
			$transporter->id_dataset = $dataset->id;
			$transporter->id_substance = $substance->id;
			$transporter->id_target = $target_obj->id;
			$transporter->type = $type;
			$transporter->note = $note;
			$transporter->Km = $KM ? $KM : $transporter->Km;
			$transporter->Km_acc = $KM ? $KM_acc : NULL;
			$transporter->Ki = $KI ? $KI : $transporter->Ki;
			$transporter->Ki_acc = $KI ? $KI_acc : NULL;
			$transporter->EC50 = $EC50 ? $EC50 : $transporter->EC50;
			$transporter->EC50_acc = $EC50 ? $EC50_acc : NULL;
			$transporter->IC50 = $IC50 ? $IC50 : $transporter->IC50;
			$transporter->IC50_acc = $IC50 ? $IC50_acc : NULL;

			$transporter->id_reference = $id_reference;
			$transporter->id_user = $user_id;
			
			$transporter->save();
		} 
		catch(UploadLineException $e)
		{
			throw new UploadLineException($ligand . ' / ' . $e->getMessage());
		}
		catch (Exception $ex) 
		{
			throw new UploadLineException($ligand . ' / ' . $ex->getMessage());
    }
	}
}