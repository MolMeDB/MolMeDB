<?php 

/**
 * Version 1.21 - COSMO-related tables
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "CREATE TABLE `fragments_ionized` (`id` INT NOT NULL AUTO_INCREMENT , `id_fragment` INT NULL DEFAULT NULL COMMENT 'Neutral parent' , `smiles` VARCHAR(1024) NOT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `fragments_ionized` ADD FOREIGN KEY (`id_fragment`) REFERENCES `fragments`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
    "ALTER TABLE `fragments_ionized` ADD UNIQUE (`smiles`);",
    "ALTER TABLE `fragments_ionized` ADD `cosmo_flag` TINYINT NULL DEFAULT NULL AFTER `smiles`;",

    "CREATE TABLE `run_ionization` (`id` INT NOT NULL AUTO_INCREMENT , `id_fragment` INT NOT NULL , `ph_start` DECIMAL NOT NULL , `ph_end` DECIMAL NOT NULL , `datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `run_ionization` ADD FOREIGN KEY (`id_fragment`) REFERENCES `fragments`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
    
    "CREATE TABLE `run_cosmo` (`id` INT NOT NULL AUTO_INCREMENT , `id_fragment` INT NOT NULL , `temperature` DECIMAL NOT NULL , `id_membrane` INT NOT NULL , `method` TINYINT NOT NULL COMMENT 'CosmoPerm/CosmoMic' , `priority` TINYINT NOT NULL DEFAULT '1' , `last_update` DATETIME on update CURRENT_TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `run_cosmo` ADD FOREIGN KEY (`id_fragment`) REFERENCES `fragments`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;", 
    "ALTER TABLE `run_cosmo` ADD FOREIGN KEY (`id_membrane`) REFERENCES `membranes`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
    "ALTER TABLE `run_cosmo` ADD `status` TINYINT NOT NULL DEFAULT '1' AFTER `state`;",
    "ALTER TABLE `run_cosmo` ADD `log` TEXT NULL DEFAULT NULL AFTER `last_update`;",
    "ALTER TABLE `run_cosmo` ADD `forceRun` TINYINT NULL DEFAULT NULL AFTER `status`;",

    "ALTER TABLE `membranes` ADD `id_cosmo_file` INT DEFAULT NULL AFTER `id`;",
    "ALTER TABLE `membranes` ADD FOREIGN KEY (`id_cosmo_file`) REFERENCES `files`(`id`) ON DELETE SET NULL ON UPDATE RESTRICT;",

    "ALTER TABLE `files` DROP INDEX `type`;",

    "CREATE TABLE `users_metacentrum` (`id` INT NOT NULL AUTO_INCREMENT , `id_user` INT NOT NULL , `login` VARCHAR(255) NOT NULL , `password` BLOB NOT NULL , `enabled` TINYINT NOT NULL DEFAULT '1' , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `users_metacentrum` ADD FOREIGN KEY (`id_user`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;"
);