<?php 

/**
 * Version 1.23 - COSMO-related tables
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "ALTER TABLE `users` ADD `email` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL AFTER `name`;",
    "ALTER TABLE `users` ADD `affiliation` VARCHAR(255) NULL DEFAULT NULL AFTER `email`;",
    "CREATE TABLE `user_verification` (`id` INT NOT NULL AUTO_INCREMENT , `id_user` INT NOT NULL , `token` VARCHAR(255) NOT NULL , `approved` TINYINT NOT NULL , `validity_date` DATETIME NOT NULL , `create_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `user_verification` ADD FOREIGN KEY (`id_user`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
    "ALTER TABLE `user_verification` ADD `total_sent` TINYINT NOT NULL DEFAULT '1' AFTER `token`;",
    "ALTER TABLE `user_verification` ADD `last_sent_date` DATETIME NOT NULL AFTER `validity_date`;",

    "CREATE TABLE `messages` (`id` INT NOT NULL AUTO_INCREMENT , `name` VARCHAR(255) NOT NULL , `email_text` TEXT NOT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `messages` CHANGE `name` `name` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL;",
    "ALTER TABLE `messages` CHANGE `email_text` `email_text` TEXT CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL;",
    "ALTER TABLE `messages` ADD `type` TINYINT NULL DEFAULT NULL AFTER `id`;",
    "ALTER TABLE `messages` ADD `email_subject` VARCHAR(255) NOT NULL AFTER `name`;",

    // Messages
    "INSERT INTO `messages` (`id`, `type`, `name`, `email_subject`, `email_text`) VALUES
    (DEFAULT, 1, 'VALIDATION WELCOME MESSAGE', 'MolMeDB: Registration', '<p>Dear researcher,</p>\n<p>Your MolMeDB registration was successful.</p>\n<p>We are glad that you have decided to use our services. To complete your registration, you must confirm your email by clicking on the link below. After confirming your email address, you will be able to log in to your account.</p>\n<p>Your account information is as follows:<br/>Login={login}<br/>Password={password}</p>\n<p>Store access information safely!</p>\n<p>Email validation:<br/>\n{validation_url}</p>\n<p>MolMeDB Team</p>\n'),
    (DEFAULT, 2, 'NOTIFY_NEW_COSMO_DATASET', 'MolMeDB: Lab', '<p>Dear researcher,</p>\n<p>We are pleased to inform you that your dataset has been successfully submitted to the MolMeDB Lab. Rest assured, we will notify you upon the completion of the computation process.</p>\n<p>Before completion, you have the option to download the dataset along with any interactions that have been computed thus far. To monitor the progress of the computation, please visit the <a href=\"{lab_dataset_url}\">MolMeDB Lab → My Datasets</a> section within the MolMeDB application.</p>\n<p>To directly access your dataset, use the following link:</p>\n<p>{dataset_url}</p>\n<p>MolMeDB Team</p>\n'),
    (DEFAULT, 3, 'NOTIFY_DONE_COSMO_DATASET', 'MolMeDB: Lab', '<p>Dear researcher,</p>\n<p>We are delighted to announce that your dataset has been processed and is now available for download on MolMeDB Lab.</p><p>To directly access your dataset, use the following link:<br/>{dataset_url}</p>\n<p>Here\'s a brief overview of the dataset:<br/>Total interactions: {total}<br/># computed: {done}<br/># errors: {error} </p>\n<p>In cases where certain interactions weren\'t computed, the issue often lies in conformation challenges. We advise reviewing the SMILES of the molecules that encountered errors. If everything seems correct, it\'s possible that our tool may have produced inappropriate conformers that couldn\'t be optimized. Rest assured, we are continuously refining our tools to enhance accuracy and efficiency. Although it might take some additional time to accurately process your molecule interactions, we appreciate your patience and understanding.</p>\n\n<p>MolMeDB Team</p>\n'),
    (DEFAULT, 4, 'WELCOME_MESSAGE_ADMIN_NOTIFY', 'MolMeDB: New registration', '<p>Dear administrator,</p>\n<p>new user has been registered to the MolMeDB Lab.</p>\n<p>Account details:<br/>Email: {email}<br/>Affiliation: {aff}</p>\n<p>MolMeDB service</p>\n'),
    (DEFAULT, 5, 'COSMO_RUN_STATS', 'MolMeDB: Cosmo stats', '<p>MolMeDB administrator,</p>\r\n<p>we are providing some info about last cosmo runs ({type})</p>\r\n<p>Total runs: {total}<br/>Completed: {completed}<br/>Errors: {errors}</p>\r\n<p>MolMeDB service</p>\r\n');",

    "ALTER TABLE `user_verification` ADD UNIQUE (`token`);",
    "ALTER TABLE `user_verification` CHANGE `last_sent_date` `last_sent_date` DATETIME NULL DEFAULT NULL;",

    "CREATE TABLE `run_cosmo_cosmo_datasets` (`id_run_cosmo` INT NOT NULL , `id_cosmo_dataset` INT NOT NULL ) ENGINE = InnoDB;",
    "ALTER TABLE `run_cosmo_cosmo_datasets` ADD FOREIGN KEY (`id_cosmo_dataset`) REFERENCES `run_cosmo_datasets`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;", 
    "ALTER TABLE `run_cosmo_cosmo_datasets` ADD FOREIGN KEY (`id_run_cosmo`) REFERENCES `run_cosmo`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;",
    "INSERT INTO run_cosmo_cosmo_datasets SELECT id, id_dataset FROM run_cosmo;",  
    "ALTER TABLE run_cosmo DROP FOREIGN KEY run_cosmo_ibfk_3;",
    "ALTER TABLE `run_cosmo` DROP `id_dataset`;",

    "ALTER TABLE `user_verification` ADD `id_email` INT NULL DEFAULT NULL AFTER `id_user`;",
    "ALTER TABLE `user_verification` ADD FOREIGN KEY (`id_email`) REFERENCES `email_queue`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",

    "ALTER TABLE `run_cosmo_datasets` ADD `token` VARCHAR(120) NULL DEFAULT NULL AFTER `comment`;"
);
