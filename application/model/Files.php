<?php

/**
 * Files model
 * 
 * @property int $id
 * @property string $name
 * @property string $comment
 * @property string $mime
 * @property double $file_size
 * @property string $hash
 * @property string $path
 * @property int $id_user
 * @property Users $user
 * @property int $id_validator_structure
 * @property Validator_structure $validator_structure
 * @property string $datetime
 * 
 * @author Jakub Juracka
 */
class Files extends Db
{
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'files';
        parent::__construct($id);
    }

    /**
     * Checks, if given file exists
     * 
     * @return boolean
     */
    public function file_exists()
    {
        if(!$this || !$this->id)
        {
            return false;
        }

        $file = new File($this->path);
        return $file->path ? TRUE : FALSE;
    }

    /**
     * Adds new record to the table
     * 
     * @param File $file
     * 
     * @author Jakub Juracka
     * @throws Exception
     * @return Files
     */
    public function add($file)
    {
        if(!($file instanceof File))
        {
            throw new Exception('Invalid parameter instance.');
        }

        if(!$file->path)
        {
            throw new Exception('Invalid file.');
        }

        $f = new Files();
        $f->path = $file->path;
        $f->name = basename($file->path);
        // Remove suffix
        $f->name = preg_replace('/\..+$/', "", $file->name, 1);
        $f->save();

        return $f;
    }

    /**
     * Redefine save function for data check
     * 
     * @author Jakub Juracka
     */
    public function save()
    {
        // Check, if file exists
        $f = new File($this->path);

        if(!$f->path)
        {
            // Not save
            throw new Exception('Cannot save file to the DB. Invalid path.');
        }

        if(!$this->mime)
        {
            // Add mime
            $this->mime = mime_content_type($f->path);
        }

        if(!$this->file_size)
        {
            // Add size
            $this->file_size = filesize($f->path);
        }

        if(!$this->hash)
        {
            // Add hash
            $this->hash = sha1_file($f->path, true);
        }

        parent::save();
    }
}