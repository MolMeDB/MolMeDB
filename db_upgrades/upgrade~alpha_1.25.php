<?php 

/**
 * Version 1.25 - COSMO-related tables
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "ALTER TABLE `run_cosmo_datasets` ADD `notify_state` TINYINT NULL DEFAULT NULL AFTER `token`;",
);
