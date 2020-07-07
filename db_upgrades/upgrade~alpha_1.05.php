<?php 

/**
 * Issue 20 - CRON SERVICE
 */

/**
 * Version 1.05 release
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    // Get ready for data validators
    'UPDATE `substances` SET `validated` = 0, `LogP` = NULL WHERE 1;',
    'UPDATE `interaction` SET `validated` = 0 WHERE 1;',
    'ALTER TABLE `validations`
        DROP `name`,
        DROP `alter_name`,
        DROP `MW`,
        DROP `SMILES`;',
    'ALTER TABLE `validations` ADD `duplicity` TEXT NOT NULL AFTER `id_substance_2`;',
    'UPDATE `substances` SET validated = 0',
    'ALTER TABLE `membranes` ADD `type` INT NULL DEFAULT NULL AFTER `id`;',
    'ALTER TABLE `membranes` ADD UNIQUE (`type`);',
    'ALTER TABLE `methods` ADD `type` INT NULL DEFAULT NULL AFTER `id`;',
    'ALTER TABLE `methods` ADD UNIQUE (`type`);',
    'ALTER TABLE `datasets` ADD `type` INT NULL DEFAULT NULL AFTER `id`;',
    'ALTER TABLE `datasets` ADD UNIQUE (`type`);',
    'ALTER TABLE `publications` ADD `type` INT NULL DEFAULT NULL AFTER `id`;',
    'ALTER TABLE `publications` ADD UNIQUE (`type`);',
    'ALTER TABLE `datasets` CHANGE `id_user_edit` `id_user_edit` INT(11) NULL;',
    // Add default LogP Membrane
    "INSERT INTO `membranes` (`id`, `type`, `name`, `description`, `references`, `keywords`, `CAM`, `idTag`, `user_id`, `createDateTime`, `editDateTime`) VALUES (NULL, '1', '1-octanol', NULL, NULL, NULL, NULL, NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);",
    // Add default LogP methods
    "INSERT INTO `methods` (`id`, `type`, `name`, `description`, `references`, `keywords`, `CAM`, `idTag`, `user_id`, `createDateTime`, `editDateTime`) VALUES (NULL, '1', 'Pubchem Method', NULL, NULL, NULL, NULL, NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);",
    "INSERT INTO `methods` (`id`, `type`, `name`, `description`, `references`, `keywords`, `CAM`, `idTag`, `user_id`, `createDateTime`, `editDateTime`) VALUES (NULL, '2', 'ChEMBL Method', NULL, NULL, NULL, NULL, NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);",
    // Add default publications
    "INSERT INTO `publications` (`id`, `type`, `citation`, `doi`, `pmid`, `title`, `authors`, `journal`, `volume`, `issue`, `page`, `year`, `publicated_date`, `pattern`, `user_id`, `createDateTime`) VALUES (NULL, '1', 'PUBCHEM', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, CURRENT_TIMESTAMP);",
    "INSERT INTO `publications` (`id`, `type`, `citation`, `doi`, `pmid`, `title`, `authors`, `journal`, `volume`, `issue`, `page`, `year`, `publicated_date`, `pattern`, `user_id`, `createDateTime`) VALUES (NULL, '2', 'ChEMBL', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, CURRENT_TIMESTAMP);",
    // Add default LogP datasets
    "INSERT INTO `datasets`(`id`, `type`, `visibility`, `name`, `id_membrane`, `id_method`, `id_publication`, `id_user_upload`, `id_user_edit`, `lastUpdateDatetime`, `createDateTime`) 
        SELECT DISTINCT NULL, '1', '1', 'PUBCHEM', mem.id, met.id, p.id, NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        FROM membranes mem
        LEFT JOIN methods met ON 1
        LEFT JOIN publications p ON 1
        WHERE mem.type = 1 AND met.type = 1 and p.type = 1",
    "INSERT INTO `datasets`(`id`, `type`, `visibility`, `name`, `id_membrane`, `id_method`, `id_publication`, `id_user_upload`, `id_user_edit`, `lastUpdateDatetime`, `createDateTime`) 
        SELECT DISTINCT NULL, '2', '1', 'ChEMBL', mem.id, met.id, p.id, NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        FROM membranes mem
        LEFT JOIN methods met ON 1
        LEFT JOIN publications p ON 1
        WHERE mem.type = 1 AND met.type = 2 and p.type = 2",

    "ALTER TABLE `substances` CHANGE `SMILES` `SMILES` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL;",
    
    "CREATE TABLE `log_scheduler` ( `id` INT NOT NULL AUTO_INCREMENT , `datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP , `error_count` INT NULL DEFAULT NULL , `success_count` INT NULL DEFAULT NULL , `report_path` VARCHAR(255) NULL DEFAULT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;"
    
);
