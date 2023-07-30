<?php 

/**
 * Version 1.23 - COSMO-related tables
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "ALTER TABLE `run_cosmo` ADD `error_count` TINYINT NULL DEFAULT NULL AFTER `state`;",
    "ALTER TABLE `run_cosmo_datasets` ADD `create_date` DATE NULL DEFAULT NULL;",
    "ALTER TABLE `run_cosmo` ADD `next_remote_check` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `last_update`;",
);