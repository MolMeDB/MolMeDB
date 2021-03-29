<?php

/**
 * Browse controller
 */
class BrowseController extends Controller 
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }


    /**
     * Membranes browser
     * 
     * @author Jakub Juračka
     */
    public function membranes()
    {
        $membraneModel = new Membranes();
        $enum_type_model = new Enum_types();

        try
        {
            // Get data
            $categories = $enum_type_model->get_categories(Enum_types::TYPE_MEMBRANE_CATS);
            $active_categories = $membraneModel->get_active_categories();
            $membranes = $membraneModel->order_by('name')->get_all();
        }
        catch(Exception $e)
        {
            $this->addMessageError($e->getMessage());
            $this->redirect('error');
        }

        $this->data['membranes'] = $membranes;
        $this->data['active_categories'] = $active_categories;
        $this->data['side_list'] = self::get_side_list($categories);
        $this->data['categories'] = json_encode($categories);
        $this->header['title'] = 'Membranes';
        $this->view = 'browse/membranes';
    }

    /**
     * Methods browser
     * 
     * @author Jakub Juračka
     */
    public function methods()
    {
        $methodModel = new Methods();
        $enum_type_model = new Enum_types();

        try
        {
            // Get all methods
            $methods = $methodModel->order_by('name', 'ASC')->get_all();
            $categories = $enum_type_model->get_categories(Enum_types::TYPE_METHOD_CATS);
            $active_categories = $methodModel->get_active_categories();
        }
        catch(Exception $e)
        {
            $this->addMessageError($e->getMessage());
            $this->redirect('error');
        }

        $this->data['methods'] = $methods;
        $this->data['active_categories'] = $active_categories;
        $this->data['side_list'] = self::get_side_list($categories);
        $this->data['categories'] = json_encode($categories);
        $this->header['title'] = 'Methods';
        $this->view = 'browse/methods';
    }


    ///// ***** TRANSPORTERS ***** /////
    /**
     * Transporter browser
     * 
     * @param int $element_id
     * @param bool $element_type
     * @param int $pagination
     * 
     * @author Jakub Juracka
     */
    public function transporters($element_id = NULL, $is_last = FALSE, $pagination = 1)
    {
        $stats = new Statistics();

        if(!$pagination || $pagination < 1)
        {
            $pagination = 1;
        }

        if($element_id)
        {
            try
            {
                if($is_last)
                {
                    $target = new Transporter_targets($element_id);

                    if(!$target->id)
                    {
                        throw new Exception('Transporter target not found.');
                    }

                    $substances = $target->get_substances($pagination);
                    $total = $target->count_substances();
                }
                else
                {
                    $enum_type_link = new Enum_type_links($element_id);
                    $enum_type = $enum_type_link->enum_type;
                    $transporter_target_model = new Transporter_targets();

                    if($enum_type->type != Enum_types::TYPE_TRANSPORTER_CATS || !$enum_type->id)
                    {
                        throw new Exception('Invalid transporter group.');
                    }

                    $data = $enum_type->get_link_elements($enum_type_link->id, ($pagination-1)*10, 10);

                    $substances = $data->data;
                    $total = $data->total;
                }

                $this->data['element'] = $is_last ? $target : $enum_type_link;
                $this->data['is_last'] = $is_last;
                $this->data['pagination'] = $pagination;
                $this->data['list'] = $substances;
                $this->data['total'] = $total;
            }
            catch(Exception $e)
            {
                $this->alert->error($e->getMessage());
            }
        }

        $this->data['chart_data'] = $stats->get(Statistics::TYPE_INTER_ACTIVE);
        $this->header['title'] = 'Transporters';
        $this->view = 'browse/transporters';
    }


    /**
     * Methods browser
     * 
     * @param integer $reference_id
     * @param integer $pagination
     * 
     * @author Jakub Juračka
     */
    public function sets($reference_id = NULL, $pagination = 1)
    {
        $publicationModel = new Publications();

        if(!$pagination)
        {
            $pagination = 1;
        }

        try
        {
            if ($reference_id) 
            {
                $publication = new Publications($reference_id);

                if(!$publication->id)
                {
                    throw new Exception('Record doesn\'t exist.');
                    $this->redirect('browse/sets');
                }

                $this->data['publication'] = $publication;
                $this->data['pagination'] = $pagination;

                $this->data['list'] = $publication->get_assigned_substances($pagination);
                $this->data['list_total'] = $publication->count_assigned_substances();
            }

            $this->data['publications'] = $publicationModel->get_nonempty_refs();
            $this->data['publications'] = self::transform_publications($this->data['publications']);
        }
        catch(Exception $e)
        {
            $this->addMessageError($e->getMessage());
            $this->redirect('browse/sets');
        }

        $this->header['title'] = 'Datasets';
        $this->view = 'browse/sets';
    }


    /**
     * Returns browe side list
     * 
     * @param array $data
     * 
     * @return string
     */
    private static function get_side_list($data)
    {
        function side_list_item($row)
        {
            $res = '';

            if(isset($row['children']) && is_array($row['children']))
            {
                $res .= '<div class="list-section"><label>' . $row['name'] . "</label>";
                $t = '';
                foreach($row['children'] as $r)
                {
                    $t .= side_list_item($r);
                }
                if(trim($t) == '')
                {
                    return '';
                }
                $res .= $t. '</div>';
            }
            else
            {
                $res .= '<div class="browse-list-item">' . 
                    '<img src="files/icons/chevron-right.svg" style="width: 6px;">'
                    . '<a class="list-item" id="target_' . $row['id_element'] . '" href="#tab' . $row['id_element'] . '">' . $row['name'] . '</a>'
                    . '</div>';
            }

            return $res;
        }

        $res = '';

        foreach($data as $row)
        {
            $res .= side_list_item($row);
        }

        return $res;
    }


    /**
     * Publication list
     * 
     * @param Iterable_object $list
     * 
     * @return Iterable_object
     */
    private static function transform_publications($list)
    {
        $result = [];

        foreach($list as $row)
        {
            $row = new Publications($row->id);

            $result[] = array
            (
                'id' => $row->id,
                'citation' => $row->citation,
                'citation_short' => self::get_substring($row->citation, 120),
                'doi' => $row->doi,
                'doi_short' => self::get_substring($row->doi, 15),
                'pmid' => $row->pmid,
                'title' => $row->title,
                'title_short' => self::get_substring($row->title, 120),
                'year' => $row->year,
                'journal' => $row->journal,
                'authors' => $row->authors,
                'authors_short' => self::get_substring($row->authors, 60),
                'total_passive_interactions' => $row->total_passive_interactions,
                'total_active_interactions' => $row->total_active_interactions,
                'total_substances'          => $row->total_substances
            );
        }

        return new Iterable_object($result, TRUE);
    }

    /**
     * 
     */
    private static function get_substring($string, $limit)
    {
        if(strlen($string) <= $limit)
        {
            return $string;
        }

        $end = strrpos(substr($string,0,$limit), " ");

        if(!$end)
        {
            $end = $limit;
        }

        return substr($string, 0, $end) . '...';
    }
}


