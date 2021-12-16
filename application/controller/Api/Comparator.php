<?php

class ApiComparator extends ApiController
{    
	/**
	 * Constructor
	 */
	function __construct()
	{
		
	}


	/**
	 * Returns energy values for given params
	 * 
	 * @POST
	 * @param flags
	 * 
	 */
    public function getEnergyValues($energyFlagIds)
    {
		//$energyFlagIds = $this->remove_empty_values($energyFlagIds);

		if(empty($energyFlagIds))
		{
			//$this->answer(array());
		}

		$result = array();
		$index = 0;

		foreach($energyFlagIds as $id)
		{
			$detail = new Energy($id);

			if(!$detail->id || !$detail->membrane || !$detail->method ||
				!$detail->substance)
			{
				continue;
			}

			$result[$index] = array
			(
				'membrane'	=> $detail->membrane->name,
				'membrane_cam'	=> $detail->membrane->CAM,
				'method'	=> $detail->method->name,
				'method_cam'	=> $detail->method->CAM,
				'substance'	=> $detail->substance->name,
				'data'	=> array()
			);

			$data = $detail->where(array
				(
					'id_substance'	=> $detail->id_substance,
					'id_membrane'	=> $detail->id_membrane,
					'id_method'		=> $detail->id_method	
				))
				->get_all();

			foreach($data as $row)
			{
				$result[$index]['data'][] = array
				(
					'distance'	=> $row->distance,
					'energy'	=> $row->energy
				);
			}

			$index++;
		}

		//$this->answer($result);

    }
    
    /**
	 * Gets interaction by ID
	 * 
	 * @GET
	 * @param id Array|integer
	 */
    public function getInteraction($id)
    {
		$ids = explode(",", $id);

		$result = array();

		foreach($ids as $interaction_id)
		{
			$interaction_id = intval($interaction_id);

			$int = new Interactions($interaction_id);
			$energy = new Energy();

			if(!$int->id)
			{
				continue;
			}

			// Check if energy value exists
			$avail = $energy->where(array
				(
					'id_substance'	=> $int->id_substance,
					'id_membrane'	=> $int->id_membrane,
					'id_method'		=> $int->id_method
				))
				->get_one();

			$result[] = array
			(
				'id'			=> $int->id,
				'substance'		=> $int->substance ? $int->substance->as_array() : NULL,
				'membrane'		=> $int->membrane ? $int->membrane->name : NULL,
				'method'		=> $int->method ? $int->method->name : NULL,
				'comment'		=> $int->comment ? $int->comment : NULL,
				'charge'		=> $int->charge,
				'temperature'	=> $int->temperature,
				'Position'		=> $int->Position,
				'Position_acc' 	=> $int->Position_acc,
				'Penetration'	=> $int->Penetration,
				'Penetration_acc' 	=> $int->Penetration_acc,
				'Water'			=> $int->Water,
				'Water_acc' 	=> $int->Water_acc,
				'LogK'			=> $int->LogK,
				'LogK_acc' 		=> $int->LogK_acc,
				'LogPerm'		=> $int->LogPerm,
				'LogPerm_acc' 	=> $int->LogPerm_acc,
				'theta'			=> $int->theta,
				'theta_acc' 	=> $int->theta_acc,
				'abs_wl'		=> $int->abs_wl,
				'abs_wl_acc' 	=> $int->abs_wl_acc,
				'fluo_wl'		=> $int->fluo_wl,
				'fluo_wl_acc' 	=> $int->fluo_wl_acc,
				'lt'			=> $int->lt,
				'lt_acc' 		=> $int->lt_acc,
				'QY'			=> $int->QY,
				'QY_acc' 		=> $int->QY_acc,
				'id_reference'	=> $int->id_reference,
				'reference'		=> $int->reference ? $int->reference->citation : NULL,
				'id_dataset'	=> $int->id_dataset,
				'id_substance'	=> $int->id_substance,
				'id_membrane'	=> $int->id_membrane,
				'id_method'		=> $int->id_method,
				'energy_profile_flag'	=> $avail->id,
				'last_update'	=> $int->editDateTime,
				'uploaded'		=> $int->createDateTime
			);
		}

		if(count($result)  === 1)
		{
			$result = $result[0];
		}
		//return $this->answer($result);
    }
    
