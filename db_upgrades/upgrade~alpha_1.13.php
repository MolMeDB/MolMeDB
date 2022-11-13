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
    "CREATE TABLE `exceptions` ( `id` INT NOT NULL AUTO_INCREMENT , `level` TINYINT NOT NULL , `code` INT NULL DEFAULT NULL , `file` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL , `line` SMALLINT NULL DEFAULT NULL , `trace` TEXT CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL , `message` TEXT CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL , `id_user` INT NULL DEFAULT NULL , `datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `exceptions` ADD FOREIGN KEY (`id_user`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
    "ALTER TABLE `exceptions` ADD `status` TINYINT NOT NULL DEFAULT '0' AFTER `id`;",

    // Fragmentation improvement
    "ALTER TABLE `fragments` ADD UNIQUE (`smiles`(1000));"
);