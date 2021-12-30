<?php

/**
 * Interaction controller
 */
class ApiInteractions extends ApiController
{
   
    /**
     * Returns passive interactions by ids
     * 
     * @POST
     * @INTERNAL
     * @param @required $id
     * @PATH(/detail/passive)
     */
    public function get_passive_interactions_POST($id)
    {
        return $this->get_passive_interactions($id);
    }

    /**
     * Returns passive interactions by ids
     * 
     * @GET
     * @INTERNAL
     * @param @required $id
     * @PATH(/detail/passive)
     */
    public function get_passive_interactions($id)
    {
        if(!is_array($id))
		{
			$id = [$id];
		}

		if(empty($id) || !$id[0])
		{
			ResponseBuilder::bad_request('Invalid id list.');
		}

		$result = array();

		foreach($id as $interaction_id)
		{
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

		if(count($result) === 1)
		{
			$result = $result[0];
		}
		
		return $result;
    }

    /**
     * Returns active interactions details
     * 
     * @GET
     * @param @required $id
     * @PATH(/detail/active)
     */
    public function get_active_interactions($id)
    {
        $ids = $id;

        if(!is_array($ids))
        {
            $ids = [$ids];
        }
        else if(empty($ids))
        {
            ResponseBuilder::bad_request('Invalid id list.');
        }

		$result = array();

		foreach($ids as $interaction_id)
		{
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

		if(count($result) === 1)
		{
			$result = $result[0];
		}

		return $result;
    }

    /**
     * Returns passive interaction ids for given params
     * 
     * @POST - used because of possible big number of parameters' values
     * @INTERNAL
     * @param $id_method
     * @param $id_membrane
	 * @param $id_compound
	 * @param $charge
     * @PATH(/ids)
     */
    public function get_ids_for_params($id_method, $id_membrane, $id_compound, $charge)
    {
        if(!is_array($id_method) && $id_method)
        {
            $id_method = [$id_method];
        }
        if(!is_array($id_membrane) && $id_membrane)
        {
            $id_membrane = [$id_membrane];
        }
        if(!is_array($id_compound) && $id_compound)
        {
            $id_compound = [$id_compound];
        }
        if(!is_array($charge) && $charge)
        {
            $charge = [$charge];
        }

        if(empty($id_method) && empty($id_membrane) && empty($id_compound) && empty($charge))
        {
            return [];
        }
        
        $interaction_model = new Interactions();

		$data = $interaction_model
            ->where("visibility", Interactions::VISIBLE)
            ->select_list('id, charge');

        if(!empty($id_method))
        {
            $data->in('id_method', $id_method);
        }
        if(!empty($id_membrane))
        {
            $data->in('id_membrane', $id_membrane);
        }
        if(!empty($id_compound))
        {
            $data->in('id_substance', $id_compound);
        }
        if(!empty($charge))
        {
            $data->in('charge', $charge);
        }

        $data = $data->get_all();

		$result = array();

		foreach($data as $row)
		{
			$result[] = array
			(
				'id' => $row->id,
				'charge' => $row->charge
			);
		}

        return $result;
    }

    /**
     * Returns all passive interaction for given compound x membrane x method combination
     * 
     * @GET
     * @param $id_compound
     * @param $id_method
     * @param $id_membrane
     * @param $id_reference
     * @param @default[FALSE] $group_by
     * 
     * @PATH(/all/passive)
     * 
     * @author Jakub Juracka <jakub.juracka@upol.cz>
     */
    public function get_all_passive_interactions($id_compound, $id_method, $id_membrane, $id_reference, $group_by)
    {
        $obj = Interactions::instance();
        $check = false;

        // Allowed group_by types
        $allowed_gb = array
        (
            'membrane',
            'method',
            'compound',
            'reference'
        );

        if($group_by && !in_array($group_by, $allowed_gb))
        {
            ResponseBuilder::bad_request('Invalid group_by parameter');
        }

        if(is_numeric($id_membrane))
        {
            $id_membrane = [$id_membrane];
        }
        if(is_numeric($id_method))
        {
            $id_method = [$id_method];
        }
        if(is_numeric($id_compound))
        {
            $id_compound = [$id_compound];
        }
        if(is_numeric($id_reference))
        {
            $id_reference = [$id_reference];
        }

        if(is_array($id_membrane) && $group_by !== 'membrane')
        {
            $obj->in('id_membrane', $id_membrane);
            $check = true;
        }
        if(is_array($id_method) && $group_by !== 'method')
        {
            $obj->in('id_method', $id_method);
            $check = true;
        }
        if(is_array($id_compound) && $group_by !== 'compound')
        {
            $obj->in('id_substance', $id_compound);
            $check = true;
        }
        if(is_array($id_reference) && $group_by !== 'reference')
        {
            $obj->in('id_reference', $id_reference);
            $check = true;
        }

        if(!$check)
        {
            ResponseBuilder::bad_request('Invalid input parameters.');
        }

        $result = [];
        
        if(!$group_by)
        {
            $interactions = $obj->get_all();

            foreach($interactions as $i)
            {
                // Prepare data
                $i->substance = $i->substance ? $i->substance->as_array() : NULL;
                $i->membrane_name = $i->membrane->name;
                $i->membrane_CAM = $i->membrane->CAM;
                $i->method_name = $i->method->name;
                $i->method_CAM = $i->membrane->CAM;
                $i->primary_reference = $i->reference ? $i->reference->citation : NULL;
                $i->secondary_reference = $i->dataset && $i->dataset->publication ? $i->dataset->publication->citation : NULL;
                $i->secondary_reference_id = $i->dataset && $i->dataset->publication ? $i->dataset->publication->id : NULL;

                $result[] = $i->as_array(NULL, TRUE);
            }

            if(count($result) == 1)
            {
                return $result[0];
            }
        }
        else
        {
            $data = [];
            $name = '';

            switch($group_by)
            {
                case 'membrane':
                    if(!is_array($id_membrane))
                    {
                        // Get available membrane ids
                        $rows = $obj->select_list('id_membrane')->distinct()->get_all(false);
                        $id_membrane = [];
                        foreach($rows as $r)
                        {
                            $id_membrane[] = $r->id_membrane;
                        }
                    }
                    foreach($id_membrane as $id)
                    {
                        $m = new Membranes($id);
                        if(!$m->id)
                        {
                            continue;
                        }
                        $name = $m->name;
                        $data[$id] = $obj->where('id_membrane', $id)->get_all();
                    }
                    break;
                case 'method':
                    if(!is_array($id_method))
                    {
                        // Get available membrane ids
                        $rows = $obj->select_list('id_method')->distinct()->get_all();
                        $id_method = [];
                        foreach($rows as $r)
                        {
                            $id_method[] = $r->id_method;
                        }
                    }
                    foreach($id_method as $id)
                    {
                        $m = new Methods($id);
                        if(!$m->id)
                        {
                            continue;
                        }
                        $name = $m->name;
                        $data[$id] = $obj->where('id_method', $id)->get_all();
                    }
                    break;
                case 'compound':
                    if(!is_array($id_compound))
                    {
                        // Get available membrane ids
                        $rows = $obj->select_list('id_substance')->distinct()->get_all();
                        $id_compound = [];
                        foreach($rows as $r)
                        {
                            $id_compound[] = $r->id_substance;
                        }
                    }
                    foreach($id_compound as $id)
                    {
                        $m = new Substances($id);
                        if(!$m->id)
                        {
                            continue;
                        }
                        $name = $m->name;
                        $data[$id] = $obj->where('id_substance', $id)->get_all();
                    }
                    break;
                case 'reference':
                    if(!is_array($id_reference))
                    {
                        // Get available membrane ids
                        $rows = $obj->select_list('id_reference')->distinct()->get_all();
                        $id_reference = [];
                        foreach($rows as $r)
                        {
                            $id_reference[] = $r->id_reference;
                        }
                    }
                    foreach($id_reference as $id)
                    {
                        $m = new Publications($id);
                        if(!$m->id)
                        {
                            continue;
                        }
                        $name = $m->citation;
                        $data[$id] = $obj->where('id_reference', $id)->get_all();
                    }
                    break;
            }

            foreach($data as $id => $rows)
            {
                if(!count($rows))
                {
                    continue;
                }

                $d = [];
                foreach($rows as $i)
                {
                    $i->substance = $i->substance ? $i->substance->as_array() : NULL;
                    $i->membrane_name = $i->membrane->name;
                    $i->membrane_idTag = $i->membrane->idTag;
                    $i->membrane_CAM = $i->membrane->CAM;
                    $i->method_name = $i->method->name;
                    $i->method_idTag = $i->method->idTag;
                    $i->method_CAM = $i->membrane->CAM;
                    $i->primary_reference = $i->reference ? $i->reference->citation : NULL;
                    $i->secondary_reference = $i->dataset && $i->dataset->publication ? $i->dataset->publication->citation : NULL;
                    $i->secondary_reference_id = $i->dataset && $i->dataset->publication ? $i->dataset->publication->id : NULL;

                    $d[] = $i->as_array(NULL, TRUE);
                }

                $result[] = array
                (
                    'id_' . $group_by => $id,
                    $group_by . "_name" => $name,
                    'data'  => $d
                );
            }
        }

        return $result;
    }

    /**
     * 
     * 
     * @GET
     * @param $id_compound
     * @param $id_method
     * @param $id_membrane
     * 
     * @PATH(/count/passive)
     */
    public function count_passive_by_params($id_compound, $id_method, $id_membrane)
    {
        $interaction_model = new Interactions();

        $count = $interaction_model->where(array
            (
                'id_substance'  => $id_compound,
                'id_membrane'   => $id_membrane,
                'id_method'     => $id_method,
                'visibility'    => Interactions::VISIBLE
            ))
            ->select_list('COUNT(*) as count')
            ->get_one()
            ->count;

        return array('count' => $count);
    }
    
}