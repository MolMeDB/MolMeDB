<?php 

/**
 * Version 1.19
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "CREATE TABLE `substance_pair_groups` ( `id` INT NOT NULL AUTO_INCREMENT , `type` TINYINT NOT NULL , `adjustment` VARCHAR(512) NOT NULL , `datetime` DATETIME NULL DEFAULT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `substance_pair_groups` ADD `total_pairs` INT NULL DEFAULT NULL AFTER `adjustment`;",

    "CREATE TABLE `substance_pair_group_types` ( `id` INT NOT NULL AUTO_INCREMENT , `id_group` INT NOT NULL , `id_membrane` INT NULL DEFAULT NULL , `id_method` INT NULL DEFAULT NULL , `charge` VARCHAR(512) NULL DEFAULT NULL , `id_target` INT NULL DEFAULT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `substance_pair_group_types` ADD FOREIGN KEY (`id_group`) REFERENCES `substance_pair_groups`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
    "ALTER TABLE `substance_pair_group_types` ADD FOREIGN KEY (`id_membrane`) REFERENCES `membranes`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
    "ALTER TABLE `substance_pair_group_types` ADD FOREIGN KEY (`id_method`) REFERENCES `methods`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
    "ALTER TABLE `substance_pair_group_types` ADD FOREIGN KEY (`id_target`) REFERENCES `transporter_targets`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",

    "ALTER TABLE `substance_pair_group_types` ADD `stats` JSON NULL DEFAULT NULL AFTER `id_target`;",

    "ALTER TABLE `substance_fragmentation_pairs` ADD `id_group` INT NULL DEFAULT NULL AFTER `id_core`;",
    "ALTER TABLE `substance_fragmentation_pairs` ADD FOREIGN KEY (`id_group`) REFERENCES `substance_pair_groups`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;"
);