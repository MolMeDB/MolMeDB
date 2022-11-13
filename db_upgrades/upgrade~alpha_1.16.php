<?php 

/**
 * Version 1.16
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "CREATE TABLE `scheduler_runs` ( `id` INT NOT NULL AUTO_INCREMENT , `pid` INT NOT NULL , `method` VARCHAR(255) NOT NULL , `params` VARCHAR(1024) NULL DEFAULT NULL , `status` TINYINT NOT NULL , `id_exception` INT NULL DEFAULT NULL , `datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `scheduler_runs` ADD FOREIGN KEY (`id_exception`) REFERENCES `exceptions`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;",
    "ALTER TABLE `scheduler_runs` ADD `note` TEXT NULL DEFAULT NULL AFTER `id_exception`;",
    "ALTER TABLE `validator_identifiers` ADD `state_msg` TEXT NULL DEFAULT NULL AFTER `state`;",
    "ALTER TABLE `validator_identifiers` ADD `active_msg` TEXT NULL DEFAULT NULL AFTER `active`;",
);