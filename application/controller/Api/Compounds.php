<?php

use EasyRdf\Http\Response;

/**
 * Internal API class for processing request 
 * related with detail loading of substances
 * 
 */
class ApiCompounds extends ApiController
{
    ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ################################################################################
    ############################### PUBLIC #########################################
    ################################################################################
    ################################################################################

    ////////////////////////////////////////////////////////////////////////////////


    ##############################################################
    ########## compounds/detail/<id>?ipassive&iactive ############
    ##############################################################

    /**
     * Returns molecule data
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @required $id
     * @param @default[false] $ipassive - Include passive interactions?
     * @param @default[false] $iactive - Include active interactions?
     * 
     * @PATH(/detail/<id:.+>)
     */
    public function P_detail($id, $ipassive, $iactive)
    {
        if(!is_array($id))
        {
            $id = [$id];
        }

        $substances = [];

        foreach($id as $id_q)
        {
            $subs = Substances::get_by_any_identifier($id_q);

            if(!$subs->id)
            {
                if(count($id) == 1)
                {
                    ResponseBuilder::not_found('Compound with id `' . $id_q . '` not found.');
                }
                else
                {
                    continue;
                }
            }

            $substances[$id_q] = $subs;
        }

        if(!count($substances))
        {
            ResponseBuilder::not_found('No compound from list found.');
        }

        $results = array();

        foreach($substances as $query => $subs)
        {
            $result = [];
            $result['query'] = $query;
            $result['detail'] = $subs->get_public_detail();
            
            if($ipassive)
            {
                $result['passive_interactions'] = [];
                $interaction_model = new Interactions();
                $interactions = $interaction_model->where(array(
                    'id_substance' => $subs->id,
                    'visibility'   => Interactions::VISIBLE
                ))->get_all();
                
                foreach($interactions as $i)
                {
                    $result['passive_interactions'][] = $i->get_public_detail();
                }
            }
            
            if($iactive)
            {
                $result['active_interactions'] = [];
                $transporter_model = new Transporters();
                $transporters = $transporter_model
                    ->join('transporter_datasets as td ON td.id = transporters.id_dataset AND td.visibility = ' . Transporter_datasets::VISIBLE)
                    ->where('id_substance', $subs->id)
                    ->select_list('transporters.*')
                    ->get_all();

                foreach($transporters as $t)
                {
                    $result['active_interactions'][] = $t->get_public_detail();
                }
            }

            $results[] = $result;
        }

        if(count($id) > 1)
        {
            $err = 'Invalid output format for multiquery. Set just one identifier or use JSON output format instead.';
            $this->responses = array
            (
                HeaderParser::HTML => $err,
                HeaderParser::XLSX => $err,
                HeaderParser::JSON => $results
            );
        }
        else
        {
            $result = $results[0];
            // Prepare HTML content
            $html = new View('api/compounds/detail');
            $html->data = new Iterable_object($result);
            $html->substance = $substances[$id[0]];

            // Prepare XLSX
            $sheets = array
            (
                'General',
                'Passive interactions',
                'Active interactions'
            );

            $headers = array
            (   
                // General info header
                array
                (
                    'identifiers.name' => 'Names',
                    'identifiers.molmedb' => "MolMeDB identifier",
                    'identifiers.inchikey'=> "InchiKey",
                    'identifiers.smiles'  => 'SMILES',
                    'identifiers.pubchem' => 'Pubchem IDs',
                    'identifiers.pdb'     => 'PDB IDs',
                    'identifiers.drugbank'=> 'Drugbank IDs',
                    'identifiers.chembl'  => 'ChEMBL IDs',
                    'molecular_weight'    => 'Molecular weight',
                    'logp'                => 'LogP'
                ),
                // Passive
                array
                (
                    'Membrane.name'         => 'Membrane',
                    'Method.name'           => 'Method',
                    'Charge'                => 'Charge',
                    'Temperature.value'     => "Temperature",
                    'Xmin.value'            => "Xmin",
                    'Gpen.value'            => "Gpen",
                    'Gwat.value'            => "Gwat",
                    'LogK.value'            => "LogK",
                    'LogPerm.value'            => "LogPerm",
                    'Theta.value'            => "Theta",
                    'Abs_wl.value'            => "Abs_wl",
                    'Fluo_wl.value'            => "Fluo_wl",
                    'QY.value'            => "QY",
                    'lt.value'            => "lt",
                    'References.primary'  => "Primary reference",
                    'References.secondary'=> "Secondary reference",
                ),
                // Active
                array 
                (
                    'Target.Name'       => "Target",
                    'Target.Uniprot_id' => "Uniprot ID",
                    'Type'              => "Type",
                    "Note"              => "Note",
                    "pKm"               => "pKm",
                    "pKi"               => "pKi",
                    "pEC50"             => "pEC50",
                    "pIC50"             => "pIC50",
                    'References.Primary'  => "Primary reference",
                    'References.Secondary'=> "Secondary reference",
                )
            );

            $fields = array
            (
                [$result['detail']],
                isset($result['passive_interactions']) ? $result['passive_interactions'] : [],
                isset($result['active_interactions']) ? $result['active_interactions'] : []
            );
    
            $this->responses = array
            (
                HeaderParser::HTML => $html,
                HeaderParser::XLSX => array
                (
                    'filename'   => 'compounds_detail',
                    "sheets" => $sheets,
                    'headers'=> $headers,
                    'data'   => $fields
                ),
                HeaderParser::JSON => $result
            );
        }
    }

