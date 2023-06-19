<?php 

/**
 * Version 1.21 - COSMO-related tables
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "ALTER TABLE `run_cosmo` ADD `error_count` TINYINT NULL DEFAULT NULL AFTER `state`;",
    "ALTER TABLE `run_cosmo_datasets` ADD `create_date` DATE NULL DEFAULT NULL;"
);