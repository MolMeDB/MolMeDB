<?php

/**
 * Molecule detail controller
 */
class MolController extends Controller 
{
    public function parse($identifier = NULL) 
    {
        $energyModel = new Energy();
        $methodModel = new Methods();
        $membraneModel = new Membranes();
        $substanceModel = new Substances();
        
        try
        {
            // Get substance detail
            $substance = $substanceModel
                ->where('identifier', $identifier)
                ->get_one();

            if(!$substance->id)
            {
                throw new Exception('Record was not found.');
            }

            // Get all membranes/methods
            $membranes = $membraneModel->get_all();
            $methods = $methodModel->get_all();
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
        $this->data['availableEnergy'] = $energyModel->get_available_by_substance($substance->id);
    }
}
