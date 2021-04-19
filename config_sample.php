<?php

// DB login info
define("dbURL", "localhost");
define("dbName", "molmedb");
define("dbUser", "root");
define("dbPassword", "password");

// SERVER VALUES 
define("URL", $_SERVER['HTTP_HOST']);
define("PROTOCOL", substr(strtolower($_SERVER['SERVER_PROTOCOL']), 0, 5) === 'https' ? "https://" : "http://");

// Folder structure
define("APP_ROOT", "application/");
define("MEDIA_ROOT", "media/");

// Debug mode
define('DEBUG', false);

// Maintenance
define('MAINTENANCE', False);

define('GOOGLE_ANALYTICS', "");