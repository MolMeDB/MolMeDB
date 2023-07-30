<?php 

/**
 * Version 1.20
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "ALTER TABLE `validator_identifiers` ADD `id_dataset_passive` INT NULL DEFAULT NULL AFTER `id_source`;",
    "ALTER TABLE `validator_identifiers` ADD `id_dataset_active` INT NULL DEFAULT NULL AFTER `id_dataset_passive`;",
    "ALTER TABLE `validator_identifiers` ADD FOREIGN KEY (`id_dataset_active`) REFERENCES `transporter_datasets`(`id`) ON DELETE SET NULL ON UPDATE RESTRICT;",
    "ALTER TABLE `validator_identifiers` ADD FOREIGN KEY (`id_dataset_passive`) REFERENCES `datasets`(`id`) ON DELETE SET NULL ON UPDATE RESTRICT;",
    "ALTER TABLE `interaction` DROP `validated`;",
    "ALTER TABLE `interaction` DROP `CAM`;",
    "ALTER TABLE `files` ADD `id_dataset_active` INT NULL DEFAULT NULL AFTER `id_validator_structure`, ADD `id_dataset_passive` INT NULL DEFAULT NULL AFTER `id_dataset_active`;",
    "ALTER TABLE `files` ADD FOREIGN KEY (`id_dataset_active`) REFERENCES `transporter_datasets`(`id`) ON DELETE SET NULL ON UPDATE RESTRICT;",
    "ALTER TABLE `files` ADD FOREIGN KEY (`id_dataset_passive`) REFERENCES `datasets`(`id`) ON DELETE SET NULL ON UPDATE RESTRICT;",

    "CREATE TABLE `upload_queue` ( `id` INT NOT NULL AUTO_INCREMENT , `id_dataset_passive` INT NULL , `id_dataset_active` INT NULL , `state` TINYINT NOT NULL , `id_user` INT NULL , `settings` JSON NULL DEFAULT NULL , `id_file` INT NULL , `create_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `upload_queue` ADD FOREIGN KEY (`id_dataset_active`) REFERENCES `transporter_datasets`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
    "ALTER TABLE `upload_queue` ADD FOREIGN KEY (`id_dataset_passive`) REFERENCES `datasets`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
    "ALTER TABLE `upload_queue` ADD FOREIGN KEY (`id_file`) REFERENCES `files`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
    "ALTER TABLE `upload_queue` ADD FOREIGN KEY (`id_user`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
    'ALTER TABLE `upload_queue` ADD `run_info` JSON NULL DEFAULT NULL AFTER `id_file`;',
    "ALTER TABLE `upload_queue` ADD `type` TINYINT NULL DEFAULT NULL AFTER `id`;",
);