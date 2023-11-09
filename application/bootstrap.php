<?php
/**
 * Check, if all requirements to run are met 
 */

/**
 * Start session
 */
session_start();

/**
 * Set coding
 */
mb_internal_encoding("UTF-8");

/**
 * Set global variables
 */
define("APP_ROOT", "application/");
define("SYS_ROOT", "system/");
define("MEDIA_ROOT", "media/");


/**
 * Import config with DB connection info
 */
if(!file_exists('config.php'))
{
    echo "Cannot find `config.php` file. Use `config_sample.php` as template.";
    die();
}

include_once("config.php");

/**
 * Import version file
 */
if(!file_exists('version.php'))
{
    echo "Cannot find `version.php` file. Make sure the file exists.";
    die();
}

include("version.php");

/**
 * If DEBUG is on, print verbose errors
 */
if(DEBUG)
{
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/**
 * Disable memory limit
 */
ini_set('memory_limit', '-1');

/**
 * Import server info methods
 */
require(SYS_ROOT . 'server.php');

/**
 * Import Exceptions contants
 */
require(SYS_ROOT . 'exceptions.php');

/**
 * Import internal system config
 */
if(!file_exists(SYS_ROOT . "system_config.php"))
{
    echo "Cannot find `system_config.php` file. Make sure the file exists and the SYS_ROOT path is valid.";
    die();
}

require(SYS_ROOT . "system_config.php");

/**
 * Register autoload function 
 */
spl_autoload_register("System_config::autoload");

/**
 * Init DB connection
 */
try
{
    System_config::connect();
}
catch(Exception $e)
{
    if(DEBUG)
    {
        Server::print_global_exception($e);
        die;
    }
    echo DB_GLOBAL_ERROR;
    die;
}

/**
 * Check, if required folders are writeable
 */
$req_write_permissions = array
(
    MEDIA_ROOT,
    MEDIA_ROOT . 'files'
);

foreach($req_write_permissions as $f)
{
    if(!is_writable($f))
    {
        echo 'Folder `' . $f . '` is not writeable. Please, check folder permissions.';
        die;
    }
}

/**
 * Finally, run app
 * - Catches uncaught exception
 */
try 
{
    $router = new RouterController();
    $router->parse(Server::request_uri());
} 
catch (Exception $e) 
{
    if(DEBUG)
    {
        Server::print_global_exception($e);
    }
    else
    {
        echo SERVER_GLOBAL_ERROR;
    }
}

?>