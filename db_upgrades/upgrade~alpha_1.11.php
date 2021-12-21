<?php 

/**
 * Validator improvement
 * 
 * Version 1.11
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
   "ALTER TABLE `validations` ADD `state` TINYINT NULL DEFAULT NULL AFTER `type`;",
   "ALTER TABLE `validations` CHANGE `duplicity` `description` TEXT CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL;",
   "ALTER TABLE `validations` DROP `active`;",
   "CREATE TABLE `validation_values` ( `id_validation` INT NOT NULL , `value` VARCHAR(255) NULL DEFAULT NULL ) ENGINE = InnoDB;",
   "ALTER TABLE `validation_values` CHANGE `value` `value` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL;",
   "ALTER TABLE `validation_values` ADD FOREIGN KEY (`id_validation`) REFERENCES `validations`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;",
   "ALTER TABLE `validation_values` ADD `id` INT NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (`id`);",
   "ALTER TABLE `validation_values` ADD `flag` TINYINT NULL DEFAULT NULL AFTER `value`;",
   "ALTER TABLE `validation_values` ADD `description` TEXT NULL DEFAULT NULL AFTER `flag`;",
   "ALTER TABLE `validation_values` ADD `group_nr` SMALLINT NULL DEFAULT NULL AFTER `flag`, ADD `order_nr` SMALLINT NULL DEFAULT NULL AFTER `group_nr`;",
   "ALTER TABLE `validation_values` ADD `state` TINYINT NULL DEFAULT NULL AFTER `description`;",
   "ALTER TABLE `validation_values` ADD `dateTime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `state`;"
   // přidat klíče
);