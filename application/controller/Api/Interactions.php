<?php

/**
 * Interaction controller
 */
class ApiInteractions extends ApiController
{
    ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ################################################################################
    ############################### PUBLIC #########################################
    ################################################################################
    ################################################################################

    ////////////////////////////////////////////////////////////////////////////////


    ################################################################################
    ########## interactions/passive/<?id><?membrane><?method><?reference> ###########
    ################################################################################

    
    /**
     * Returns COUNT of passive interactions
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @optional $id
     * @param @optional $membrane
     * @param @optional $method
     * @param @optional $reference
     * 
     * @PATH(/passive/total)
     */
    public function P_passive_interactions_count($id, $membrane, $method, $reference)
    {
        $this->P_passive_interactions($id, $membrane, $method, $reference);
        $data = $this->responses[HeaderParser::JSON];

        $result = array
        (
            'compounds' => array
            (
                'query' => $id,
                'total_hits' => 0,
            ),
            'membranes' => array
            (
                'query' => $membrane,
                'total_hits' => 0
            ),
            'methods' => array
            (
                'query' => $method,
                'total_hits' => 0,
            ),
            'references' => array
            (
                'query' => $reference,
                'total_hits' => 0
            ),
            'interactions' => array
            (
                'total_hits' => 0
            )
        );

        if($data)
        {
            $mems = [];
            $mets = [];
            $refs = [];

            foreach($data as $comp)
            {
                $result['compounds']['total_hits']++;
                $result['interactions']['total_hits']+=count($comp['passive_interactions']);

                foreach($comp['passive_interactions'] as $pi)
                {
                    $mems[] = $pi['Membrane']['id'];
                    $mets[] = $pi['Method']['id'];
                    $refs[] = isset($pi['References']['primary']['citation']) ? $pi['References']['primary']['citation'] : null;
                    $refs[] = isset($pi['References']['secondary']['citation']) ? $pi['References']['secondary']['citation'] : null;
                }
            }

            $result['membranes']['total_hits'] = count(array_unique($mems));
            $result['methods']['total_hits'] = count(array_unique($mets));
            $result['references']['total_hits'] = count(array_unique($refs));
        }

        $this->responses = null;
        return $result;
    }

    ################################################################################
    ########## interactions/passive/<?id><?membrane><?method><?reference> ###########
    ################################################################################

    /**
     * Returns passive interactions
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @optional $id
     * @param @optional $membrane
     * @param @optional $method
     * @param @optional $reference
     * 
     * @PATH(/passive)
     */
    public function P_passive_interactions($id, $membrane, $method, $reference)
    {
        if(!is_array($id) && $id)
		{
			$id = [$id];
		}

        // Find membrane if set
        $mem_records = null;
        if($membrane)
        {
            $mem_records = Membranes::instance()->find_all($membrane);

            if(empty($mem_records))
            {
                    ResponseBuilder::not_found('Membrane `' . $membrane . '` not found.');
            }
        }

        // Find method if set
        $met_records = null;
        if($method)
        {
           $met_records = Methods::instance()->find_all($method);

           if(empty($met_records))
           {
                ResponseBuilder::not_found('Method `' . $method . '` not found.');
           }
        }

        // Find reference if set
        $publication = null;
        if($reference)
        {
            $publication = Publications::instance()->get_by_query($reference);

            if(!$publication->id)
            {
                ResponseBuilder::not_found('Publication `' . $reference . '` not found in MolMeDB.');
            }
        }

        if(empty($id) && !$mem_records && !$met_records && !$publication)
        {
            ResponseBuilder::bad_request('At least one parameter value must be set.');
        }

		$result = array();
            
        $int = Interactions::instance();

        if(!empty($id))
        {
            $ids = [];
            foreach($id as $q_id)
            {
                $s = new Substances($q_id);
                $ids[] = $s->id;
            }

            $int->in('id_substance', $ids);
        }

        if(!empty($mem_records))
        {
            $int->in('id_membrane', arr::get_values($mem_records, 'id'));
        }

        if(!empty($met_records))
        {
            $int->in('id_method', arr::get_values($met_records, 'id'));
        }

        if(isset($publication))
        {
            $int->in('id_reference', [$publication->id]);
        }

        $int = $int->order_by('id_substance')->get_all();

        $last = new Substances();
        $pi = [];
        $row = [];

        foreach($int as $i)
        {
            if($i->id_substance != $last->id)
            {
                // Save last if set
                if($last->id)
                {
                    $row['passive_interactions'] = $pi;
                    $result[] = $row;
                }

                // Create new
                $pi = [];
                $last = new Substances($i->id_substance);
                $row = array
                (
                    'substance' => $last->get_public_detail(),
                    'passive_interactions' => []
                );
            }

            $pi[] = $i->get_public_detail();
        }

        $row['passive_interactions'] = $pi;
        $result[] = $row;

        $view = new View('api/interactions/passive');
        $view->data = $result;

        $this->responses = array
        (
            HeaderParser::HTML => $view->__toString(),
            HeaderParser::JSON => $result
        );
    }


