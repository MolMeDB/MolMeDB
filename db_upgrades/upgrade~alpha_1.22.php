<?php 

/**
 * Version 1.21 - COSMO-related tables
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "CREATE TABLE `run_cosmo_datasets` (`id` INT NOT NULL AUTO_INCREMENT , `comment` VARCHAR(255) NULL DEFAULT NULL , `id_user` INT NULL DEFAULT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `run_cosmo_datasets` ADD FOREIGN KEY (`id_user`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
    "ALTER TABLE `run_cosmo` ADD `id_dataset` INT NULL DEFAULT NULL AFTER `id`;",
    "ALTER TABLE `run_cosmo` ADD FOREIGN KEY (`id_dataset`) REFERENCES `run_cosmo_datasets`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;"
);