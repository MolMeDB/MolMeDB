<?php 

/**
 * Version 1.21
 */
$upgrade_sql = array
(
    "ALTER TABLE `validator_identifiers_duplicities` ADD `progress` tinyint(4) NULL DEFAULT NULL AFTER `state`;",
);