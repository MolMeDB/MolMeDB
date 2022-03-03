<?php

/**
 * Molecule detail controller
 */
class MolController extends Controller 
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Shows substance detail
     * 
     * @param string $identifier
     * 
     * @author Jakub Juracka
     */
    public function index($identifier = NULL) 
    {
        $energyModel = new Energy();
        $methodModel = new Methods();
        $membraneModel = new Membranes();
        $substanceModel = new Substances();
        $transporterModel = new Transporters();
        $transporter_dataset_model = new Transporter_datasets();
        $link_model = new Substance_links();

        try
        {
            // Get substance detail
            $substance = $substanceModel
                ->where('identifier', $identifier)
                ->get_one();

            $by_link = false;

            if(!$substance->id)
            {
                $substance = $link_model->get_substance_by_identifier($identifier);
                $by_link = True;
            }

            if(!$substance->id)
            {
                throw new Exception('Record was not found.');
            }

            // Get all membranes/methods
            $membranes = $membraneModel->get_all();
            $methods = $methodModel->get_all();
            $methods_cat = $methodModel->get_structured_for_substance($substance->id, true);

            // Get all transporters
            $visible_datasets = $transporter_dataset_model
                ->where('visibility', Datasets::VISIBLE)
                ->select_list('id')
                ->get_all();

            $dataset_ids = array();

            foreach($visible_datasets as $d)
            {
                $dataset_ids[] = $d->id;
            }

            $transporters = $transporterModel->where(array
                (
                    'id_substance' => $substance->id,
                ))
                ->in('id_dataset', $dataset_ids)
                ->get_all();

            $identifiers = $substance->get_active_identifiers();

            $similar_entries = $substance->get_similar_entries();

            // Show max 10 records
            $total_similar_entries = count($similar_entries);
            $similar_entries = array_slice($similar_entries, 0,3,TRUE);

            // Add stats info
            foreach($similar_entries as $key => $row)
            {
                $s = $row->substance;

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

                $row->interactions = (object)array
                (
                    'total_passive' => $total_passive,
                    'total_active'  => $total_active
                );
            }

            // Stat for current molecule
            $total_passive = Interactions::instance()->where(array
                (
                    'visibility' => Interactions::VISIBLE,
                    'id_substance' => $substance->id
                ))
                ->count_all();

            $total_active = Transporters::instance()->where(array
                (
                    'id_substance' => $substance->id,
                    'td.visibility' => Transporter_datasets::VISIBLE
                ))
                ->join('JOIN transporter_datasets td ON td.id = transporters.id_dataset')
                ->select_list('transporters.id')
                ->distinct()
                ->count_all();

            if($by_link)
            {
                $this->addMessageWarning('Your request has been redirected from record ' . $identifier);
            }
        } 
        catch (Exception $ex) 
        {
            $this->addMessageError($ex->getMessage());
            $this->redirect('error');
        }

        $this->title = $substance->name;

        $this->view = new View('detail');
        // Substance detail
        $this->view->substance = $substance;
        $this->view->identifiers = $identifiers;

        // 3D structure
        $this->view->structures_3d = $substance->get_all_3D_structures();

        // Similar entries
        $this->view->similar_entries = $similar_entries;
        $this->view->similar_entries_load_all = count($similar_entries) !== $total_similar_entries;

        //
        $this->view->stats_interactions = (object)array
        (
            'total_passive' => $total_passive,
            'total_active'  => $total_active
        );
        
        // Membranes and methods
        $this->view->membranes = $membranes;
        $this->view->methods = $methods;
        $this->view->methods_cat = $methods_cat;

        // Transporters
        $this->view->transporters = $transporters;
        
        // Energy data
        $this->view->availableEnergy = $energyModel->get_available_by_substance($substance->id);
    }
}
