<?php
session_start();

// Set internal coding
mb_internal_encoding("UTF-8");

require 'system/system_config.php';

if(DEBUG)
{
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

try 
{
    // Make default settings
    new System_config();
    
    // Parse URL
    $router = new RouterController();
    $router->parse($_SERVER['REQUEST_URI']);
} 
catch (Exception $e) 
{
    echo($e->getMessage());
    die;
}

$router->showView();