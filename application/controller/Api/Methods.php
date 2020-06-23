<?php

/**
 * 
 */
class ApiMethods extends ApiController
{   
    /**
     * Constructor
     */
    function __construct()
    {
        
    }


     /**
     * Returns method detail
     * 
     * @GET
     * @param id
     */
    public function get($id = NULL)
    {
        $method = new Methods($id);

        if(!$method->id)
        {
            $this->answer(array(), self::CODE_OK_NO_CONTENT);
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

        $this->answer($result);
    }


    /**
     * Finds membrane details
     * 
     * @GET
     * @param q
     */
    public function find($q = NULL)
    {
        $methodModel = new Methods();
        $methods = $methodModel->search($q, NULL, TRUE);

        if (!count($methods)) {
            $this->answer(array(), self::CODE_NOT_FOUND);
        }

        $result = [];

        foreach ($methods as $mem) {
            $result[] = array(
                'id'    => $mem->id,
                'name'  => $mem->name,
                'keywords' => $mem->keywords,
                'description'   => str_replace(PHP_EOL, '', strip_tags($mem->description)),
                'references' => str_replace(PHP_EOL, '', strip_tags($mem->references)),
            );
        }

        $this->answer($result);
    }

    /**
     * Returns all methods for given 
     * 
     * @POST
     * @param substance_ids Array
     * @param membrane_ids  Array
     */
    public function getAll($substance_ids = NULL, $membrane_ids = NULL)
    {
        if (!$substance_ids) 
        {
            $substance_ids = [];
        }

        if (!$membrane_ids) {
            $membrane_ids = [];
        }

        $substance_ids = $this->remove_empty_values($substance_ids);
        $membrane_ids = $this->remove_empty_values($membrane_ids);

        $result = array();

        if(empty($substance_ids) && empty($membrane_ids))
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
                ->where('visibility', Interactions::VISIBLE)
                ->in('id_substance', $substance_ids)
                ->in('id_membrane', $membrane_ids)
                ->select_list('id_method')
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

        $this->answer($result);
    }
    
}