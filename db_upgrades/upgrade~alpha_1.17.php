<?php 

/**
 * Version 1.17
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    // ADD TABLE
    "CREATE TABLE `substance_fragmentation_pair_links` (
        `id` int(11) NOT NULL,
        `id_substance_pair` int(11) NOT NULL,
        `type` tinyint(4) NOT NULL,
        `id_substance_1_fragment` int(11) DEFAULT NULL,
        `id_substance_2_fragment` int(11) DEFAULT NULL,
        `id_core` int(11) DEFAULT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8;",

      "ALTER TABLE `substance_fragmentation_pair_links` ADD PRIMARY KEY (`id`);",
      "ALTER TABLE `substance_fragmentation_pair_links` ADD KEY `id_core` (`id_core`);",
      "ALTER TABLE `substance_fragmentation_pair_links` ADD KEY `id_substance_1_fragment` (`id_substance_1_fragment`);",
      "ALTER TABLE `substance_fragmentation_pair_links` ADD KEY `id_substance_2_fragment` (`id_substance_2_fragment`);",
      "ALTER TABLE `substance_fragmentation_pair_links` ADD KEY `substance_fragmentation_pair_links_ibfk_2` (`id_substance_pair`);",

      "ALTER TABLE `substance_fragmentation_pair_links` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;",

      "ALTER TABLE `substance_fragmentation_pair_links` ADD CONSTRAINT `substance_fragmentation_pair_links_ibfk_1` FOREIGN KEY (`id_core`) REFERENCES `fragments` (`id`);",
      "ALTER TABLE `substance_fragmentation_pair_links` ADD CONSTRAINT `substance_fragmentation_pair_links_ibfk_2` FOREIGN KEY (`id_substance_pair`) REFERENCES `substance_fragmentation_pairs` (`id`) ON DELETE CASCADE;",
      "ALTER TABLE `substance_fragmentation_pair_links` ADD CONSTRAINT `substance_fragmentation_pair_links_ibfk_3` FOREIGN KEY (`id_substance_1_fragment`) REFERENCES `substances_fragments` (`id`);",
      "ALTER TABLE `substance_fragmentation_pair_links` ADD CONSTRAINT `substance_fragmentation_pair_links_ibfk_4` FOREIGN KEY (`id_substance_2_fragment`) REFERENCES `substances_fragments` (`id`);"
);