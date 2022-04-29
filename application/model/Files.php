<?php

/**
 * Files model
 * 
 * @property int $id
 * @property int $type
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
 * @property File $file_handler
 * 
 * @author Jakub Juracka
 */
class Files extends Db
{
    /** SPECIAL TYPES OF FILES FOR EASY FINDING */
    const T_STATS_INTERACTIONS_ALL = 1;
    const T_STATS_ACTIVE_PASSIVE = 2;
    const T_STATS_IDENTIFIERS = 3;
    const T_STATS_SUBST_INTERACTIONS = 4;

    /** RDF SPECIAL FILES */
    const T_RDF_VOCABULARY = 100;

    private static $valid_types = array
    (
        self::T_STATS_INTERACTIONS_ALL,
        self::T_STATS_ACTIVE_PASSIVE,
        self::T_STATS_IDENTIFIERS,
        self::T_STATS_SUBST_INTERACTIONS
    );

    private static $type_path = array
    (
        self::T_STATS_INTERACTIONS_ALL  => File::FOLDER_STATS_DOWNLOAD . 'interactions_all',
        self::T_STATS_ACTIVE_PASSIVE    => File::FOLDER_STATS_DOWNLOAD . 'active_passive_interactions',
        self::T_STATS_IDENTIFIERS       => File::FOLDER_STATS_DOWNLOAD . 'identifiers',
        self::T_STATS_SUBST_INTERACTIONS => File::FOLDER_STATS_DOWNLOAD . 'substances_interactions'
    );

    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'files';
        parent::__construct($id);
        $this->file = new File($this->path);
    }

    /**
     * Instance
     * 
     * @return Files
     */
    public static function instance()
    {
        return parent::instance();
    }

    /**
     * Checks, if given type is valid
     * 
     * @param $type
     * 
     * @return bool
     */
    public static function valid_type($type)
    {
        return in_array($type, self::$valid_types);
    }

    /**
     * Returns default path of file for given type
     * 
     * @param $type
     * 
     * @return string|null
     */
    public static function get_type_path($type)
    {
        if(!self::valid_type($type) || !array_key_exists($type, self::$type_path))
        {
            return null;
        }
        return self::$type_path[$type];
    }

    /**
     * Returns file record by type
     * 
     * @param $type
     * 
     * @return Files
     */
    public static function get_by_type($type)
    {
        return Files::instance()->where('type', $type)->get_one();
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

    /**
     * Returns file content
     */
}