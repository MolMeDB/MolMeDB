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
    ########## interactions/passive/<id><?membrane><?method><?reference> ###########
    ################################################################################

    /**
     * Returns passive interactions by ids
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @required $id
     * @param @optional $membrane
     * @param @optional $method
     * @param @optional $reference
     * 
     * @PATH(/passive/<id:.+>)
     */
    public function P_passive_interactions($id, $membrane, $method, $reference)
    {
        if(!is_array($id))
		{
			$id = [$id];
		}

		if(empty($id) || !$id[0])
		{
			ResponseBuilder::bad_request('Invalid id list.');
		}

        // Find membrane if set
        $m_records = null;
        if($membrane)
        {
            $by_identifier = new Membranes($membrane);
            if(!$by_identifier->id)
            {
                $by_identifier = Membranes::instance()->where('name LIKE', $membrane)->get_one();
            }

            if($by_identifier->id)
            {
                $m_records = [$by_identifier];
            }
            else
            {
                // Try to find category by path
                $cats = explode('.', $membrane);
                $cats_objects = [];

                foreach($cats as $c)
                {
                    $exists = Enum_types::instance()->where(array
                    (
                        'name LIKE' => $c,
                        'type'      => Enum_types::TYPE_MEMBRANE_CATS
                    ))->get_one();

                    if(!$exists->id)
                    {
                        ResponseBuilder::not_found('Membrane/category `' . $c .'` not found.');
                    }

                    $cats_objects[] = $exists;
                }

                $current = array_shift($cats_objects);
                $curr_link_id = null;

                foreach($cats_objects as $c)
                {
                    $link = Enum_type_links::instance()->where(array
                    (
                        'id_enum_type' => $c->id,
                        'id_enum_type_parent' => $current->id,
                    ) + ($curr_link_id ? ['id_parent_link' => $curr_link_id] : ['id_parrent_link IS NULL' => NULL]))
                    ->get_one();

                    if(!$link->id)
                    {
                        ResponseBuilder::not_found('Category `' . $c->name . '` does not have parent `' . $current->name . '`');
                    }

                    $current = $c;
                    $curr_link_id = $link->id;
                }

                $link_ids = [];

                // Only one category provided
                if(!$curr_link_id)
                {
                    $links = Enum_type_links::instance()->where('id_enum_type_parent', $current->id)->get_all();
                    $link_ids = arr::get_values($links, 'id');
                    $total = 0;

                    while($total != count($link_ids))
                    {
                        $total = count($link_ids);
                        $links = Enum_type_links::instance()->in('id_parent_link', $link_ids)->get_all();
                        $link_ids = array_merge($link_ids, arr::get_values($links, 'id'));
                    }
                }
                else
                {
                    $links = Enum_type_links::instance()
                        ->where('id_parent_link', $curr_link_id)
                        ->get_all();
                }

                if(count($links))
                {
                    $m_records = Membranes::instance()
                        ->join('JOIN membrane_enum_type_links etl ON etl.id_membrane = membranes.id')
                        ->in('etl.id_enum_type_link', arr::get_values($links, 'id'))
                        ->get_all();
                }


                $m_records = Membranes::instance()
                    ->join('JOIN membrane_enum_type_links etl ON etl.id_membrane = membranes.id AND etl.id_enum_type_link = ' . $curr_link_id)
                    ->get_all();
            }
        }

        echo count($m_records);
        die;
        
		$result = array();

		foreach($id as $q_id)
		{
            $substance = Substances::get_by_any_identifier($q_id);

            if(!$substance->id)
            {
                continue;
            }
            
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