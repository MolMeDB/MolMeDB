<?php

/**
 * Internal API class for processing request 
 * related with detail loading of substances
 * 
 */
class ApiDetail extends ApiController
{
    /**
     * Constructor
     */
    function __construct()
    {
        // Is user logged in ?
        $this->user = isset($_SESSION['user']) ? $_SESSION['user'] : NULL;
    }

    /**
     * Returns methods with available interactions for given substance ID
     * 
     * @GET
     * @param id Compound ID
     */
    public function checkMethods($ID)
    {
        $method_model = new Methods();

        $result = $method_model->check_avail_by_substance($ID)->as_array();

        $this->answer($result);
    }
    
    /**
     * Returns number of available interactions for given 
     * substance - method - membrane combination
     * 
     * @GET
     * @param id - Substance ID
     * @param idMem - Membrane ID
     * @param idMet - Method ID
     * 
     */
    public function availabilityInteraction($idSubstance, $idMembrane, $idMethod)
    {
        $interaction_model = new Interactions();

        $count = $interaction_model->where(array
            (
                'id_substance'  => $idSubstance,
                'id_membrane'   => $idMembrane,
                'id_method'     => $idMethod,
                'visibility'    => Interactions::VISIBLE
            ))
            ->select_list('COUNT(*) as count')
            ->get_one()
            ->count;

        $result = array('count' => $count);
        
        $this->answer($result);
    }

    /**
     * Checks input validity
     * 
     * @param array|string $input
     */
    private function is_wrong_input($input)
    {
        if (empty($input)) 
        {
            return True;
        }

        if(is_array($input))
        {
            if(count($input) < 2 && isset($input[0]))
            {
                if(is_array($input[0]))
                {
                    return False;
                }

                if(is_string($input[0]))
                {
                    if(!intval($input[0]) || !$input[0])
                    {
                        return True;
                    }
                }
            }
        }

        if(!$input || !intval($input))
        {
            return True;
        }

        return False;
    }

	/**
	 * Returns all interactions for given
     * substance - method - membrane combinations
     * 
     * @POST
	 * @param id - Substance ID
     * @param idMethods array
     * @param idMembranes array
	 */
    public function getInteractions($idSubstance, $idMethods, $idMembranes)
    {
        $interaction_model = new Interactions();
        $membrane_model = new Membranes();
        $method_model = new Methods();
        $membranes = $this->is_wrong_input($idMembranes) ? $membrane_model->select_list('id')->get_all() : $idMembranes;
        $methods = $this->is_wrong_input($idMethods) ? $method_model->select_list('id')->get_all() : $idMethods;
        $result = $mems = $meths = array();

        $membranes = is_array($membranes) || is_object($membranes) ? $membranes : array($membranes);
        $methods = is_array($methods) || is_object($methods) ? $methods : array($methods);

        foreach($membranes as $key => $m)
        {
            $mems[$key] = is_object($m) ? $m->id : $m;
        }

        foreach($methods as $key => $m)
        {
            $meths[$key] = is_object($m) ? $m->id : $m;
        }

        foreach($mems as $mem_id)
        {
            $interactions = $interaction_model->where(array
                (
                    'id_substance'  => $idSubstance,
                    'id_method IN ' => '(' . implode(',', $meths) . ') ',
                    'id_membrane'   => $mem_id,
                    'visibility'    => Interactions::VISIBLE
                ))
                ->get_all();

            $data = array();
            $membrane_name = '';

            foreach($interactions as $i)
            {
                $membrane_name = $i->membrane->name;
                // Prepare data
                $i->substance = $i->substance ? $i->substance->as_array() : NULL;
                $i->membrane_name = $i->membrane->name;
                $i->membrane_idTag = $i->membrane->idTag;
                $i->membrane_CAM = $i->membrane->CAM;
                $i->method_name = $i->method->name;
                $i->method_idTag = $i->method->idTag;
                $i->method_CAM = $i->membrane->CAM;
                $i->reference = $i->reference ? $i->reference->reference : NULL;

                $data[] = $i->as_array(NULL, True);
            }

            $result[] = array
            (
                'membrane_id'   => $mem_id,
                'membrane_name' => $membrane_name,
                'data'  => $data
            );
        }

        return $this->answer($result);
    }
    

    /**
     * Returns energy values for given combination of
     * substance - membrane - method
     * 
     * @GET
     * @param id - Substance ID
     * @param idMem - Membrane ID
     * @param idMet - Method ID
     * @param limit
     */
    public function getEnergyValues($substance_id, $membrane_id, $method_id, $limit)
    {
        $energy_model = new Energy();

        if(!$limit)
        {
            $limit = NULL;
        }

        $data = $energy_model->where(array
            (
                'id_substance'  => $substance_id,
                'id_membrane'   => $membrane_id,
                'id_method'     => $method_id
            ))
            ->limit($limit)
            ->select_list(array
                (
                    'distance',
                    'energy'
                ))
            ->get_all();

        $this->answer($data->as_array());
    }
    
    /**
     * Returns chart label
     * 
     * @GET
     * @param idMem
     * @param idMet
     */
    public function getEnergyLabel($idMembrane, $idMethod)
    {
        $membrane = new Membranes($idMembrane);
        $method = new Methods($idMethod);

        if(!$membrane->id || !$method->id)
        {
            $this->answer(NULL, self::CODE_NOT_FOUND);
        }

        $result = array
        (
            'membrane'  => $membrane->CAM,
            'method'    => $method->CAM
        );

        $this->answer($result);
    }
    
    /**
     * Returns availability of energy profile 
     * for given dataset
     * 
     * @POST
     * @param list - LIST OF membrane;method combinations
     * @param id - ID of substance
     */
    public function getEnergyData($list, $id)
    {
        // Parse list
        $list = json_decode($list);

        if(!$id)
        {
            $this->answer(NULL, self::CODE_BAD_REQUEST);
        }

        $energy_model = new Energy();
        
        $result = [];

        foreach($list as $row)
        {
            if(!$row->idMembrane || !$row->idMethod)
            {
                $result = 0;
                continue;
            }

            $idMembrane = $row->idMembrane;
            $idMethod = $row->idMethod;
            
            $data = $energy_model->getEnergyValues($id, $idMembrane, $idMethod, True);
            
            $result[] = $data->id ? 1 : 0;
        }
        
        $this->answer($result);
    }
    
}