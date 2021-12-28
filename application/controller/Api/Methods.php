<?php

/**
 * 
 */
class ApiMethods extends ApiController
{   
    
    /**
     * Returns methods with count of available interactions for given substance id
     * 
     * @GET
     * @param @required $id_compound
     * @Path(/availability/substance)
     */
    public function get_avail_by_substance($id_compound)
    {
        $s = new Substances($id_compound);

        if(!$s->id)
        {
            ResponseBuilder::not_found('Invalid compound id.');
        }

        return Methods::instance()->check_avail_by_substance($s->id);
    }


     /**
     * Returns method detail
     * 
     * @GET
     * @param $id
     * @param $cam
     * @param $tag
     * @PATH(/detail)
     */
    public function get_detail($id, $cam, $tag)
    {
        $method = new Methods($id);

        if(!$method->id)
        {
            $method = $method->where('CAM', $cam ? $cam : "NONSENSE!@#")->get_one();
            if(!$method->id)
            {
                $method = $method->where('idTag', $tag ? $tag : "NONSENSE!@#")->get_one();
            }

            if(!$method->id)
            {
                ResponseBuilder::not_found('Method not found.');
            }
        }

        $result = array
        (
            'id'    => $method->id,
            'name'  => $method->name,
            'keywords' => $method->keywords,
            'description'  => $method->description,
            'reference' => $method->references,
            'CAM'   => $method->CAM,
            'created'   => $method->createDateTime
        );

        return $result;
    }

    /**
     * Returns all methods for given 
     * 
     * @GET
     * @param $id_compound 
     * @param $id_membrane 
     * @PATH(/all)
     */
    public function getAll($id_compound, $id_membrane)
    {
        if(!is_array($id_compound) && $id_compound)
        {
            $id_compound = [$id_compound];
        }

        if(!is_array($id_membrane) && $id_membrane)
        {
            $id_membrane = [$id_membrane];
        }

        $result = array();

        if(!is_array($id_compound) && !is_array($id_membrane))
        {
            $method_model = new Methods();

            $data = $method_model->get_all();

            foreach ($data as $row) 
            {
                $result[] = array
                (
                    'id'    => $row->id,
                    'name'  => $row->name,
                    'cam'   => $row->CAM
                );
            }
        }
        else
        {
            $interaction_model = new Interactions();

            $data = $interaction_model
                ->where('visibility', Interactions::VISIBLE);

            if(is_array($id_compound))
            {
                $data->in('id_substance', $id_compound);
            }
            if(is_array($id_membrane))
            {
                $data->in('id_membrane', $id_membrane);
            }
                
            $data = $data->select_list('id_method')
                ->distinct()
                ->get_all();

            $result = array();

            foreach($data as $row)
            {
                if(!$row->method)
                {
                    continue;
                }

                $result[] = array
                (
                    'id'    => $row->method->id,
                    'name'  => $row->method->name,
                    'cam'   => $row->method->CAM
                );
            }
        }

        return $result;
    }
    
}