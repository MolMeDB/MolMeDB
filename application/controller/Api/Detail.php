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
     * Returns molecule data
     * 
     * @GET
     * @param identifier
     */
    public function mol($identifier)
    {
        $subs = new Substances($identifier);

        if(!$subs->id)
        {
            $subs = $subs->where('identifier', $identifier)->get_one();
        }

        if(!$subs->id)
        {
            $this->answer(NULL, self::CODE_NOT_FOUND);
        }

        $result = array();

        $result['detail'] = $subs->as_array();
        $result['interactions'] = [];
        $result['transporters'] = [];

        $interaction_model = new Interactions();
        $interactions = $interaction_model->where('id_substance', $subs->id)->get_all();

        foreach($interactions as $i)
        {
            $result['interactions'][] = $i->as_array(null, True) + array
                (
                    'membrane' => $i->membrane->name,
                    'method' => $i->method->name,
                    "id_dataset_reference" => $i->dataset->id_publication
                );
        }

        $transporter_model = new Transporters();
        $transporters = $transporter_model->where('id_substance', $subs->id)->get_all();

        foreach($transporters as $t)
        {
            $result['transporters'][] = $t->as_array(null, true) + array
                (
                    'target' => $t->target->name, 
                    'dataset_ref' => $t->dataset->id_reference,
                    'uniprot_id' => $t->target->uniprot_id
                );
        }

        $this->answer($result);
    }

    /**
     * Returns methods with available interactions for given substance ID
     * 
     * @deprecated
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
                    'id_membrane'   => $mem_id,
                    'visibility'    => Interactions::VISIBLE
                ))
                ->in('id_method', $meths)
                ->get_all();

            if(!count($interactions))
            {
                continue;
            }

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
                $i->primary_reference = $i->reference ? $i->reference->citation : NULL;
                $i->secondary_reference = $i->dataset && $i->dataset->publication ? $i->dataset->publication->citation : NULL;
                $i->secondary_reference_id = $i->dataset && $i->dataset->publication ? $i->dataset->publication->id : NULL;

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
     * Shows molecular fragments of molecule
     * 
     * @param id - Substance ID
     * @GET
     */
    public function getFragments($id)
    {
        $s = new Substances($id);

        if(!$s->id)
        {
            $this->answer(NULL, SELF::CODE_NOT_FOUND);
        }

        $fragments = $s->get_all_fragments();

        $view = new View('fragments/grid');
        $view->fragments = $fragments;

        $this->answer($view->__toString(), self::CODE_OK, 'html');
    }
    
	/**
	 * Returns all interactions for given
     * substance - method - membrane combinations
     * 
     * @GET
	 * @param id - Substance ID
     * @param idMethods array
     * @param idMembranes array
	 */
    public function getInteractionsTable($idSubstance, $idMethods, $idMembranes)
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

        $t = new Table([
            Table::S_PAGINATIOR_POSITION => 'left',
            Table::FILTER_HIDE_ROWS => true,
            Table::FILTER_HIDE_ROWS_DEFAULT => true,
        ]);

        $t->column('membrane.name')
            ->sortable()
            ->numeric()
            ->link('browse/membranes?target=', 'membrane.id')
            ->css(array
            (
                'text-align' => 'center' // Centering - flex - justify-content: center;
            ))
            ->title('Membrane');

        $t->column('method.name')
            ->link('browse/methods?target=', 'method.id')
            ->numeric()
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

        foreach($mems as $mem_id)
        {
            $interactions = $interaction_model->where(array
                (
                    'id_substance'  => $idSubstance,
                    'id_membrane'   => $mem_id,
                    'visibility'    => Interactions::VISIBLE
                ))
                ->in('id_method', $meths)
                ->get_all();

            if(!count($interactions))
            {
                continue;
            }

            foreach($interactions as $i)
            {
                // Prepare data
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

                $result[] = $i;;
            }
        }

        $t->datasource($result);

        $this->answer($t->html(), self::CODE_OK, 'html');
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