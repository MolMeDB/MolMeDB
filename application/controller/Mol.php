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

    public function parse($identifier = NULL) 
    {
        $energyModel = new Energy();
        $methodModel = new Methods();
        $membraneModel = new Membranes();
        $substanceModel = new Substances();
        $transporterModel = new Transporters();
        $transporter_dataset_model = new Transporter_datasets();
        $link_model = new Substance_links();

         // Get substance detail
        // $substance = $substanceModel
        //     ->where('identifier', $identifier)
        //     ->get_one();

        // $pubchem = new Drugbank();

        // print_r($pubchem->get_3d_structure($substance));
        // die;
        
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

        $this->view = 'detail';
        $this->header['title'] = $substance->name;

        // Substance detail
        $this->data['substance'] = $substance;
        
        // Membranes and methods
        $this->data['membranes'] = $membranes;
        $this->data['methods'] = $methods;

        // Transporters
        $this->data['transporters'] = $transporters;
        
        // Energy data
        $this->data['availableEnergy'] = $energyModel->get_available_by_substance($substance->id);
    }
}
