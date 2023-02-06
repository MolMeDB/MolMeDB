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
    public $path;
    public $origin_path;
    private $FILE;

    // 3dstructure folder
    const FOLDER_3DSTRUCTURES = MEDIA_ROOT . "files/3DStructures/";
    const FOLDER_STATS_DOWNLOAD = MEDIA_ROOT . 'files/download/stats/';
    const FOLDER_PUBLICATION_DOWNLOAD = MEDIA_ROOT . 'files/download/publication/';
    const FOLDER_MEMBRANE_DOWNLOAD = MEDIA_ROOT . 'files/download/membrane/';
    const FOLDER_METHOD_DOWNLOAD = MEDIA_ROOT . 'files/download/method/';
    const FOLDER_TRANSPORTER_DOWNLOAD = MEDIA_ROOT . 'files/download/transporter/';
    const FOLDER_DATASETS = MEDIA_ROOT . 'files/datasets/';
    const FOLDER_CONFORMERS = MEDIA_ROOT . 'files/conformers';

    /**
     * Constructor
     * 
     * @param string|int|Files $path - PATH to the file
     */
    function __construct($path = NULL)
    {
        // File model id
        if(is_numeric($path))
        {
            $f = new Files($path);
            $path = $f->path;
        }
        else if($path instanceof Files)
        {
            $path = $path->path;
        }
        
        if($path && is_array($path) && 
            isset($path['tmp_name']) && // Uploaded file
            isset($path['size']) && // Uploaded file
            isset($path['name'])) // Uploaded file
        {
            $this->origin_path = $this->path = $path['tmp_name'];
            $this->size = $path['size'];
            $this->extension = pathinfo($path['name'], PATHINFO_EXTENSION);
            $this->name = rtrim($path['name'], '.'. $this->extension);
        }
        else if($path && file_exists($path))
        {
            $this->origin_path = $this->path = $path;
            $this->get_file_info($path);
        }
    }

    /**
     * Creates new file instance
     */
    public function create($filePath = NULL, $type = 'w')
    {
        if($filePath)
        {
            // Get path
            $last_slash = strrpos($filePath, '/');
            $dir_path = substr($filePath, 0, $last_slash);

            // check path
            $this->make_path($dir_path);
        }
        else
        {
            throw new Exception('Invalid file path.');
        }

        // Set default values
        $this->FILE = fopen($filePath, $type);
        $this->origin_path = $this->path = $filePath;
        $this->name = substr($filePath, $last_slash + 1);
    }

    /**
     * Checks, if file exists
     * 
     * @return bool
     */
    public function exists()
    {
        return $this->path && file_exists($this->path);
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
     * Prepare conformers folder for upload
     * 
     * @param int $id_fragment
     * 
     * @return string - Path/to/folder/
     */
    public function prepare_conformer_folder($id_fragment, $id_ion)
    {
        $group = intval($id_fragment / 10000);
        $group = $group*10000;
        $group .= '-' . ($group+1000); 

        $path = self::FOLDER_CONFORMERS . "/" . $group . '/' . $id_fragment . "/" . $id_ion . '/';

        if(!file_exists($path))
        {
            if(!mkdir($path, 0777, TRUE))
            {
                throw new Exception('Cannot create folder structure.');
            }
        }

        return $path;
    }

    /**
     * Remove conformer folder
     * 
     * @param int $id_fragment
     * 
     * @return void
     */
    public function remove_conformer_folder($id_fragment)
    {
        $group = intval($id_fragment / 10000);
        $group = $group*10000;
        $group .= '-' . ($group+1000); 

        $path = self::FOLDER_CONFORMERS . "/" . $group . '/' . $id_fragment . "/";

        if(!file_exists($path))
        {
            return;
        }

        // Remove whole content recursively
        $it = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it,
                    RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if ($file->isDir()){
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($path);
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
     * Writes content to the file
     * 
     * @param string $text
     */
    public function write($text)
    {
        if(!$this->FILE)
        {
            throw new Exception('Wrong file instance');
        }

        fwrite($this->FILE, $text);
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

        $i = 0;
        while(file_exists($target))
        {
            $i++;
            $target = $dir_path . '/' . $this->name . '_' . $i . '.' . $this->extension;
        }

        if(!rename($this->origin_path, $target))
        {
            throw new Exception('Cannot move uploaded file to target directory.');
        }

        $this->path = $target;

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

        $this->path = $path . $new_name . '.' . $this->extension;

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
     * Parses file content
     * 
     * @param string $delimiter
     * 
     * @return array
     */
    public function parse_csv_content($delimiter)
    {
        $data = [];

        if(!$this->path || !file_exists($this->path))
        {
            return [];
        }

        if(!($file = fopen($this->path, 'r')))
        {
            return [];
        }

        while($row = fgets($file))
		{
			$row = str_replace(array("\n","\r\n","\r"), '', $row);
			$data[] = explode($delimiter, $row);
		}

        return $data;
    }

    /**
     * Transform data array to CSV and make file
     * 
     * @return string|null
     */
    public function transform_to_CSV($data, $file_name, $prefix = self::FOLDER_STATS_DOWNLOAD)
    {
        if(!$data || !is_array($data) || !is_array($data[0]))
        {
            return null;
        }

        if(!$prefix)
        {
            $prefix = '';
        }

        if(!$file_name)
        {
            $file_name = strtotime('now') . '.csv';
        }

        array_unshift($data, array_keys($data[0]));

        $this->create($prefix . $file_name);

        $this->writeCSV($data);

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
                // echo 'not_exists';
                unset($path[$key]);
            }
        }

        if(!count($path))
        {
            if(DEBUG)
            {
                echo 'Nothing to zip';
            }
            return null;
        }

        $zip = new ZipArchive();

        // Delete old zip if exists
        if(file_exists($target))
        {
            unlink($target);
        }

        if(!preg_match('/\.zip$/', $target))
        {
            $target .= '.zip';
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

        return $target;
    }
}