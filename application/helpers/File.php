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
    private $FILE;

    // 3dstructure folder
    const FOLDER_3DSTRUCTURES = MEDIA_ROOT . "files/3DStructures/";
    
    // 
    const FOLDER_STATS_DOWNLOAD = MEDIA_ROOT . 'files/stats_download/';

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
     * Creates new file instance
     */
    public function create($filePath, $type = 'w')
    {
        // Get path
        $last_slash = strrpos($filePath, '/');
        $dir_path = substr($filePath, 0, $last_slash);

        // check path
        $this->make_path($dir_path);
        
        // Set default values
        $this->FILE = fopen($filePath, $type);
        $this->origin_path = $filePath;
        $this->name = substr($filePath, $last_slash + 1);
    }

    /**
     * Closes file
     */
    public function close()
    {
        if($this->FILE)
        {
            fclose($this->FILE);
        }
    }

    /**
     * Writes lines to the file
     * 
     * @param array $arr
     * 
     */
    public function writeCSV($arr)
    {
        if(!$this->FILE)
        {
            throw new Exception('Wrong file instance');
        }

        foreach($arr as $line)
        {
            if(!is_array($line))
            {
                throw new Exception('Wrong array instance');
            }
            fputcsv($this->FILE, $line, ';');
        }
    }

    /**
     * Writes line to the file
     * 
     * @param string $line
     */
    public function writeLine($line)
    {
        if(!$this->FILE)
        {
            throw new Exception('Wrong file instance');
        }

        fwrite($this->FILE, $line . PHP_EOL);
    }

    /**
     * Makes new filename
     * 
     * @return string
     */
    public static function generateName()
    {
        return 'tmp_file_' . strval(strtotime('now'));
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

    /**
     * Checks if exists 3d structure for given molecule identifier
     * 
     * @param string $identifier
     */
    public function structure_file_exists($identifier)
    {
        // If not exists, then make folder
        $this->make_path(self::FOLDER_3DSTRUCTURES);

        $path = self::FOLDER_3DSTRUCTURES . $identifier . '.mol';

        return file_exists($path) && filesize($path);
    }

    /**
     * Saves new file structure
     * 
     * @param string $identifier
     * @param string $content
     */
    public function save_structure_file($identifier, $content)
    {
        if($this->structure_file_exists($identifier))
        {
            $this->remove_structure_file($identifier);
        }

        $file = fopen(self::FOLDER_3DSTRUCTURES . $identifier . '.mol', 'w');
        fwrite($file, $content);
        fclose($file);
    }

    /**
     * Removes structure file
     * 
     * @param string $identifier
     */
    public function remove_structure_file($identifier)
    {
        $path = self::FOLDER_3DSTRUCTURES . $identifier . '.mol';

        if($this->structure_file_exists($identifier))
        {
            unlink($path);
        }
    }

    /**
     * Gets FILE
     * 
     * @return File
     */
    public function get_file()
    {
        if(!$this->FILE)
        {
            return NULL;
        }

        return $this->FILE;
    }

    /**
     * Transform data array to CSV and make file
     * 
     * @return File
     */
    public function transform_to_CSV($data, $file_name = null)
    {
        if(!is_array($data) || !is_array($data[0]))
        {
            return null;
        }

        if(!$file_name)
        {
            $file_name = strtotime('now') . '.csv';
        }

        $result = [array_keys($data[0])] + $data;

        $this->create(self::FOLDER_STATS_DOWNLOAD . $file_name);
        $this->writeCSV($result);
        $this->close();

        return $this->origin_path;
    }

    /**
     * Zip list of files
     * 
     * @param array|string $path
     * 
     * @return string
     */
    public function zip($path, $target, $delete_after = FALSE)
    {
        if(is_string($path))
        {
            $path = [$path];
        }

        if(!is_array($path))
        {
            throw new Exception('No files to zip.');
        }
        
        $t = $path;

        foreach($t as $key => $p)
        {
            if(!file_exists($p))
            {
                unset($path[$key]);
            }
        }

        if(!count($path))
        {
            return null;
        }

        $zip = new ZipArchive();

        // Delete old zip if exists
        if(file_exists($target))
        {
            unlink($target);
        }

        if ($zip->open($target, ZIPARCHIVE::CREATE) != TRUE) 
        {
            throw new Exception("Could not open archive");
        }
            
        foreach($path as $p)
        {
            $zip->addFile($p, basename($p));
        }
        
        // close and save archive
        $zip->close(); 

        if($delete_after)
        {
            foreach($path as $p)
            {
                unlink($p);
            }
        }
    }
}