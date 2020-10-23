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
    public function insert_interaction($dataset_id, $reference, $ligand, $uploadName,$MW,$position,$position_acc, $penetration, $penetration_acc, $water, $water_acc, 
                            $LogK, $LogK_acc, $Area,$Volume, $LogP, $LogPerm, $LogPerm_acc, $Theta, $Theta_acc, $abs_wl, $abs_wl_acc, $fluo_wl, $fluo_wl_acc, 
                            $QY, $QY_acc, $lt, $lt_acc,
                            $Q, $SMILES, $DrugBank, $PubChem, $PDB, $membrane, $method, $temperature)
    {
        $substanceModel = new Substances();
        $interactionModel = new Interactions();
        $smilesModel = new Smiles();
		$user_id = $_SESSION['user']['id'];
		
		try
		{
			if($SMILES == '' || !$SMILES)
			{
				// try to find SMILES in DB
				$SMILES = $smilesModel->where('name', $ligand)
					->select_list('SMILES')
					->get_one()
					->SMILES;
			}

			// Checks if substance already exists
			$substance = $substanceModel->exists($ligand, $SMILES, $PDB, $DrugBank, $PubChem);

			// Protected insert 
			if(!$substance->id)
			{
				// Add new one
				$substance->name = $ligand;
				$substance->uploadName = $ligand;
				$substance->SMILES = $SMILES;
				$substance->MW = $MW;
				$substance->Area = $Area;
				$substance->Volume = $Volume;
				$substance->LogP = $LogP;
				$substance->pdb = $PDB;
				$substance->pubchem = $PubChem;
				$substance->drugbank = $DrugBank;
				$substance->user_id = $user_id;
				$substance->validated = 0;
				
				$substance->save();

				// Save identifier and check names
				$substance->identifier = Identifiers::generate_substance_identifier($substance->id);
				$substance->name = trim($substance->name) == '' ? $substance->identifier : $substance->name;
				$substance->uploadName = trim($substance->uploadName) == '' ? $substance->identifier : $substance->uploadName;

				$substance->save();
			}
			else
			{
				// Update record
				$substance->MW = $MW ? $MW : $substance->MW;
				$substance->SMILES = $SMILES ? $SMILES : $substance->SMILES;
				$substance->LogP = $LogP ? $LogP : $substance->LogP;
				$substance->Area = $Area ? $Area : $substance->Area;
				$substance->Volume = $Volume ? $Volume : $substance->Volume;
				$substance->pdb = $PDB ? $PDB : $substance->pdb;
				$substance->pubchem = $PubChem ? $PubChem : $substance->pubchem;
				$substance->drugbank = $DrugBank ? $DrugBank : $substance->drugbank;

				// If missing identifier, then update
				if(!Identifiers::is_valid($substance->identifier))
				{
					$substance->identifier = Identifiers::generate_substance_identifier($substance->id);

					$name = trim($substance->name) == '' ? 
						($ligand && $ligand != '' ? $ligand : $substance->identifier) :
						$substance->name;
					
					$substance->name = $name;
					$substance->uploadName = $name;
				}

				$substance->save();
			}

			// Add altername record if not exists
			$alterName = new AlterNames();

			$exists = $alterName->where('name LIKE', $ligand)
				->get_one();

			if(!$exists)
			{
				$alterName->name = $ligand;
				$alterName->id_substance = $substance->id;

				$alterName->save();
			}

			// try to find interaction
			$interaction_id = $interactionModel->where(array
				(
					'id_membrane'	=> $membrane->id,
					'id_method'		=> $method->id,
					'id_substance'	=> $substance->id,
					'temperature'	=> $temperature,
					'charge'		=> $Q,
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

			// Insert new interaction - protected db:ON_DUPLICATE_KEY
			return $interactionModel->insert_interaction($dataset_id, $reference, $temperature, $Q, $membrane->id, $substance->id, $method->id, $position,$position_acc, $penetration, $penetration_acc, $water, $water_acc, 
								$LogK, $LogK_acc, $user_id, "", $LogPerm, $LogPerm_acc, $Theta, $Theta_acc, $abs_wl, $abs_wl_acc, $fluo_wl, $fluo_wl_acc, 
								$QY, $QY_acc, $lt, $lt_acc);
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
	 * Insert new transporter record
	 * 
	 * @param integer $id_dataset
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
	public function insert_transporter($id_dataset, $ligand, $SMILES, $MW, $log_p, $pdb, $pubchem, $drugbank, $uniprot,
		$id_reference, $type, $target, $IC50, $EC50, $KI, $KM, $IC50_acc = NULL, $EC50_acc = NULL, $KI_acc = NULL, $KM_acc = NULL)
	{
		$substanceModel = new Substances();
		$smilesModel = new Smiles();
		$user_id = $_SESSION['user']['id'];

		try
		{
			if($SMILES === '' || !$SMILES)
			{        
				// try to find SMILES in DB
				$SMILES = $smilesModel->where('name', $ligand)
					->select_list('SMILES')
					->get_one()
					->SMILES;
			}

			// Checks if substance already exists
			$substance = $substanceModel->exists($ligand, $SMILES, $pdb, $drugbank, $pubchem);

			// Protected insert 
			if(!$substance->id)
			{
				// Add new one
				$substance->name = $ligand;
				$substance->uploadName = $ligand;
				$substance->SMILES = $SMILES;
				$substance->MW = $MW;
				$substance->LogP = $log_p;
				$substance->pdb = $pdb;
				$substance->pubchem = $pubchem;
				$substance->drugbank = $drugbank;
				$substance->user_id = $user_id;
				
				$substance->save();

				// Save identifier and check names
				$substance->identifier = Identifiers::generate_substance_identifier($substance->id);
				$substance->name = trim($substance->name) == '' ? $substance->identifier : $substance->name;
				$substance->uploadName = trim($substance->uploadName) == '' ? $substance->identifier : $substance->uploadName;

				$substance->save();
			}
			else
			{
				// Update record
				$substance->MW = $MW ? $MW : $substance->MW;
				$substance->SMILES = $SMILES ? $SMILES : $substance->SMILES;
				$substance->LogP = $log_p ? $log_p : $substance->LogP;
				$substance->Area = $substance->Area;
				$substance->Volume = $substance->Volume;
				$substance->pdb = $pdb ? $pdb : $substance->pdb;
				$substance->pubchem = $pubchem ? $pubchem : $substance->pubchem;
				$substance->drugbank = $drugbank ? $drugbank : $substance->drugbank;

				// If missing identifier, then update
				if(!Identifiers::is_valid($substance->identifier))
				{
					$substance->identifier = Identifiers::generate_substance_identifier($substance->id);

					$name = trim($substance->name) == '' ?
						($ligand && $ligand != '' ? $ligand : $substance->identifier) :
						$substance->name;

					$substance->name = $name;
					$substance->uploadName = $name;
				}

				$substance->save();
			}

			// Add altername record if not exists
			if($ligand && strlen($ligand) > 0)
			{
				$alterName = new AlterNames();

				$exists = $alterName->where('name LIKE', $ligand)
					->get_one();

				if(!$exists)
				{
					$alterName->name = $ligand;
					$alterName->id_substance = $substance->id;

					$alterName->save();
				}
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
			$transporter = $transporter->search($substance->id, $target_obj->id, $id_reference, $type);

			// Create/rewrite record
			$transporter->id_dataset = $id_dataset;
			$transporter->id_substance = $substance->id;
			$transporter->id_target = $target_obj->id;
			$transporter->type = $type;
			$transporter->Km = $KM;
			$transporter->Km_acc = $KM_acc;
			$transporter->Ki = $KI;
			$transporter->Ki_acc = $KI_acc;
			$transporter->EC50 = $EC50;
			$transporter->EC50_acc = $EC50_acc;
			$transporter->IC50 = $IC50;
			$transporter->IC50_acc = $IC50_acc;
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