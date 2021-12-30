<?php

/**
 * Internal API class for processing request 
 * related with detail loading of substances
 * 
 */
class ApiCompounds extends ApiController
{
    /**
     * Returns substances with interactions for given membrane/method/publication
     * 
     * @GET
     * @internal
     * @param @required $id
     * @param @required $type
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
                        "id_dataset_reference" => $i->dataset->id_publication
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
    
    // /**
    //  * Returns availability of energy profile 
    //  * for given dataset
    //  * 
    //  * DEPRECATED
    //  * 
    //  * @POST
    // //  * @param list - LIST OF membrane;method combinations
    // //  * @param id - ID of substance
    // //  */
    // public function getEnergyData($list, $id)
    // {
    //     // Parse list
    //     $list = json_decode($list);

    //     if(!$id)
    //     {
    //         //$this->answer(NULL, self::CODE_BAD_REQUEST);
    //     }

    //     $energy_model = new Energy();
        
    //     $result = [];

    //     foreach($list as $row)
    //     {
    //         if(!$row->idMembrane || !$row->idMethod)
    //         {
    //             $result = 0;
    //             continue;
    //         }

    //         $idMembrane = $row->idMembrane;
    //         $idMethod = $row->idMethod;
            
    //         $data = $energy_model->getEnergyValues($id, $idMembrane, $idMethod, True);
            
    //         $result[] = $data->id ? 1 : 0;
    //     }
        
    //     //$this->answer($result);
    // }
    
}