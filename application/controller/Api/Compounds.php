<?php

use EasyRdf\Http\Response;

/**
 * Internal API class for processing request 
 * related with detail loading of substances
 * 
 */
class ApiCompounds extends ApiController
{
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
     * @param @required $id
     * @param @default[true] $pi - Include passive interactions?
     * @param @default[true] $ai - Include active interactions?
     * 
     * @PATH(/detail)
     */
    public function detail($id, $pi, $ai)
    {
        $subs = new Substances($id);

        if(!$subs->id)
        {
            $subs = $subs->where('identifier', $id)->get_one();
        }

        if(!$subs->id)
        {
            ResponseBuilder::not_found('Compound not found.');
        }

        $result = array();

        $result['detail'] = $subs->as_array();
        
        if($pi)
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
         
        if($ai)
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