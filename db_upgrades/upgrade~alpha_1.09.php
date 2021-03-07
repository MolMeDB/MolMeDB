<?php 

/**
 * Browse improvement
 * Version 1.09
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
   "ALTER TABLE `publications` ADD `total_passive_interactions` INT NULL DEFAULT NULL AFTER `pattern`, ADD `total_active_interactions` INT NULL DEFAULT NULL AFTER `total_passive_interactions`;",
   "ALTER TABLE `publications` ADD `total_substances` INT NULL DEFAULT NULL AFTER `total_active_interactions`;"
);