    /**
     * Returns molecule data
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @required $id
     * @param @default[false] $ipassive - Include passive interactions?
     * @param @default[false] $iactive - Include active interactions?
     * 
     * @PATH(/detail)
     */
    public function __P_detail($id, $ipassive, $iactive)
    {
        return $this->P_detail($id, $ipassive, $iactive);
    }

    /**
     * Returns molecule data
     * 
     * @POST
     * @PUBLIC
     * 
     * @param @required $id
     * @param @default[false] $ipassive - Include passive interactions?
     * @param @default[false] $iactive - Include active interactions?
     * 
     * @PATH(/detail)
     */
    public function __P_detail_POST($id, $ipassive, $iactive)
    {
        return $this->P_detail($id, $ipassive, $iactive);
    }



    //////////////////////////////////////////////////////////////

    ##############################################################
    ########## compounds/exists/<id> #############################
    ##############################################################

    /**
     * Fast check of existence of queried molecule
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @required $id
     * 
     * @PATH(/exists/<id:.+>)
     * @author Jakub Juracka
     */
    public function P_check_existence($id)
    {
        if(!is_string($id) && !is_numeric($id))
        {
            ResponseBuilder::bad_request('Invalid `id` parameter.');
        }

        $record = Substances::get_by_any_identifier($id);

        if($record->id)
        {
            return 'true';
        }
        else
        {
            return 'false';
        }
    }

    /**
     * Fast check of existence of queried molecule
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @required $id
     * 
     * @PATH(/exists)
     * @author Jakub Juracka
     */
    public function __P_check_existence($id)
    {
        return $this->P_check_existence($id);
    }

    //////////////////////////////////////////////////////////////

    ##############################################################
    ########## compounds/frealitves/<id> #########################
    ##############################################################

