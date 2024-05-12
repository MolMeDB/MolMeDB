<?php 

/**
 * Version 1.25 - COSMO-related tables
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "ALTER TABLE `substances` CHANGE `user_id` `user_id` INT(11) NULL DEFAULT NULL;",
    "UPDATE `methods` SET `type` = '3' WHERE `methods`.`name` LIKE 'CCM18';",
    "UPDATE `publications` SET `type` = '3' WHERE `publications`.`citation` LIKE 'in-house calculations';",
    "ALTER TABLE `interaction` ADD `id_fragment_ion` INT NULL DEFAULT NULL AFTER `charge`;",
    "ALTER TABLE `interaction` ADD FOREIGN KEY (`id_fragment_ion`) REFERENCES `fragments_ionized`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
    "UPDATE `fragments_ionized` SET `cosmo_flag` = '3' WHERE `cosmo_flag` IN (3, 4);"
);
