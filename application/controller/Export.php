<?php
/**
 * Export Controller
 * 
 * @author Jakub Juracka
 */
class ExportController extends Controller
{
    /**
     * Constructor
     * 
     */
    function __construct()
    {
        parent::__construct();
    }

    /**
     * Exports data from given publication
     * 
     * @param int $publication_id
     */
    public function publication($publication_id)
    {
        $publication = new Publications($publication_id);

        if(!$publication->id)
        {
            $this->alert->error(PARAMETER);
            $this->redirect('browse/sets');
        }

        $file_model = new File();

        $passive = $publication->get_passive_transport_data()->as_array();
        $active = $publication->get_active_transport_data()->as_array();

        if(!count($passive) && !count($active))
        {
            $this->alert->error('No data found.');
            $this->redirect('browse/sets');
        }

        try
        {
            // Set filename
            $filename = '';
            if($publication->pmid)
            {
                $filename = $publication->pmid;
            }
            else if($publication->doi)
            {
                $filename = $publication->doi;
            }
            else
            {
                $filename = 'dataset';
            }

            $filename = str_replace('.', '_', $filename);
            $filename = str_replace('/', '_', $filename);

            $folder = File::FOLDER_PUBLICATION_DOWNLOAD . $publication->id . '/';

            $passive_transport_file = $file_model->transform_to_CSV($passive, 'passive_' . $filename . '.csv', $folder);
            $active_transport_file = $file_model->transform_to_CSV($active, "active_" . $filename . '.csv', $folder);

            $paths = array
            (
                $passive_transport_file,
                $active_transport_file
            );

            $target = $file_model->zip($paths, $folder . $filename . '.zip', TRUE);
        }
        catch(Exception $e)
        {
            $this->alert->error('Error occured while getting server data.');
            $this->redirect('browse/sets');
        }
        

        header('Content-Tpe: application/zip');
        // tell the browser we want to save it instead of displaying it
        header('Content-Disposition: attachment; filename="'.$filename.'.zip";');
        header("Content-length: " . filesize($target));
        header("Pragma: no-cache"); 
        header("Expires: 0"); 
        readfile("$target");

        die;
    }

    /**
     * Exports data for given membrane
     * 
     * @param int $id_membrane
     * 
     * @author Jakub Juracka
     */
    public function membrane($id_membrane)
    {
        $membrane = new Membranes($id_membrane);
        $file_model = new File();

        if(!$membrane->id)
        {
            $this->alert->error('Invalid membrane id.');
            $this->redirect('browse/membranes');
        }

        try
        {
            $interactions = $membrane->get_interactions()->as_array();

            $file_name =  $membrane->name . '_interactions.csv';

            $folder = File::FOLDER_MEMBRANE_DOWNLOAD . $membrane->id . '/';
            $file_path = $file_model->transform_to_CSV($interactions, $file_name, $folder);

            if(!$file_path)
            {
                $this->alert->warning('No data.');
                $this->redirect('browse/membranes');
            }

            header('Content-Tpe: application/csv');
            // tell the browser we want to save it instead of displaying it
            header('Content-Disposition: attachment; filename="' . $file_name . '";');
            header("Content-length: " . filesize($file_path));
            header("Pragma: no-cache"); 
            header("Expires: 0"); 
            readfile("$file_path");
        }
        catch(Exception $e)
        {
            $this->alert->error('Cannot export membrane data.');
            $this->redirect('browse/membranes');
        }

        die();
    }

