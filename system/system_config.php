<?php

/**
 * Internal system configuration
 * 
 * @author Jakub Juracka
 */
class System_config
{
    /**
	 * Regex for valitadation of DB versions
	 */
	const VERSION_REGEX = '^(((alpha|beta|dev|rc)([1-9][0-9]*)?)(\_(\d|[1-9][0-9]+)\.(\d|[0-9][0-9]+))?)$';

    /**
     * Is DB connected?
     */
    public static $db_connected = FALSE;

    /**
     * Constructor
     */
    function __construct()
    {
        // Connect to DB
        self::DB_CONNECT();

        if(!Server::is_maintenance())
        {
            // Check DB version
            self::check_DB_version();
        }

        self::$db_connected = TRUE;
    }

    /**
     * Init system config
     */
    public static function connect()
    {
        return new System_config();
    }

    /**
     * Checks if DB has correct version
     * IF not, then upgrade
     */
    private static function check_DB_version()
    {
        // GET version from DB
        $db = new Db();

        // Check, if GROUP_BY is not restricted
        $db->check_group_by_restrictions();
        try
        {
            $db_ver = $db->get_db_version();
        }
        catch(Exception $e)
        {
            throw new Exception('Looks like DB is not initialized. Please, check the DB status and try again.');
        }
        
        $app_ver = DB_VERSION;

        if($db_ver == $app_ver)
        {
            return True;
        }

        $upgrades = self::scan_applicable_db_upgrades($db_ver, $app_ver);

        sort($upgrades);

        foreach($upgrades as $file)
        {
            self::execute_db_upgrade($file);
        }
    }

    /**
     * Executes DB upgrade from given file
     * 
     * @param string $file - Filename
     */
    private static function execute_db_upgrade($file)
    {
        require('db_upgrades/' . $file);

        $db = new Db();

        try
        {
            Db::beginTransaction();

            foreach($upgrade_sql as $sql)
            {
                $db->query($sql);
            }

            // New version
            $new_version = str_replace(array('upgrade~', '.php'), '', $file);

            // Change DB version in DB
            $db->set_db_version($new_version);

            Db::commitTransaction();
        }
        catch(Exception $e)
        {
            Db::rollbackTransaction();
            throw new Exception('Error occured during DB upgrade. FILE[' . $file . '] <br/> ' . $e->getMessage());
        }
    }

    /**
     * Scan directory with given name for upgrade DB files and returns
     * all applicable DB upgrade versions. Applicable upgrade means that
     * its version is higher than the current database version.
     *
     * @param string $db_version
     * @param string $app_version
     * 
     * @return array list of applicable DB upgrade versions
     */
    private static function scan_applicable_db_upgrades($db_version, $app_version)
    {
        $scan_directory = 'db_upgrades/';

        $db_version = floatval(self::get_version_from_string($db_version));
        $app_version = floatval(self::get_version_from_string($app_version));

		// array of available verisons
		$versions = array();
		// gets all files in scan directory dir
        $files = scandir($scan_directory);
        
		// regex for file: upgrade_VERSION.php
		$regex = '^upgrade~' . rtrim(ltrim(self::VERSION_REGEX, '^'), '$')
                . '\.php$';
		// filter files
		foreach ($files as $file)
		{
            $matches = array();
			// remove invalid files (wrong name) and value replace by version
			if (!mb_eregi($regex, $file, $matches))
			{
				continue;
            }

			// get version
            $version = floatval(self::get_version_from_string($matches[1]));
            
			// remove old already installed upgrades and future upgrades
			if ($db_version >= $version || $version > $app_version)
			{
                continue;
            }
            
			// add to available versions
			$versions[] = $file;
		}

        return $versions;
    }

    /**
     * Returns version of current file
     * 
     * @param string $string - Filename
     * 
     * @return string 
     */
    private static function get_version_from_string($string)
    {
        return ltrim(ltrim(str_replace(array('alpha_', 'beta_', 'dev_', 'rc_'), '', $string),'upgrade~'), 'molmedb~');
    }


    /**
     * Try to connect to the DB
     */
    private static function DB_CONNECT()
    {
        try
        {
            Db::connect(dbURL, dbUser, dbPassword, dbName);
        }
        catch(Exception $e)
        {
            throw new Exception('Cannot connect to DB. Please, check your config.php file.');
        }
    }

    /**
     * Callback for autoloading files
     * 
     * @param string $class - Class name
     */
    public static function autoload($class)
    {
        $targets = array
        (
            APP_ROOT . 'helpers/',
            APP_ROOT . 'libraries/',
            APP_ROOT . 'model/',
            'system/exceptions/',
            'system/'
        );

        // Default destinations for fast loading
        if (preg_match('/Controller$/', $class))
        {
            if($class != 'Controller')
            {
                $class = str_replace('Controller', '', $class);
            }
            require_once(APP_ROOT . "controller/" . $class . ".php");
        }
        else if (preg_match('/^Api/', $class) 
            && file_exists(APP_ROOT . "controller/Api/" . substr($class, 3) . ".php"))
        {
            $class = str_replace('Api', '', $class);
            require(APP_ROOT . "controller/Api/" . $class . ".php");
        }
        else if (preg_match('/^Mol_/', $class) 
            && file_exists(APP_ROOT . "libraries/Mol/" . ucfirst(substr($class, 4)) . ".php"))
        {
            $class = ucfirst(str_replace('Mol_', '', $class));
            require(APP_ROOT . "libraries/Mol/" . $class . ".php");
        }
        else if (preg_match('/^View_/', $class) 
            && file_exists(APP_ROOT . "libraries/View/" . ucfirst(substr($class, 5)) . ".php"))
        {
            $class = ucfirst(str_replace('View_', '', $class));
            require(APP_ROOT . "libraries/View/" . $class . ".php");
        }
        else if (preg_match('/^Render_/', $class) 
            && file_exists(APP_ROOT . "helpers/Render/" . ucfirst(str_replace('Render_', '', $class)) . ".php"))
        {
            $class = ucfirst(str_replace('Render_', '', $class));
            require(APP_ROOT . "helpers/Render/" . $class . ".php");
        }
        else if (preg_match('/^Run_/', $class) 
            && file_exists(APP_ROOT . "helpers/Run/" . ucfirst(str_replace('Run_', '', $class)) . ".php"))
        {
            $class = ucfirst(str_replace('Run_', '', $class));
            require(APP_ROOT . "helpers/Run/" . $class . ".php");
        }
        else
        {
            foreach($targets as $folder)
            {
                if(file_exists($folder . $class . ".php"))
                {
                    require_once($folder . $class . ".php");
                }
                else if(file_exists($folder . strtolower($class) . ".php"))
                {
                    require_once($folder . strtolower($class) . ".php");
                }
            }
        }
    }   
}
