<?php 

/**
 * Browse improvement
 * 
 * --- Added enum types table
 * 
 * Version 1.10
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
   "CREATE TABLE `enum_types` ( `id` INT NOT NULL AUTO_INCREMENT , `name` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL , `type` TINYINT NOT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
   "ALTER TABLE `enum_types` ADD `content` TEXT NULL DEFAULT NULL AFTER `name`;",
   "CREATE TABLE `enum_type_links` ( `id` INT NOT NULL AUTO_INCREMENT , `id_enum_type` INT NOT NULL , `id_enum_type_parent` INT NOT NULL , `data` TEXT NULL DEFAULT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
   "ALTER TABLE `enum_type_links` ADD UNIQUE (`id_enum_type`);",
   "ALTER TABLE `enum_type_links` ADD FOREIGN KEY (`id_enum_type`) REFERENCES `enum_types`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
   "ALTER TABLE `enum_type_links` ADD FOREIGN KEY (`id_enum_type_parent`) REFERENCES `enum_types`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
   "ALTER TABLE `enum_type_links` ADD `id_parent_link` INT NULL DEFAULT NULL AFTER `id_enum_type_parent`;",
   "ALTER TABLE `enum_type_links` ADD FOREIGN KEY (`id_parent_link`) REFERENCES `enum_type_links`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
   "ALTER TABLE `enum_type_links` ADD `reg_exp` VARCHAR(255) NULL DEFAULT NULL AFTER `data`;",
   "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Membranes', NULL, '1'), (NULL, 'Methods', NULL, '2'), (NULL, 'Transporters', NULL, '3')",
   // Delete old category tables
   "DROP TABLE `cat_membranes`, `cat_mem_mem`, `cat_subcat_membranes`;",
   "CREATE TABLE `membrane_enum_type_links` ( `id_enum_type` INT NOT NULL , `id_membrane` INT NOT NULL ) ENGINE = InnoDB;",
   "ALTER TABLE `membrane_enum_type_links` ADD FOREIGN KEY (`id_enum_type`) REFERENCES `enum_type_links`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
   "ALTER TABLE `membrane_enum_type_links` ADD FOREIGN KEY (`id_membrane`) REFERENCES `membranes`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
   "ALTER TABLE `membrane_enum_type_links` ADD UNIQUE (`id_membrane`);",

   "CREATE TABLE `method_enum_type_links` ( `id_enum_type_link` INT NOT NULL , `id_method` INT NOT NULL ) ENGINE = InnoDB;",
   "ALTER TABLE `method_enum_type_links` ADD FOREIGN KEY (`id_enum_type_link`) REFERENCES `enum_type_links`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
   "ALTER TABLE `method_enum_type_links` ADD FOREIGN KEY (`id_method`) REFERENCES `methods`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",

   "CREATE TABLE `transporter_target_enum_type_links` ( `id_enum_type_link` INT NOT NULL , `id_transporter_target` INT NOT NULL ) ENGINE = InnoDB;",
   "ALTER TABLE `transporter_target_enum_type_links` ADD FOREIGN KEY (`id_enum_type_link`) REFERENCES `enum_type_links`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
   "ALTER TABLE `transporter_target_enum_type_links` ADD FOREIGN KEY (`id_transporter_target`) REFERENCES `transporter_targets`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",


);