    /**
     * Exports data for given method
     * 
     * @param int $id_method
     * 
     * @author Jakub Juracka
     */
    public function method($id_method)
    {
        $method = new Methods($id_method);
        $file_model = new File();

        if(!$method->id)
        {
            $this->alert->error('Invalid method id.');
            $this->redirect('browse/methods');
        }

        try
        {
            $interactions = $method->get_interactions()->as_array();

            $folder = File::FOLDER_METHOD_DOWNLOAD . $method->id . '/';
            $file_name =  $method->name . '_interactions.csv';

            $file_path = $file_model->transform_to_CSV($interactions, $file_name, $folder);

            if(!$file_path)
            {
                $this->alert->warning('No data.');
                $this->redirect('browse/methods');
            }

            header('Content-Tpe: application/csv');
            // tell the browser we want to save it instead of displaying it
            header('Content-Disposition: attachment; filename="' . $file_name .'.csv";');
            header("Content-length: " . filesize($file_path));
            header("Pragma: no-cache"); 
            header("Expires: 0"); 
            readfile("$file_path");
        }
        catch(Exception $e)
        {
            $this->alert->error('Cannot export method data.');
            $this->redirect('browse/methods');
        }

        die();
    }

    /**
     * Exports data for given transporter group
     * 
     * @param int $id_method
     * 
     * @author Jakub Juracka
     */
    /**
     * Transporter browser
     * 
     * @param int $element_id
     * @param bool $element_type
     * @param int $pagination
     * 
     * @author Jakub Juracka
     */
    public function transporter_data($element_id = NULL, $is_last = FALSE)
    {
        $stats = new Statistics();
        $file_model = new File();

        if(!$element_id)
        {
            $this->alert->error('Invalid input.');
            $this->redirect('browse/transporters');
        }

        try
        {
            if($is_last)
            {
                $target = new Transporter_targets($element_id);

                if(!$target->id)
                {
                    throw new Exception('Transporter target not found.');
                }

                $subst_model = new Substances();

                // Get interactions
                $interactions = $subst_model
                    ->distinct()
                    ->select_list('substances.name, substances.identifier, substances.SMILES, substances.inchikey, substances.MW, 
                        substances.LogP, substances.pubchem, substances.drugbank, substances.pdb, substances.chEMBL,
                        tt.name as target, tt.uniprot_id as uniprot, t.type, t.note, t.Km, t.Km_acc, t.EC50, t.EC50_acc, t.Ki, t.Ki_acc,
                        t.IC50, t.IC50_acc, p1.citation as primary_reference, p2.citation as secondary_reference')
                    ->join('JOIN transporters t ON t.id_substance = substances.id AND t.id_target = ' . $target->id)
                    ->join('JOIN transporter_targets tt ON tt.id = t.id_target')
                    ->join('JOIN transporter_datasets td ON td.id = t.id_dataset')
                    ->join('LEFT JOIN publications p1 ON p1.id = t.id_reference')
                    ->join('LEFT JOIN publications p2 ON p2.id = td.id_reference')
                    ->get_all()
                    ->as_array();
            }
            else
            {
                $enum_type_link = new Enum_type_links($element_id);
                $enum_type = $enum_type_link->enum_type;

                if($enum_type->type != Enum_types::TYPE_TRANSPORTER_CATS || !$enum_type->id)
                {
                    throw new Exception('Invalid transporter group.');
                }

                $interactions = $enum_type->get_link_interactions($enum_type_link->id)->as_array();
            }

            $folder = File::FOLDER_TRANSPORTER_DOWNLOAD . $element_id . '/';
            $file_name =  ($is_last ? $target->name : $enum_type_link->enum_type->name) . '_interactions.csv';

            $file_path = $file_model->transform_to_CSV($interactions, $file_name, $folder);


            if(!$file_path)
            {
                $this->alert->warning('No data.');
                $this->redirect('browse/transporters');
            }

            header('Content-Tpe: application/csv');
            // tell the browser we want to save it instead of displaying it
            header('Content-Disposition: attachment; filename="' . $file_name .'.csv";');
            header("Content-length: " . filesize($file_path));
            header("Pragma: no-cache"); 
            header("Expires: 0"); 
            readfile("$file_path");
            die;
        }
        catch(Exception $e)
        {
            $this->alert->error($e->getMessage());
        }
        
        $this->redirect('browse/transporters');
    }
}