    ################################################################################
    ########## interactions/active/<?id><?membrane><?method><?reference> ###########
    ################################################################################

    
    /**
     * Returns COUNT of active interactions
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @optional $id
     * @param @optional $target
     * @param @optional $reference
     * 
     * @PATH(/active/total)
     */
    public function P_active_interactions_count($id, $target, $reference)
    {
        $this->P_active_interactions($id, $target, $reference);
        $data = $this->responses[HeaderParser::JSON];

        $result = array
        (
            'compounds' => array
            (
                'query' => $id,
                'total_hits' => 0,
            ),
            'targets' => array
            (
                'query' => $target,
                'total_hits' => 0
            ),
            'references' => array
            (
                'query' => $reference,
                'total_hits' => 0
            ),
            'interactions' => array
            (
                'total_hits' => 0
            )
        );

        if($data)
        {
            $targs = [];
            $refs = [];

            foreach($data as $comp)
            {
                $result['compounds']['total_hits']++;
                $result['interactions']['total_hits']+=count($comp['active_interactions']);

                foreach($comp['active_interactions'] as $pi)
                {
                    $targs[] = $pi['Target']['Name'];
                    $refs[] = isset($pi['References']['primary']['citation']) ? $pi['References']['primary']['citation'] : null;
                    $refs[] = isset($pi['References']['secondary']['citation']) ? $pi['References']['secondary']['citation'] : null;
                }
            }

            $result['targets']['total_hits'] = count(array_unique($targs));
            $result['references']['total_hits'] = count(array_unique($refs));
        }

        $this->responses = null;
        return $result;
    }

    ################################################################################
    ########## interactions/passive/<?id><?membrane><?method><?reference> ###########
    ################################################################################

    /**
     * Returns active interactions
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @optional $id
     * @param @optional $target
     * @param @optional $reference
     * 
     * @PATH(/active)
     */
    public function P_active_interactions($id, $target, $reference)
    {
        if(!is_array($id) && $id)
		{
			$id = [$id];
		}

        // Find method if set
        $target_records = null;
        if($target)
        {
           $target_records = Transporter_targets::instance()->find_all($target);

           if(empty($target_records))
           {
                ResponseBuilder::not_found('Transporter target `' . $target . '` not found.');
           }
        }

        // Find reference if set
        $publication = null;
        if($reference)
        {
            $publication = Publications::instance()->get_by_query($reference);

            if(!$publication->id)
            {
                ResponseBuilder::not_found('Publication `' . $reference . '` not found in MolMeDB.');
            }
        }

        if(empty($id) && !$target_records && !$publication)
        {
            ResponseBuilder::bad_request('At least one parameter value must be set.');
        }

		$result = array();
            
        $int = Transporters::instance();

        if(!empty($id))
        {
            $ids = [];
            foreach($id as $q_id)
            {
                $s = new Substances($q_id);
                $ids[] = $s->id;
            }

            $int->in('id_substance', $ids);
        }

        if(!empty($target_records))
        {
            $int->in('id_target', arr::get_values($target_records, 'id'));
        }

        if(isset($publication))
        {
            $int->join("JOIN transporter_datasets td ON td.id = transporters.id_dataset AND td.id_reference = " . $publication->id);
        }

        $int = $int->order_by('id_substance')->get_all();

        $last = new Substances();
        $ai = [];
        $row = [];

        foreach($int as $i)
        {
            if($i->id_substance != $last->id)
            {
                // Save last if set
                if($last->id)
                {
                    $row['active_interactions'] = $ai;
                    $result[] = $row;
                }

                // Create new
                $ai = [];
                $last = new Substances($i->id_substance);
                $row = array
                (
                    'substance' => $last->get_public_detail(),
                    'active_interactions' => []
                );
            }

            $ai[] = $i->get_public_detail();
        }

        $row['active_interactions'] = $ai;
        $result[] = $row;

        $view = new View('api/interactions/active');
        $view->data = $result;

        $this->responses = array
        (
            HeaderParser::HTML => $view->__toString(),
            HeaderParser::JSON => $result
        );
    }


