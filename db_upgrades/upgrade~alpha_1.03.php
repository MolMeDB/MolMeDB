<?php 

/**
 * Issue 7 - Distinguish between primary and secondary citations
 * Issue 11 - Storing data about transporters
 */

/**
 * Version 1.03 release
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    // Issue 7
    'ALTER TABLE `datasets` CHANGE `id_publication` `id_publication` INT(11) NULL DEFAULT NULL COMMENT "secondary publictiaon";',
    'ALTER TABLE `publications` ADD `pattern` TEXT NULL DEFAULT NULL AFTER `publicated_date`;',
    
    // Issue 11 - Transporters tables
   'CREATE TABLE `transporters` (
    `id` int(11) NOT NULL,
    `id_dataset` int(11) NOT NULL,
    `id_substance` int(11) NOT NULL,
    `id_target` int(11) NOT NULL,
    `type` int(150) NOT NULL,
    `Km` float DEFAULT NULL,
    `EC50` float DEFAULT NULL,
    `Ki` float DEFAULT NULL,
    `IC50` float DEFAULT NULL,
    `id_reference` int(11) DEFAULT NULL,
    `id_user` int(11) NOT NULL,
    `create_datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci;',

    'CREATE TABLE `transporter_datasets` (
    `id` int(11) NOT NULL,
    `visibility` int(11) NOT NULL,
    `name` varchar(255) COLLATE utf8_bin NOT NULL,
    `id_reference` int(11) NOT NULL,
    `id_user_upload` int(11) NOT NULL,
    `id_user_edit` int(11) NOT NULL,
    `update_datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `create_datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;',

    'CREATE TABLE `transporter_targets` (
    `id` int(11) NOT NULL,
    `name` varchar(150) COLLATE utf8_czech_ci NOT NULL,
    `uniprot_id` varchar(50) COLLATE utf8_czech_ci NOT NULL,
    `id_user` int(11) NOT NULL,
    `create_datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci;',

    'ALTER TABLE `transporters`
    ADD PRIMARY KEY (`id`),
    ADD KEY `substance_id` (`id_substance`),
    ADD KEY `transporter_target_id` (`id_target`),
    ADD KEY `primary_reference_id` (`id_reference`),
    ADD KEY `id_user` (`id_user`),
    ADD KEY `transporters_ibfk_6` (`id_dataset`);',

    'ALTER TABLE `transporter_datasets`
    ADD PRIMARY KEY (`id`),
    ADD KEY `id_reference` (`id_reference`),
    ADD KEY `id_user_edit` (`id_user_edit`),
    ADD KEY `id_user_upload` (`id_user_upload`),
    ADD KEY `visibility` (`visibility`);',

    'ALTER TABLE `transporter_targets`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `uniprot_id` (`uniprot_id`),
    ADD KEY `id_user` (`id_user`);',

    'ALTER TABLE `transporters`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;',

    'ALTER TABLE `transporter_datasets`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;',

    'ALTER TABLE `transporter_targets`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;',

    'ALTER TABLE `transporters`
    ADD CONSTRAINT `transporters_ibfk_1` FOREIGN KEY (`id_substance`) REFERENCES `substances` (`id`),
    ADD CONSTRAINT `transporters_ibfk_2` FOREIGN KEY (`id_target`) REFERENCES `transporter_targets` (`id`),
    ADD CONSTRAINT `transporters_ibfk_3` FOREIGN KEY (`id_reference`) REFERENCES `publications` (`id`),
    ADD CONSTRAINT `transporters_ibfk_5` FOREIGN KEY (`id_user`) REFERENCES `users` (`id`),
    ADD CONSTRAINT `transporters_ibfk_6` FOREIGN KEY (`id_dataset`) REFERENCES `transporter_datasets` (`id`) ON DELETE CASCADE;',

    'ALTER TABLE `transporter_datasets`
    ADD CONSTRAINT `transporter_datasets_ibfk_1` FOREIGN KEY (`id_reference`) REFERENCES `publications` (`id`),
    ADD CONSTRAINT `transporter_datasets_ibfk_2` FOREIGN KEY (`id_user_edit`) REFERENCES `users` (`id`),
    ADD CONSTRAINT `transporter_datasets_ibfk_3` FOREIGN KEY (`id_user_upload`) REFERENCES `users` (`id`),
    ADD CONSTRAINT `transporter_datasets_ibfk_4` FOREIGN KEY (`visibility`) REFERENCES `vis_ch` (`id`);',

    'ALTER TABLE `transporter_targets`
    ADD CONSTRAINT `transporter_targets_ibfk_1` FOREIGN KEY (`id_user`) REFERENCES `users` (`id`);'

);
