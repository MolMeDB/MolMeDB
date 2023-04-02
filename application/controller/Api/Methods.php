<?php

/**
 * 
 */
class ApiMethods extends ApiController
{   
    ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ################################################################################
    ############################### PUBLIC #########################################
    ################################################################################
    ################################################################################

    ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ########## methods/all<?categorized> #########################################
    ################################################################################

    /**
     * Returns all methods (PUBLIC)
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @optional $categorized
     * 
     * @Path(/all)
     */
    public function P_get_all($categorized)
    {
        $result = [];
        if(!$categorized)
        {
            $all = Methods::instance()->get_all();

            foreach($all as $mem)
            {
                $result[] = $mem->get_public_detail();
            }
        }
        else
        {
            $d = Methods::instance()->get_active_categories();

            foreach($d as $id => $cats)
            {
                $m = new Methods($id);
                $result[] = array
                (
                    'membrane' => $m->get_public_detail(),
                    'category' => $cats->category,
                    'subcategory' => $cats->subcategory,
                );
            }
        }

        return $result;
    }

    ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ########## methods/categories ################################################
    ################################################################################

    /**
     * Returns all membrane categories (PUBLIC)
     * 
     * @GET
     * @PUBLIC
     * 
     * @Path(/categories)
     */
    public function P_get_categories()
    {
        $et_model = new Enum_types();
        $categories = $et_model->get_categories(Enum_types::TYPE_METHOD_CATS);
        $result = $this->clarify_cat_structure($categories);
        return $result;
    }

    /**
     * Clarify category structure
     * 
     * @param array $cats
     * 
     * @return array
     */
    private function clarify_cat_structure($cats)
    {
        $result = $cats;

        foreach($cats as $key => $row)
        {
            unset($result[$key]['id_element']);
            unset($result[$key]['id_enum_type']);
            unset($result[$key]['fixed']);
            unset($result[$key]['value']);
            unset($result[$key]['last']);
            if(isset($result[$key]['children']))
            {
                $result[$key]['children'] = $this->clarify_cat_structure($result[$key]['children']);
                $result[$key]['subcategories'] = $result[$key]['children'];
                unset($result[$key]['children']);
            }
        }

        return $result;
    }

     ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ########## methods/detail ################################################
    ################################################################################

    /**
     * Returns detail of method
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @optional $identifier
     * @param @optional $category
     * 
     * @Path(/detail)
     */
    public function P_detail($identifier, $category)
    {
        if(!$identifier && !$category)
        {
            ResponseBuilder::bad_request('At least one parameter must be set.');
        }

        $result = [];
        $membranes = Methods::instance()->find_all($identifier ? $identifier : $category);

        foreach($membranes as $mem)
        {
            $result[] = $mem->get_public_detail(true);
        }

        return $result;
    }

     ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ################################################################################
    ############################### PRIVATE ########################################
    ################################################################################
    ################################################################################

    ////////////////////////////////////////////////////////////////////////////////

    /**
     * Returns method detail by given param
     * Priority: $id > $cam > $tag
     * 
     * @GET
     * 
     * @param $id
     * @param $cam
     * @param $tag
     * 
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
            'description'   => $method->description,
            'reference' => $method->references,
            'CAM'   => $method->CAM,
            'created'   => $method->createDateTime
        );

        return $result;
    }

    /**
     * Whispers about methods
     * 
     * @GET
     * @INTERNAL
     * 
     * @param $id_method
     * @param $id_group
     * 
     * @PATH(/whisper)
     */
    public function whisper($id_method, $id_group)
    {
        if($id_method)
        {
            $method = new Methods($id_method);

            if(!$method->id)
            {
                ResponseBuilder::not_found();
            }

            // Prepare description
            $d = preg_replace('/(<br\/>|<br>|<\/br>|\\n)/iU', '', $method->description);
            $d = preg_replace('/(<em>|<\/em>)/iU', '', $d);
            $d = preg_replace('/(<h\d{1}.*>.*<\/h\d{1}.*>)/iU', '', $d);
            $d = preg_replace('/<ul>.*<\/ul>/iU', '', $d);
            $d = preg_replace("/<([a-z][a-z0-9]*)[^>]*?(\/?)>/si",'<$1$2>', $d);
            $d = trim($d);

            if($d == "")
            {
                ResponseBuilder::ok_no_content();
            }

            $method->description = $d;

            $this->view = new View('api/method/detail');
            $this->view->method = $method;
        }
        else if($id_group)
        {
            $enum_type = new Enum_types($id_group);
            $etl = new Enum_type_links();

            if(!$enum_type->id || $enum_type->type !== Enum_types::TYPE_METHOD_CATS)
            {
                ResponseBuilder::not_found();
            }

            $parent_link = $etl->get_by_enum_type_id($enum_type->id);
            $children = $parent_link->get_all_children();

            foreach($children as $id => $ns)
            {
                $link = $etl->get_by_enum_type_id($id);
                $children[$id] = $link->get_all_children();
            }

            if(empty($children)) // Should have only method as children
            {
                $children = Methods::instance()->get_linked_data($parent_link->id);
            }
            else // Has subcategories as children
            {

            }
            $this->view = new View('api/method/category');
            $this->view->title = $enum_type->name;
            $this->view->children = $children;
        }
        else
        {
            ResponseBuilder::not_found();
        }

        $this->responses = array
        (
            HeaderParser::HTML => $this->view->render(false)
        );
    }


    /**
     * Returns all methods for given 
     * 
     * @GET
     * @param $id_compound 
     * @param $id_membrane 
     * 
     * @PATH(/p/all)
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