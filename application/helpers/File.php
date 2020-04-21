<?php

/**
 * Class for working with files
 */
class File
{
    // File info
    public $name;
    public $extension;
    public $size;
    public $origin_path;

    /**
     * Constructor
     * 
     * @param string $path - PATH to the file
     */
    function __construct($path = NULL)
    {
        if($path && is_array($path)) // Uploaded file
        {
            $this->origin_path = $path['tmp_name'];
            $this->size = $path['size'];
            $this->extension = pathinfo($path['name'], PATHINFO_EXTENSION);
            $this->name = rtrim($path['name'], '.'. $this->extension);
        }
        else if($path)
        {
            $this->origin_path = $path;

            // Check if exists
            if(!file_exists($path))
            {
                throw new Exception("File '$path' was not found.");
            }

            $this->get_file_info($path);
        }
    }

    /**
     * Loads file info
     */
    private function get_file_info($path)
    {
        // get size
        $this->size = filesize($path);
        // get name
        $this->name = basename($path);
        // get extension
        $this->extension = pathinfo($path,PATHINFO_EXTENSION);
    }


    /**
     * Saves to the given directory
     * 
     * @param string $dir_path
     */
    public function save_to_dir($dir_path)
    {
        if(ltrim($dir_path, MEDIA_ROOT) === $dir_path && $dir_path[0] != '/')
        {
            $dir_path = MEDIA_ROOT . trim($dir_path, '/');
        }

        $dir_path = rtrim($dir_path, '/');

        //Check if path exists
        $this->make_path($dir_path);

        $target = $dir_path . '/' . $this->name . '.' . $this->extension;

        if(file_exists($target))
        {
            throw new Exception('Sorry, file "' . $target . '" already exists.');
        }

        if(!rename($this->origin_path, $target))
        {
            throw new Exception('Cannot move uploaded file to target directory.');
        }

        return True;
    }

    /**
     * Renames file
     * 
     * @param string $new_name
     */
    public function rename($new_name)
    {
        $path = explode('/', $this->origin_path);

        unset($path[count($path)-1]);

        $path = rtrim(implode('/', $path), '/') . '/';

        if(!rename($this->origin_path, $path . $new_name . '.' . $this->extension))
        {
            throw new Exception('Cannot rename file.');
        }

        return True;
    }

    /**
     * Makes path if not exists
     * 
     * @param string $dir_path
     */
    public function make_path($dir_path)
    {
        $dir_path = trim($dir_path);

        if(!is_dir($dir_path))
        {
            mkdir($dir_path, 0777, True);
        }
    }
}