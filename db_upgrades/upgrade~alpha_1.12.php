<?php 

/**
 * Validator improvement
 * 
 * Version 1.12
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "CREATE TABLE `fragments_options` (
      `id` int(11) NOT NULL,
      `id_parent` int(11) NOT NULL,
      `id_child` int(11) NOT NULL,
      `deletions` varchar(100) CHARACTER SET utf8 DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;",

    "ALTER TABLE `fragments_options`
    ADD PRIMARY KEY (`id`),
    ADD KEY `id_parent` (`id_parent`),
    ADD KEY `id_child` (`id_child`);",

    "ALTER TABLE `fragments_options`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;",

    "ALTER TABLE `fragments_options`
    ADD CONSTRAINT `fragments_options_ibfk_1` FOREIGN KEY (`id_parent`) REFERENCES `fragments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fragments_options_ibfk_2` FOREIGN KEY (`id_child`) REFERENCES `fragments` (`id`);",

    "ALTER TABLE `substances_fragments` ADD `similarity` FLOAT NULL DEFAULT NULL AFTER `order_number`;",
    "ALTER TABLE `substances` ADD `fingerprint` VARCHAR(512) NULL DEFAULT NULL AFTER `SMILES`;",
    "ALTER TABLE `substances` ADD INDEX (`fingerprint`);",

    "DELETE FROM config WHERE attribute IN ('scheduler_revalidate_3d_structures','scheduler_revalidate_3d_structures_last_id', 'scheduler_revalidate_3d_structures_is_running')",

    
);