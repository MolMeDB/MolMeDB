<?php 

/**
 * Version 1.13
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "ALTER TABLE `files` ADD `type` TINYINT NULL DEFAULT NULL AFTER `id`;",
    "ALTER TABLE `files` ADD UNIQUE (`type`);",
    "ALTER TABLE `stats` DROP `data_path`;",
    "ALTER TABLE `stats` ADD `id_file` INT NULL DEFAULT NULL AFTER `content`;",
    "ALTER TABLE `stats` ADD FOREIGN KEY (`id_file`) REFERENCES `files`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
);