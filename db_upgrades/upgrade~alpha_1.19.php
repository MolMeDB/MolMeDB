<?php 

/**
 * Version 1.19
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "CREATE TABLE `substance_pair_groups` ( `id` INT NOT NULL AUTO_INCREMENT , `type` TINYINT NOT NULL , `adjustment` VARCHAR(512) NOT NULL , `datetime` DATETIME on update CURRENT_TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `substance_pair_groups` ADD `total_pairs` INT NULL DEFAULT NULL AFTER `adjustment`;",

    "CREATE TABLE `substance_pair_group_types` ( `id` INT NOT NULL AUTO_INCREMENT , `id_group` INT NOT NULL , `id_membrane` INT NULL DEFAULT NULL , `id_method` INT NULL DEFAULT NULL , `charge` VARCHAR(512) NULL DEFAULT NULL , `id_target` INT NULL DEFAULT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `substance_pair_group_types` ADD FOREIGN KEY (`id_group`) REFERENCES `substance_pair_groups`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
    "ALTER TABLE `substance_pair_group_types` ADD FOREIGN KEY (`id_membrane`) REFERENCES `membranes`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
    "ALTER TABLE `substance_pair_group_types` ADD FOREIGN KEY (`id_method`) REFERENCES `methods`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
    "ALTER TABLE `substance_pair_group_types` ADD FOREIGN KEY (`id_target`) REFERENCES `transporter_targets`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",

    "CREATE TABLE `substance_pair_group_type_interactions` ( `id` INT NOT NULL AUTO_INCREMENT , `id_interaction` BIGINT NULL DEFAULT NULL , `id_transporter` INT NULL DEFAULT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `substance_pair_group_type_interactions` ADD FOREIGN KEY (`id_interaction`) REFERENCES `interaction`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
    "ALTER TABLE `substance_pair_group_type_interactions` ADD FOREIGN KEY (`id_transporter`) REFERENCES `transporters`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
    "ALTER TABLE `substance_pair_group_type_interactions` ADD `id_group_type` INT NOT NULL AFTER `id`;",
    "ALTER TABLE `substance_pair_group_type_interactions` ADD FOREIGN KEY (`id_group_type`) REFERENCES `substance_pair_group_types`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",

    "ALTER TABLE `substance_pair_groups` ADD `update_datetime` DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER `datetime`;",

    "ALTER TABLE `substance_pair_group_type_interactions` CHANGE `id_interaction` `id_interaction_1` BIGINT(20) NULL DEFAULT NULL, CHANGE `id_transporter` `id_transporter_1` INT(11) NULL DEFAULT NULL;",
    "ALTER TABLE `substance_pair_group_type_interactions` ADD `id_interaction_2` BIGINT NULL DEFAULT NULL AFTER `id_interaction_1`;",
    "ALTER TABLE `substance_pair_group_type_interactions` ADD `id_transporter_2` INT NULL DEFAULT NULL AFTER `id_transporter_1`;",
    "ALTER TABLE `substance_pair_group_type_interactions` ADD FOREIGN KEY (`id_interaction_2`) REFERENCES `interaction`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
    "ALTER TABLE `substance_pair_group_type_interactions` ADD FOREIGN KEY (`id_transporter_2`) REFERENCES `transporters`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",

    "ALTER TABLE `substance_pair_group_types` ADD `stats` JSON NULL DEFAULT NULL AFTER `id_target`;",
);