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
