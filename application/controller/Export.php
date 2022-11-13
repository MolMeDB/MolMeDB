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
     * Interaction types
     */
    const PASSIVE = 'passive';
    const ACTIVE  = 'active';

    /**
     * Exports passive interaction data for given molecule
     * 
     * @param string $type
     * @param int $id_substance
     */
    public function mol($type = self::PASSIVE, $id_substance)
    {
        $substance = new Substances($id_substance);

        if(!$substance->id)
        {
            echo 'Molecule with given ID does not exist.';
            die;
        }

        if(!in_array($type, [self::PASSIVE, self::ACTIVE]))
        {
            echo 'Invalid interaction type.';
            die;
        }

        $data = [];

        try
        {
            if($type == self::PASSIVE)
            {
                $suffix = 'passive';
                $raw_data = Interactions::instance()->where(array
                    (
                        'id_substance'  => $substance->id,
                        'visibility'    => Interactions::VISIBLE,
                    ))
                    ->get_all();

                foreach($raw_data as $row)
                {
                    $data[] = array
                    (
                        'substance' => $row->substance->name,
                        'substance_identifier' => $row->substance->identifier,
                        'membrane' => $row->membrane->name,
                        'method'    => $row->method->name,
                        'charge'    => $row->charge,
                        'temperature' => $row->temperature,
                        'note'      => $row->comment,
                        'x_min'     => $row->Position,
                        'x_min_acc' => $row->Position_acc,
                        'g_pen'     => $row->Penetration,
                        'g_pen_acc' => $row->Penetration_acc,
                        'g_wat'     => $row->Water,
                        'g_wat_acc' => $row->Water_acc,
                        'log_k'     => $row->LogK,
                        'log_k_acc' => $row->LogK_acc,
                        'log_perm'  => $row->LogPerm,
                        'log_perm_acc'  => $row->LogPerm_acc,
                        'primary_publication' => $row->publication ? $row->publication->citation : null,
                        'secondary_publication' => $row->dataset && $row->dataset->publication ? $row->dataset->publication->citation : null,
                    );
                }
            }
            else
            {
                $suffix = 'transporters';
                $raw_data = Transporters::instance()
                    ->where(array
                    (
                        'id_substance' => $substance->id,
                    ))
                ->get_all();

                foreach($raw_data as $row)
                {
                    if($row->dataset->visibility != Interactions::VISIBLE)
                    {
                        continue;
                    }

                    $data[] = array
                    (
                        'substance' => $row->substance->name,
                        'substance_identifier' => $row->substance->identifier,
                        'target'    => $row->target->name,
                        'type' => Transporters::instance()->get_enum_type($row->type),
                        'note' => $row->note,
                        'Km'    => $row->Km,
                        'Km_acc' => $row->Km_acc,
                        'EC50'  => $row->EC50,
                        'EC50_acc' => $row->EC50_acc,
                        'Ki'    => $row->Ki,
                        'Ki_acc' => $row->Ki_acc,
                        'IC50' => $row->IC50,
                        'IC50_acc' => $row->IC50_acc,
                        'primary_publication' => $row->reference ? $row->reference->citation : null,
                        'secondary_publication' => $row->dataset->reference ? $row->dataset->reference->citation : null
                    );
                }
            }

            // Set filename
            $filename = $substance->identifier . '_' . $suffix . '.csv';

        }
        catch(Exception $e)
        {
            $this->alert->error('Error occured while getting server data.');
            $this->redirect('mol/' . $substance->identifier);
        }

        // Make final final
        $file = fopen('php://output', 'w');

        if(count($data))
        {
            // First, add header
            $header = array_keys($data[0]);
            fputcsv($file, $header, ';');

            foreach($data as $row)
            {
                fputcsv($file, $row, ';');
            }
        }

        header('Content-Tpe: text/csv');
        // tell the browser we want to download it instead of displaying it
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header("Pragma: no-cache"); 
        header("Expires: 0"); 
        fpassthru($file);
        die;
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
        catch(MmdbException $e)
        {
            $this->alert->error($e);
        }
        
        $this->redirect('browse/transporters');
    }

    /**
     * Types
     */
    const DOWN_TYPE_PASSIVE = 1;
    const DOWN_TYPE_ACTIVE  = 2;

    /**
     * Exports downloader data
     * 
     * @param int $type
     */
    public function downloader($type = self::DOWN_TYPE_PASSIVE)
    {
        if(!$_POST)
        {
            die('Invalid parameters');
        }

        if($type == self::DOWN_TYPE_PASSIVE)
        {
            $id_membranes = isset($_POST['id_membranes']) ? $_POST['id_membranes'] : [];
            $id_methods = isset($_POST['id_methods']) ? $_POST['id_methods'] : [];
            $id_substances = isset($_POST['id_molecules']) ? $_POST['id_molecules'] : [];
            $logic = isset($_POST['logic']) ? $_POST['logic'] : null;

            if(strtolower($logic) != 'and')
            {
                $logic = 'or';
            }

            $logic = strtolower($logic);

            $query = 'SELECT id 
                    FROM interaction
                    WHERE visibility = 1';
                
            if(empty($id_membranes) && empty($id_methods) && empty($id_substances))
            {
                // Download all
                $stats = new Statistics();
                $files = $stats->get_stat_files();

                if(isset($files[Statistics::TYPE_INTER_ADD]) && $files[Statistics::TYPE_INTER_ADD])
                {
                    $this->redirect('file/download/' . $files[Statistics::TYPE_INTER_ADD]->id);
                }
            }
            else if(!empty($id_methods) && empty($id_membranes))
            {
                $query .= ' AND id_method IN ("' . implode('","', $id_methods) .'")';
            }
            else if(!empty($id_membranes) && empty($id_methods))
            {
                $query .= ' AND id_membrane IN ("' . implode('","', $id_membranes) .'")';
            }
            else if(!empty($id_membranes) && !empty($id_methods))
            {
                $query .= ' AND (id_membrane IN ("' . implode('","', $id_membranes) . '") ' 
                    . ($logic == 'and' ? "AND" : "OR") . ' id_method IN ("' . implode('","', $id_methods) .'"))';
            }

            if(!empty($id_substances))
            {
                $query .= ' AND id_substance IN ("' . implode('","', $id_substances) . '")';
            }

            $ids = Db::instance()->queryAll($query);
        }
        else if($type == self::DOWN_TYPE_ACTIVE)
        {
            if(!isset($_POST['id_group']) || !isset($_POST['is_last']))
            {
                die('Invalid parameters');
            }

            $id_group = $_POST['id_group'];
            $is_last = $_POST['is_last'];

            if($is_last)
            {
                $target = new Transporter_targets($id_group);

                if(!$target->id)
                {
                    die('Transporter target not found.');
                }

                $ids = Db::instance()->queryAll('
                    SELECT DISTINCT t.id
                    FROM transporters t
                    JOIN transporter_datasets td ON td.id = t.id_dataset 
                    WHERE t.id_target = ? AND td.visibility = ? 
                ', array($target->id, Transporter_datasets::VISIBLE));
            }
            else
            {
                $enum_type_link = new Enum_type_links($id_group);
                $enum_type = $enum_type_link->enum_type;

                if($enum_type->type != Enum_types::TYPE_TRANSPORTER_CATS || !$enum_type->id)
                {
                    throw new Exception('Invalid transporter group.');
                }

                $els = $enum_type->get_items($enum_type_link->id, 'transporter_target');

                $ids = Arr::get_values($els, 'id');

                $ids = Db::instance()->queryAll('
                    SELECT DISTINCT t.id
                    FROM transporters t
                    JOIN transporter_datasets td ON td.id = t.id_dataset
                    WHERE td.visibility = ? AND t.id_target IN ("' . implode('","', $ids) . '")
                ', array(Transporter_datasets::VISIBLE));
            }
        }

        $ids = Arr::get_values($ids, 'id');

        $this->view = new Render_js_export($type);
        $this->view->ids = json_encode($ids);
    }

    /**
     * Exports uploded file
     * 
     * @param int $id - File ID
     */
    public function uploadFile($id = NULL)
    {
        $file = new Files($id);

        if(!$file->id)
        {
            $this->alert->error('Invalid file id.');
            $this->redirect('error');
        }

        // TODO - Access rights
        if(!session::is_logged_in())
        {
            $this->redirect('login');
        }

        // Make final final
        if(!($file_stream = fopen($file->path, 'r')))
        {
            $this->alert->error('Cannot open the target file.');
            $this->redirect('error');
        } 

        header('Content-Tpe: text/csv');
        // tell the browser we want to download it instead of displaying it
        header('Content-Disposition: attachment; filename="'.$file->name.'.csv"');
        header("Pragma: no-cache"); 
        header("Expires: 0"); 
        fpassthru($file_stream);
        die;
    }
}