<?php

class ApiEnergy extends ApiController
{    

    /**
     * Returns energy values for given ids
     * 
     * @GET
     * @INTERNAL
     * 
     * @param @required $id - array|numeric
     * @param @default[true] $group
     * 
     * @PATH(/detail)
     */
    public function get_energy_values($id, $group)
    {
        if(is_numeric($id))
        {
            $id = [$id];
        }

        if(!is_array($id))
        {
            ResponseBuilder::bad_request('Invalid `id` list of values.');
        }

        $result = array();

		$check = [];

		foreach($id as $i)
		{
			$detail = new Energy($i);

            // Invalid record
			if(!$detail->id || !$detail->membrane || !$detail->method ||
				!$detail->substance)
			{
				continue;
			}

			$key = $detail->substance->id . "_" . $detail->membrane->id . "_" . $detail->method->id;

            if($group)
            {
                if(isset($check[$key]))
                {
                    $check[$key][] = $i;
                    continue;
                }

                $check[$key] = [$i];
            }

			$result[$i] = array
			(
				'membrane'	=> $detail->membrane->name,
				'membrane_cam'	=> $detail->membrane->CAM,
				'method'	=> $detail->method->name,
				'method_cam'	=> $detail->method->CAM,
				'substance'	=> $detail->substance->name,
				'data'	=> array
                (
                    'distance' => $detail->distance,
                    'energy'    => $detail->energy
                )
			);

            if($group)
            {
                $data = $detail->where(array
                    (
                        'id_substance'	=> $detail->id_substance,
                        'id_membrane'	=> $detail->id_membrane,
                        'id_method'		=> $detail->id_method	
                    ))
                    ->get_all();

                $result[$i]['data'] = [];

                foreach($data as $row)
                {
                    $result[$i]['data'][] = array
                    (
                        'distance'	=> $row->distance,
                        'energy'	=> $row->energy
                    );
                }
            }
		}

        if($group)
        {
            foreach($check as $id_list)
            {
                if(isset($result[$id_list[0]]))
                {
                    $result[$id_list[0]]['ids'] = $id_list;
                }
            }
        }

        return $result;
    }
}