    /**
     * Returns functional relatives of given molecule
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @required $id
     * @PATH(/frelatives/<id:.+>)
     */
    public function P_func_relatives($id)
    {
        if(is_array($id))
        {
            ResponseBuilder::bad_request('Invalid `id` parameter value.');
        }

        $substance = Substances::get_by_any_identifier($id);

        if(!$substance->id)
        {
            ResponseBuilder::not_found('Compound with id=`' . $id . '` not found.');
        }

        $sp_model = new Substance_pairs();

        $data = $sp_model->by_substance($substance->id);

        // Prepare JSON
        $json = [
            'query' => $id,
            'q_substance' => $substance->get_public_detail(),
            'relatives' => []
        ];

        try
        {
            $rdkit = new Rdkit();
        }
        catch(Exception $e)
        {
            $rdkit = false;
        }

        foreach($data as $row)
        {
            if($row->id_substance_1 == $row->id_substance_2)
            {
                continue;
            }

            $rel = [];
            $reverse = ($row->id_substance_1 == $substance->id);

            $rel['f_substance'] = $reverse ? $row->substance_2->get_public_detail() : $row->substance_1->get_public_detail();
            $rel['tanimoto_sim'] = $rdkit !== false ? $rdkit->compute_similarity($row->substance_1->SMILES, $row->substance_2->SMILES, Rdkit::METRIC_TANIMOTO) : false;
            $rel['tanimoto_sim'] = $rel['tanimoto_sim'] === false ? "N/A" : $rel['tanimoto_sim'];
            
            $core = !$reverse ? $row->fragmentation_2->core : $row->fragmentation_1->core;

            $rel['detail'] = array
            (
                'core' => $core->fragment->fill_link_numbers($core->fragment->smiles, explode(',', $core->links)),
                'add' => array
                (
                    'query' => array(),
                    'relative' => array()
                )
            );

            foreach((!$reverse ? $row->fragmentation_2->side : $row->fragmentation_1->side) as $sf)
            {
                $keys = ['relative', 'query'];
                $target = $reverse ? 0 : 1;

                $rel['detail']['add'][$keys[$target]][] = $sf->fragment->fill_link_numbers($sf->fragment->smiles, explode(',', $sf->links));
            }

            $json['relatives'][] = $rel;
        }

        // Prepare HTML output
        $view = new View('fragments/relatives');
        $view->data = $data;
        $view->substance = $substance;

        $this->responses = array
        (
            HeaderParser::HTML => $view->__toString(),
            HeaderParser::JSON => $json
        );
    }

    /**
     * ALIAS
     *
     * @GET
     * @PUBLIC
     * 
     * @param @required $id
     * @PATH(/frelatives)
     */
    public function __P_func_relatives($id)
    {
        return $this->P_func_relatives($id);
    }


    //////////////////////////////////////////////////////////////

    ##############################################################
    ########## compounds/structure/<id> #########################
    ##############################################################

    /**
     * Returns structure of molecule
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @required $id
     * 
     * @PATH(/structure/<id:.+>)
     */
    public function P_structure($id)
    {
        if(is_array($id))
        {
            ResponseBuilder::bad_request('Invalid `id` parameter value.');
        }

        $substance = Substances::get_by_any_identifier($id);

        if(!$substance->id)
        {
            ResponseBuilder::not_found('Compound with id=`' . $id . '` not found.');
        }

        $structures_3d = array_values($substance->get_all_3D_structures());
        $structure_file = count($structures_3d) ? $structures_3d[0] : null;

        // Not check SSL certificate
        $arrContextOptions=array(
            "ssl"=>array(
                "verify_peer"=>false,
                "verify_peer_name"=>false,
            ),
        ); 

        $html = new View('api/compounds/structure');
        $html->substance = $substance;
        $html->structure_3d = $structure_file;

        $this->responses = array
        (
            HeaderParser::SVG => file_get_contents('https://molmedb.upol.cz/depict/cow/svg?smi=' . urlencode($substance->SMILES), false, stream_context_create($arrContextOptions)),
            HeaderParser::HTML => $html,
            HeaderParser::SDF => $structure_file !== null ? file_get_contents($structure_file['path']) : 'N/A',
            HeaderParser::JSON => array
            (
                'query'  => $id,
                'smiles' => $substance->SMILES,
                'sdf'    => $structure_file !== null ? file_get_contents($structure_file['path']) : 'N/A'
            ),
            HeaderParser::SMI => $substance->SMILES
        );
    }

