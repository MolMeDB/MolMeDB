<?php

// DB login info
define("dbURL", "database");
define("dbName", "molmedb");
define("dbUser", "molmedb");
define("dbPassword", "molmedb");

// SERVER VALUES 
define("URL", $_SERVER['HTTP_HOST']);
define("PROTOCOL", substr(strtolower($_SERVER['SERVER_PROTOCOL']), 0, 5) === 'https' ? "https://" : "http://");

// Folder structure
define("APP_ROOT", "application/");
define("SYS_ROOT", "system/");
define("MEDIA_ROOT", "media/");

// Debug mode
define('DEBUG', false);
define('DEBUG_API', false);

// Maintenance
define('MAINTENANCE', False);

define('GOOGLE_ANALYTICS', "");
