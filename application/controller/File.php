<?php

/**
 * File controller
 */
class FileController extends Controller 
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Adds cosmo file to the membrane
     * 
     * @author Jakub Juracka
     */
    public function add_cosmo()
    {
        // Target where to upload
        $target = MEDIA_ROOT . 'files/membranes/';

        $form = $this->form;
        $redir = 'edit/membrane';

        if(!$form->is_post() || !$this->form->has_file('cosmoFile'))
        {
            $this->alert->error('Endpoint is not accessible. Must be sent POST form data.');
            $this->redirect($redir);
        }

        $membrane = new Membranes($form->param->id_membrane);

        if(!$membrane->id)
        {
            $this->alert->error('Membrane not found..');
            $this->redirect($redir);
        }

        try
        {
            Db::beginTransaction();

            $file = $form->file->cosmoFile;
            $target .= $membrane->id . '/cosmo/';

            $file_o = new File($file->as_array());
            $f_model = new Files();
            
            // Check if file already exists?
            $exists = $f_model->get_by_hash($file_o);

            if($exists->id)
            {
                $membrane->id_cosmo_file = $exists->id;
                $membrane->save();            
            }
            else
            {
                if(intval($file_o->size) > 50000000) // 50mb max size
                {
                    throw new MmdbException('Sorry, your file is too large.', 'Sorry, your file is too large.');
                }

                if($file_o->extension !== 'mic') // Only CSV files allowed
                {
                    throw new MmdbException('Wrong file format. Upload only ".mic" files.', 'Wrong file format. Upload only ".mic" files.');
                }

                if($file_o->save_to_dir($target)) // Finally, move file to target directory
                {
                    $this->alert->warning('The file "' . $file_o->name . '" has been uploaded!');
                }
                else
                {
                    throw new MmdbException('Cannot move file to the target folder.');
                }

                // Make new DB record and return ID
                $n_file = new Files();

                $n_file->type = Files::T_MEMBRANE_COSMO;
                $n_file->name = $file_o->name;
                $n_file->path = $file_o->path;
                $n_file->id_user = session::user_id();
                $n_file->save();

                $membrane->id_cosmo_file = $n_file->id;
                $membrane->save();
            }

            Db::commitTransaction();
            $this->alert->success('Membrane COSMO file saved.');
        }   
        catch(MmdbException $e)
        {
            Db::rollbackTransaction();
            $this->alert->error($e);
        }

        $this->redirect($redir);
    }

    /**
     * Returns structure file of given molecule
     * 
     * @param int $id_substance
     * 
     * @author Jakub Juracka
     */
    public function structure($id_substance)
    {
        $s = new Substances($id_substance);

        if(!$s->id)
        {
            header('Not found', true, 404);
            die();
        }

        try
        {
            $smiles = $s->SMILES;
            $content = file_get_contents(
                Url::get_2d_structure_source($smiles),
                false,
                stream_context_create(array
                (
                    "ssl" => array
                    (
                        "verify_peer" => false,
                        "verify_peer_name" => false,
                    ),
                ))
            );

            // set headers
            header('Content-Type: text/html');
            header('Content-Length: ' . strlen($content));
            header('Expires: 0');
            header("Content-Disposition: inline; filename=" . $s->identifier);
            header('Connection: close');
            flush();

            // send file data
            echo $content;
            die;
        }
        catch(MmdbException $e)
        {
            header('Server error', true, 500);
            die();
        }
    }


    /**
     * Shows content of given file
     * 
     * @param int $file_id
     * 
     * @author Jakub Juracka
     */
    public function show($file_id)
    {
        $file = new Files($file_id);

        if(!$file->id)
        {
            $this->addMessageError('File not found.');
            $this->redirect('error');
        }

        $ph_file = new File($file->path);

        
        if(!$ph_file->path)
        {
            // Not exists
            $this->addMessageError('File content not found.');
            $this->redirect('error');
        }
        
        $data = file_get_contents($ph_file->path);

        // set headers
		header('Content-Type: ' . $file->mime);
		header('Content-Length: ' . filesize($file->path));
		header('Expires: 0');
		header("Content-Disposition: inline; filename=" . urlencode($ph_file->name));
		header('Connection: close');
		flush();

		// send file data
		echo $data;
    }

    
    /**
     * Downloads given file
     * 
     * @param int $file_id
     * 
     * @author Jakub Juracka
     */
    public function download($file_id)
    {
        $file = new Files($file_id);

        if(!$file->id)
        {
            $this->addMessageError('File not found.');
            $this->redirect('error');
        }

        $ph_file = new File($file->path);

        
        if(!$ph_file->path)
        {
            // Not exists
            $this->addMessageError('File content not found.');
            $this->redirect('error');
        }
        
        $data = file_get_contents($ph_file->path);

        // set headers
		header('Content-Type: ' . $file->mime);
		header('Content-Length: ' . filesize($file->path));
		header('Expires: 0');
		header("Content-Disposition: attachment; filename=" . urlencode($ph_file->name));
		header('Connection: close');
		flush();

		// send file data
		echo $data;
    }
}