    /**
	 * Gets active interaction by ID
	 * 
	 * @GET
	 * @param id Array|integer
	 */
    public function getActiveInteraction($id)
    {
		$ids = explode(",", $id);

		$result = array();

		foreach($ids as $interaction_id)
		{
			$interaction_id = intval($interaction_id);

			$int = new Transporters($interaction_id);

			if(!$int->id)
			{
				continue;
			}

			$result[] = array
			(
				'id'			=> $int->id,
				'substance'		=> $int->substance ? $int->substance->as_array() : NULL,
				'comment'		=> $int->note ? $int->note : NULL,
				'type'			=> $int->type,
				'target_id' 	=> $int->id_target,
				'target'		=> $int->target->as_array(),
				'Km'			=> $int->Km,
				'Km_acc'		=> $int->Km_acc,
				'EC50'			=> $int->EC50,
				'EC50_acc'		=> $int->EC50_acc,
				'Ki'			=> $int->Ki,
				'Ki_acc'		=> $int->Ki_acc,
				'IC50'			=> $int->IC50,
				'IC50_acc'		=> $int->IC50_acc,
				'id_reference'	=> $int->id_reference,
				'reference'		=> $int->reference ? $int->reference->citation : NULL,
				'id_dataset'	=> $int->id_dataset,
				'id_substance'	=> $int->id_substance,
				'uploaded'		=> $int->create_datetime
			);
		}

		if(count($result)  === 1)
		{
			$result = $result[0];
		}
		//return $this->answer($result);
    }
    
	/**
	 * Returns interaction ids and charges for given params
	 * 
	 * @POST
	 * @param method_ids
	 * @param membrane_ids
	 * @param substance_ids
	 * @param charges
	 */
    public function getInteractionIds($methods, $membranes, $substances, $charges)
    {
		//$methods_ids = $methods ? $this->remove_empty_values($methods) : array();
		//$membranes_ids = $membranes ? $this->remove_empty_values($membranes) : array();
		//$charges = $charges ? $this->remove_empty_values($charges) : array();
		//$substances_ids = $substances ? $this->remove_empty_values($substances) : array();

		// If not set parameters, then return empty response
		if(empty($methods_ids) && empty($membranes_ids) &&
			empty($charges) && empty($substances_ids))
		{
			//$this->answer(array());	
		}

		$interaction_model = new Interactions();

		$data = $interaction_model
			->in('id_substance', $substances_ids)
			->in('id_membrane', $membranes_ids)
			->in('id_method', $methods_ids)
			->in('charge', $charges)
			->where("visibility", Interactions::VISIBLE)
			->select_list('id, charge')
			->get_all();

		$result = array();

		foreach($data as $row)
		{
			$result[] = array
			(
				'id' => $row->id,
				'charge' => $row->charge
			);
		}

		//$this->answer($result);
    }

	
	/**
	 * Return substances to comparator for given params
	 * 
	 * @GET 
	 * @param type - Type of request
	 * @param id   - ID of request type
	 * 
	 */
	public function getSubstancesToComparator($type, $id)
	{
		$valid_types = array
		(
			'membrane',
			'method',
			'set'
		);

		if(!in_array($type, $valid_types) || !$id)
		{
			//$this->answer(NULL, self::CODE_BAD_REQUEST);
		}

		$inter_model = new Interactions();

		$data = new Db();
		
		switch($type)
		{
			case 'membrane':
				$data = $inter_model->where(array
					(
						'visibility'	=> Interactions::VISIBLE,
						'id_membrane'	=> $id		
					))
					->get_all();
				break;
			
			case 'method':
				$data = $inter_model->where(array
					(
						'visibility'	=> Interactions::VISIBLE,
						'id_method'	=> $id		
					))
					->get_all();
				break;
			
			case 'set':
				$data = $inter_model->where(array
					(
						'visibility'	=> Interactions::VISIBLE,
						'id_reference'	=> $id		
					))
					->get_all();
				break;
		}

		$result = array();

		foreach($data as $row)
		{
			$result[] = array
			(
				'id'	=> $row->id_substance,
				'name'	=> $row->substance->name
			);
		}

		//$this->answer($result);
	}
}