<?php 

/**
 * Scheduler edit
 * Version 1.07
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
   "ALTER TABLE `substances` DROP INDEX `UNIQUE_subst_pubchem`;",
   "ALTER TABLE `substances` DROP INDEX `UNIQUE_subst_drugbank`;",
   "ALTER TABLE `substances` DROP INDEX `UNIQUE_subst_chEBI`;",
   "ALTER TABLE `substances` DROP INDEX `UNIQUE_subst_pdb`;",
   "ALTER TABLE `substances` DROP INDEX `UNIQUE_subst_chEMBL`;",
   "ALTER TABLE `substances` ADD `waiting` TINYINT NULL DEFAULT NULL AFTER `prev_validation_state`;",
   "ALTER TABLE `substances` ADD `invalid_structure_flag` TINYINT NULL DEFAULT NULL AFTER `waiting`;",
   "ALTER TABLE `scheduler_errors` ADD `id_user` INT NULL DEFAULT NULL AFTER `last_update`;",
   "ALTER TABLE `scheduler_errors` ADD FOREIGN KEY (`id_user`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
   "ALTER TABLE `log_scheduler` ADD `type` TINYINT NULL DEFAULT NULL AFTER `id`;",
   "ALTER TABLE `config` CHANGE `value` `value` TEXT CHARACTER SET utf8 COLLATE utf8_czech_ci NULL;",
   // Remove all DB LOG_P VALUES - will be overwriten by rdkit service logP
   "UPDATE substances SET LogP = NULL;",
   "ALTER TABLE `transporters` ADD `note` TEXT CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL AFTER `type`;",
   "INSERT INTO `config` (`id`, `attribute`, `value`) VALUES
      (DEFAULT, 'db_chembl_pattern', '/^CHEMBL\\d+$/'),
      (DEFAULT, 'db_chebi_pattern', '/^\\d+$/');",
   "ALTER TABLE `validations` DROP FOREIGN KEY `validations_ibfk_1`;",
   "ALTER TABLE `validations` ADD CONSTRAINT `validations_ibfk_1` FOREIGN KEY (`id_substance_1`) REFERENCES `substances`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;",
   "ALTER TABLE `validations` DROP FOREIGN KEY `validations_ibfk_2`;",
   "ALTER TABLE `validations` ADD CONSTRAINT `validations_ibfk_2` FOREIGN KEY (`id_substance_2`) REFERENCES `substances`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;"
);
