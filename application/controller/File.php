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
