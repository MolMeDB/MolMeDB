<?php 

/**
 * Version 1.14
 * 
 * Fragmentation improvement
 * 
 * !!!ATTENTION!!!! - All substances' fragments will be removed!
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "CREATE TABLE `error_fragments` (
        `id` int(11) NOT NULL,
        `id_substance` int(11) DEFAULT NULL,
        `id_fragment` int(11) DEFAULT NULL,
        `type` tinyint(4) DEFAULT NULL,
        `text` text,
        `datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8;",

      "ALTER TABLE `error_fragments` CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT, add PRIMARY KEY (`id`);",
      "ALTER TABLE `error_fragments` ADD FOREIGN KEY (`id_fragment`) REFERENCES `fragments`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;",
      "ALTER TABLE `error_fragments` ADD FOREIGN KEY (`id_substance`) REFERENCES `substances`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;",

      // Erase all fragments and start 
      "TRUNCATE `fragments_options`;",
      "TRUNCATE `substances_fragments`;",

      "ALTER TABLE `fragments` CHANGE `smiles` `smiles` VARCHAR(1024) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL;",
      "ALTER TABLE `fragments` DROP INDEX `smiles`;",
      "ALTER TABLE `fragments` ADD UNIQUE (`smiles`(1024));"
);