    ///////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ################################################################################
    ############################### END OF PUBLIC ##################################
    ################################################################################
    ################################################################################

    ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ################################################################################
    ############################### INTERNAL #######################################
    ################################################################################
    ################################################################################

    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Returns passive interactions by ids
     * 
     * @POST
     * @INTERNAL
     * 
     * @param @required $id
     * 
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
     * 
     * @param @required $id
     * 
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
                'secondary_reference' => $int->dataset && $int->dataset->publication ? $int->dataset->publication->citation : null,
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
     * Returns passive interactions by ids
     * 
     * @POST
     * @INTERNAL
     * 
     * @param @required $id
     * 
     * @PATH(/detail/active)
     */
    public function get_active_interactions_POST($id)
    {
        return $this->get_active_interactions($id);
    }

    /**
     * Returns active interactions details
     * 
     * @GET
     * 
     * @param @required $id
     * 
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
                'secondary_reference' => $int->dataset->reference ? $int->dataset->reference->citation : null,
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
     * 
     * @param $id_method
     * @param $id_membrane
	 * @param $id_compound
	 * @param $charge
     * 
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
     * 
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

        // HTML content template
        $t = new Table([
            Table::S_PAGINATIOR_POSITION => 'left',
            Table::FILTER_HIDE_COLUMNS => true,
            Table::FILTER_HIDE_COLUMNS_DEFAULT => true,
            Table::S_NODATA => "No data found..."
        ]);

        $t->column('membrane_name')
            ->sortable()
            ->numeric()
            ->link('browse/membranes?target=', 'membrane_id')
            ->css(array
            (
                'text-align' => 'center' // Centering - flex - justify-content: center;
            ))
            ->title('Membrane');

        $t->column('method_name')
            ->numeric()
            ->link('browse/methods?target=', 'method_id')
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->title('Method');

        $t->column('Q')
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->numeric()
            ->title('Q', 'Charge');

        $t->column('temperature')
            ->css(array
            (
                'text-align' => 'center'
            ), true)
            ->title('T <br/><label class="units">[°C]</label>', "Temperature"); 

        $t->column('Position')
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->accuracy('Position_acc')
            ->title('X<sub>min</sub> <br/><label class="units">[nm]</label>', "Position of minima along the membrane normal from the membrane center");

        $t->column('Penetration')
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->accuracy('Penetration_acc')
            ->title('&Delta;G<sub>pen</sub> <br/><label class="units">[kcal / mol]</label>', "Penetration barrier (maximum - minimum)");

        $t->column('Water')
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->accuracy('Water_acc')
            ->title('&Delta;G<sub>wat</sub> <br/><label class="units">[kcal / mol]</label>', "A depth of minima within membrane");

        $t->column('LogK')
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->accuracy('LogK_acc')
            ->title('LogK<sub>m</sub> <br/><label class="units">[mol<sub>m</sub>/mol<sub>w</sub>]</label>', "Partition coefficient membrane/water");

        $t->column('LogPerm')
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->accuracy('LogPerm_acc')
            ->title('LogPerm <br/><label class="units">[cm/s]</label>', "Permeability coefficient");

        $t->column('theta')
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->accuracy('theta_acc')
            ->title('Theta<br/><label class="units">[°]</label>', "Contact angle in membrane");

        $t->column('abs_wl')
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->accuracy('abs_wl_acc')
            ->title('Abs_wl<br/><label class="units">[nm]</label>', "Peak absorption wavelenght");

        $t->column('fluo_wl')
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->accuracy('fluo_wl_acc')
            ->title('Fluo_wl<br/><label class="units">[nm]</label>', "Peak fluorescence wavelenght");

        $t->column('qy')
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->accuracy('qy_acc')
            ->title('QY<br/><label class="units"></label>', "Quantum yield");

        $t->column('lt')
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->accuracy('lt_acc')
            ->title('lt<br/><label class="units">[ns]</label>', "Fluorescence lifetime");

        $t->column('note')
            ->long_text()
            ->title('Note');

        $t->column('primary_reference')
            ->long_text()
            ->title('Primary<br/> reference', "Original source");

        $t->column('secondary_reference')
            ->long_text()
            ->title('Secondary<br/> reference', "Source of input into databse if different from original");

        $result = [];
        
        if(!$group_by)
        {
            $interactions = $obj->get_all();

            foreach($interactions as $i)
            {
                // Prepare data
                $i->substance = $i->substance ? $i->substance->as_array() : NULL;
                $i->membrane_id = $i->membrane->id;
                $i->membrane_name = $i->membrane->name;
                $i->membrane_CAM = $i->membrane->CAM;
                $i->method_id = $i->method->id;
                $i->method_name = $i->method->name;
                $i->method_CAM = $i->membrane->CAM;
                $i->primary_reference = $i->reference ? $i->reference->citation : NULL;
                $i->secondary_reference = $i->dataset && $i->dataset->publication ? $i->dataset->publication->citation : NULL;
                $i->secondary_reference_id = $i->dataset && $i->dataset->publication ? $i->dataset->publication->id : NULL;

                $result[] = $i->as_array(NULL, TRUE);
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

        $t->datasource(new Iterable_object($result, true));

        $this->responses = array
        (
            'default'   => $result,
        );

        if(!$group_by)
        {
            $this->responses[HeaderParser::HTML] = $t->html();
        }
    }

    /**
     * Returns counts of passive interactions for given params
     * 
     * @POST
     * 
     * @param @optional $id_membranes
     * @param @optional $id_methods
     * @param @optional $id_molecules
     * @param @optional @default[true] $only_total
     * @param @optional @default[AND] $logic
     * 
     * @Path(/count)
     */
    public function get_count($id_membranes, $id_methods, $id_molecules, $only_total, $logic)
    {
        $total = Interactions::instance()
            ->where('visibility', Interactions::VISIBLE);

        if(strtolower($logic) !== 'or')
        {
            $logic = 'AND';
        }
        else
        {
            $logic = "OR";
        }

        if($id_membranes)
        {
            if(!is_array($id_membranes))
            {
                $id_membranes = [$id_membranes];
            }
            
            $total->in('id_membrane', $id_membranes);
        }

        if($id_methods)
        {
            if(!is_array($id_methods))
            {
                $id_methods = [$id_methods];
            }
            
            $total->in('id_method', $id_methods);
        }

        if($id_molecules)
        {
            if(!is_array($id_molecules))
            {
                $id_molecules = [$id_molecules];
            }

            $total->in('id_substance', $id_molecules);
        }

        if(empty($id_methods) || empty($id_membranes) || $logic == "AND")
        {
            $total = $total->count_all();
        }
        else
        {
            if($id_molecules)
            {
                $total = Db::instance()->queryOne('
                    SELECT COUNT(*) as count
                    FROM
                    (
                        SELECT DISTINCT i.id
                        FROM interaction i 
                        WHERE (i.id_membrane IN ("' . implode('","',$id_membranes) . '") OR 
                            i.id_method IN ("' . implode('","', $id_methods) . '")) AND 
                            i.visibility = ? AND id_substance IN ("' . implode('","',$id_molecules) . '")
                    ) as t
                ', array(Interactions::VISIBLE))->count;
            }
            else
            {
                $total = Db::instance()->queryOne('
                    SELECT COUNT(*) as count
                    FROM
                    (
                        SELECT DISTINCT i.id
                        FROM interaction i 
                        WHERE (i.id_membrane IN ("' . implode('","',$id_membranes) . '") OR 
                            i.id_method IN ("' . implode('","', $id_methods) . '")) AND 
                            i.visibility = ? 
                    ) as t
                ', array(Interactions::VISIBLE))->count;
            }
        }

        if($only_total)
        {
            return ['total' => $total];
        }

        $result = ['total' => $total];

        // Get by groups
        if(!$id_membranes && $id_methods)
        {
            $membranes = Membranes::instance()->get_all();

            $result['membranes'] = [];

            foreach($membranes as $mem)
            {
                $total = Interactions::instance()->where(array
                (
                    'visibility' => Interactions::VISIBLE,
                    'id_membrane' => $mem->id
                ))
                    ->in('id_method', $id_methods)
                    ->count_all();

                $result['membranes'][] = array
                (
                    'id' => $mem->id,
                    'name' => $mem->name,
                    'total' => $total
                );
            }
        }
        else if($id_membranes && !$id_methods)
        {
            $methods = Methods::instance()->get_all();

            $result['methods'] = [];

            foreach($methods as $met)
            {
                $total = Interactions::instance()->where(array
                (
                    'visibility' => Interactions::VISIBLE,
                    'id_method' => $met->id
                ))
                    ->in('id_membrane', $id_membranes)
                    ->count_all();

                $result['methods'][] = array
                (
                    'id' => $met->id,
                    'name' => $met->name,
                    'total' => $total
                );
            }
        }

        return $result;
    }
}