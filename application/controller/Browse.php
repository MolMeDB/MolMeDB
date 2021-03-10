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
            // Get all membranes
            $membranes = $membraneModel->order_by('name')->get_all();
            $categories = $enum_type_model->get_categories(Enum_types::TYPE_MEMBRANE_CATS);
            $active_categories = $membraneModel->get_active_categories();

        }
        catch(Exception $e)
        {
            $this->addMessageError($e->getMessage());
            $this->redirect('error');
        }

        $this->data['membranes'] = $membranes;
        $this->data['active_categories'] = $active_categories;
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

        // Get all methods
        $methods = $methodModel->order_by('name', 'ASC')->get_all();
        $categories = $enum_type_model->get_categories(Enum_types::TYPE_METHOD_CATS);
        $active_categories = $methodModel->get_active_categories();

        $this->data['methods'] = $methods;
        $this->data['active_categories'] = $active_categories;
        $this->data['categories'] = json_encode($categories);
        $this->header['title'] = 'Methods';
        $this->view = 'browse/methods';
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
        $substanceModel = new Substances();

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
                    $this->redirect('error');
                }

                $this->data['publication'] = $publication;
                $this->data['pagination'] = $pagination;
                $this->data['list'] = $substanceModel
                    ->select_list('substances.*')
                    ->distinct()
                    ->join(' LEFT JOIN interaction i ON i.id_substance = substances.id ')
                    ->join(' LEFT JOIN datasets d ON d.id = i.id_dataset ')
                    ->join(' LEFT JOIN transporters t ON t.id_substance = substances.id ')
                    ->join(' LEFT JOIN transporter_datasets td ON td.id = t.id_dataset ')
                    ->where(array
                    (
                        'i.id_reference' => $publication->id,
                        'OR',
                        'd.id_publication' => $publication->id,
                        'OR',
                        't.id_reference' => $publication->id,
                        'OR',
                        'td.id_reference' => $publication->id,
                    ))
                    ->limit($pagination, 10)
                    ->get_all();

                $this->data['list_total'] = $substanceModel
                ->select_list('substances.*')
                ->distinct()
                ->join(' LEFT JOIN interaction i ON i.id_substance = substances.id ')
                ->join(' LEFT JOIN datasets d ON d.id = i.id_dataset ')
                ->join(' LEFT JOIN transporters t ON t.id_substance = substances.id ')
                ->join(' LEFT JOIN transporter_datasets td ON td.id = t.id_dataset ')
                ->where(array
                (
                    'i.id_reference' => $publication->id,
                    'OR',
                    'd.id_publication' => $publication->id,
                    'OR',
                    't.id_reference' => $publication->id,
                    'OR',
                    'td.id_reference' => $publication->id,
                ))
                ->count_all();
            }

            $this->data['publications'] = $publicationModel->get_nonempty_refs();
            $this->data['publications'] = self::transform_publications($this->data['publications']);
        }
        catch(Exception $e)
        {
            $this->addMessageError($e->getMessage());
            $this->redirect('error');
        }

        $this->header['title'] = 'Datasets';
        $this->view = 'browse/sets';
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