    /**
     * ALIAS
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @required $id
     * 
     * @PATH(/structure)
     */
    public function __P_structure($id)
    {
        return $this->P_structure($id);
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
     * Returns molecular fragments of molecule
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @required $id - Substance ID
     * 
     * @PATH(/fragment/<id:^MM\d+>)
     */
    public function getFragments($id)
    {
        $s = new Substances($id);

        if(!$s->id)
        {
            ResponseBuilder::not_found('Invalid substance id.');
        }

        $fragments = $s->get_all_fragments();

        // Get HTML RESPONSE
        $view = new View('fragments/grid');
        $view->fragments = $fragments;

        $this->responses = array
        (
            HeaderParser::HTML => $view->__toString(),
            // 'default' => $fragments
        );
    }

    /**
     * Returns functional relatives
     * 
     * @GET
     * @public
     * 
     * @param @required $id - Substance ID
     * 
     * @PATH(/relatives/<id:^[M]*\d+>)
     */
    public function get_relatives($id)
    {
        $substance = new Substances($id);

        if(!$substance->id)
        {
            ResponseBuilder::not_found('Compound not found.');
        }

        $sp_model = new Substance_pairs();

        $data = $sp_model->by_substance($substance->id);

        $view = new View('fragments/relatives');
        $view->data = $data;
        $view->substance = $substance;

        $this->responses = array
        (
            HeaderParser::HTML => $view->__toString()
        );
    }

    /**
     * Returns similiar molecules
     * 
     * @GET
     * @public
     * 
     * @param @required $id - Substance ID
     * @param @default[0.1] $limit
     * 
     * @PATH(/similarEntry)
     */
    public function find_similiar($id, $limit)
    {
        $s = new Substances($id);

        if(!$s->id)
        {
            ResponseBuilder::not_found('Invalid substance id.');
        }

        $options = $s->get_similar_entries(null, $limit);

        $view = new View('fragments/similarity');
        $view->options = $options;
        $view->substance = $s;

        $this->responses = array
        (
            HeaderParser::HTML => $view->__toString()
        );
    }

    /**
     * Returns substances with interactions for given membrane/method/publication combination
     * 
     * @GET
     * @internal
     * 
     * @param @required $id
     * @param @required $type
     * 
     * @PATH(/ids/byPassiveInteractionType)
     */
    public function get_by_type($id, $type)
    {
        $valid_types = array
		(
			'membrane',
			'method',
			'set'
		);

        if(!in_array($type, $valid_types))
		{
			ResponseBuilder::bad_request('Invalid type. Valid types are: ' . implode(', ', $valid_types));
		}

		if(!$id)
		{
			ResponseBuilder::bad_request('Invalid ID.');
		}

		$inter_model = new Interactions();

		$data = new Iterable_object();
		
		switch($type)
		{
			case 'membrane':
				$data = $inter_model->where(array
					(
						'visibility'	=> Interactions::VISIBLE,
						'id_membrane'	=> $id		
					))
                    ->select_list('id_substance')
                    ->distinct()
					->get_all();
				break;
			
			case 'method':
				$data = $inter_model->where(array
					(
						'visibility'	=> Interactions::VISIBLE,
						'id_method'	=> $id		
					))
                    ->select_list('id_substance')
                    ->distinct()
					->get_all();
				break;
			
			case 'set':
				$data = $inter_model->where(array
					(
						'visibility'	=> Interactions::VISIBLE,
						'id_reference'	=> $id		
					))
                    ->select_list('id_substance')
                    ->distinct()
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

		return $result;
    }

    /**
     * Returns molecule data
     * 
     * @GET
     * @INTERNAL
     * 
     * @param @required $id
     * @param @default[false] $ipassive - Include passive interactions?
     * @param @default[false] $iactive - Include active interactions?
     * 
     * @PATH(/internal/detail)
     */
    public function detail_internal($id, $ipassive, $iactive)
    {
        $subs = new Substances($id);

        if(!$subs->id)
        {
            ResponseBuilder::not_found('Compound not found.');
        }

        $result = array();

        $result['detail'] = $subs->as_array();
        
        if($ipassive)
        {
            $result['passive_interactions'] = [];
            $interaction_model = new Interactions();
            $interactions = $interaction_model->where('id_substance', $subs->id)->get_all();
            
            foreach($interactions as $i)
            {
                $result['passive_interactions'][] = $i->as_array(null, True) + array
                    (
                        'membrane' => $i->membrane->name,
                        'method' => $i->method->name,
                        "id_dataset_reference" => $i->dataset ? $i->dataset->id_publication : null
                    );
                }
            }
         
        if($iactive)
        {
            $result['active_interactions'] = [];
            $transporter_model = new Transporters();
            $transporters = $transporter_model->where('id_substance', $subs->id)->get_all();

            foreach($transporters as $t)
            {
                $result['active_interactions'][] = $t->as_array(null, true) + array
                    (
                        'target' => $t->target->name, 
                        'dataset_ref' => $t->dataset->id_reference,
                        'uniprot_id' => $t->target->uniprot_id
                    );
            }
        }

        return $result;
    }

    
    /**
     * Returns similar entries
     * 
     * @GET 
     * @internal
     * 
     * @param @required $id
     * @param @default[0] $offset
     * @param @default[null] $limit
     * 
     * @PATH(/similar)
     */
    public function get_all_similar_entries($id, $offset, $limit)
    {
        $s = new Substances($id);

        if(!$s->id)
        {
            ResponseBuilder::not_found('Invalid substance id.');
        }

        $options = $s->get_similar_entries();

        $result = [];
        $i = 0;
        $offset = intval($offset);
        $added = 0;

        foreach($options as $o)
        {
            if($i < $offset)
            {
                $i++;
                continue;
            }

            $result[] = array
            (
                'similarity' => $o->similarity,
                'substance' => $o->substance->as_array(),
                'fragment'  => $o->fragment->as_array()
            );
            $added++;

            if($limit && $added == $limit)
            {
                break;
            }
        }

        return $result;
    }
    
    /**
     * Returns all similar entries
     * 
     * @GET 
     * @internal
     * 
     * @param @required $id
     * @param @default[0] $offset
     * @param @default[null] $limit
     * 
     * @PATH(/similar/html)
     */
    public function get_all_similar_entries_html($id, $offset, $limit)
    {
        $s = new Substances($id);

        if(!$s->id)
        {
            ResponseBuilder::not_found('Invalid substance id.');
        }

        $options = $s->get_similar_entries();

        $data = $result = [];
        $i = 0;
        $offset = intval($offset);
        $added = 0;

        foreach($options as $o)
        {
            if($i < $offset)
            {
                $i++;
                continue;
            }

            $s = $o->substance;

            $total_passive = Interactions::instance()->where(array
                (
                    'visibility' => Interactions::VISIBLE,
                    'id_substance' => $s->id
                ))
                ->count_all();

            $total_active = Transporters::instance()->where(array
                (
                    'id_substance' => $s->id,
                    'td.visibility' => Transporter_datasets::VISIBLE
                ))
                ->join('JOIN transporter_datasets td ON td.id = transporters.id_dataset')
                ->select_list('transporters.id')
                ->distinct()
                ->count_all();

            $o->interactions = (object)array
            (
                'total_passive' => $total_passive,
                'total_active'  => $total_active
            );

            $data[] = $o;
            $added++;

            if($limit && $added >= $limit)
            {
                break;
            }
        }

        $total_passive = Interactions::instance()->where(array
            (
                'visibility' => Interactions::VISIBLE,
                'id_substance' => $s->id
            ))
            ->count_all();

        $total_active = Transporters::instance()->where(array
            (
                'id_substance' => $s->id,
                'td.visibility' => Transporter_datasets::VISIBLE
            ))
            ->join('JOIN transporter_datasets td ON td.id = transporters.id_dataset')
            ->select_list('transporters.id')
            ->distinct()
            ->count_all();

        $stats = (object)array
        (
            'total_passive' => $total_passive,
            'total_active'  => $total_active
        );

        foreach($data as $row)
        {
            $result[] = Render_similar_entry_item::print($row, $stats);
        }

        return $result;
    }

    /**
     * Returns "load-next" similar entries
     * 
     * @GET
     * @internal
     * 
     * @param @required $total
     * @param @required $visible
     * 
     * @PATH(/similar/next/html)
     */
    public function get_next_similar_html($total, $visible)
    {
        return Render_similar_entry_item::print_next($total, $visible);
    }


}