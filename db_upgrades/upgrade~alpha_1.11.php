<?php 

/**
 * Validator improvement
 * 
 * Version 1.11
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
   ///// FRAGMENTS TABLES /////////
   "CREATE TABLE `fragments` (
      `id` int(11) NOT NULL,
      `smiles` text CHARACTER SET utf8 NOT NULL
    )",
   "ALTER TABLE `fragments`
      ADD PRIMARY KEY (`id`);",

   "ALTER TABLE `fragments`
      MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;",

   "CREATE TABLE `substances_fragments` (
      `id` int(11) NOT NULL,
      `id_substance` int(11) NOT NULL,
      `id_fragment` int(11) NOT NULL,
      `links` varchar(255) DEFAULT NULL,
      `order_number` smallint(6) NOT NULL,
      `datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",

    "ALTER TABLE `substances_fragments`
    ADD PRIMARY KEY (`id`),
    ADD KEY `id_fragment` (`id_fragment`),
    ADD KEY `id_substance` (`id_substance`,`order_number`);",

    "ALTER TABLE `substances_fragments`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;",
    
    "ALTER TABLE `substances_fragments`
    ADD CONSTRAINT `substances_fragments_ibfk_1` FOREIGN KEY (`id_fragment`) REFERENCES `fragments` (`id`),
    ADD CONSTRAINT `substances_fragments_ibfk_2` FOREIGN KEY (`id_substance`) REFERENCES `substances` (`id`);",

   //// VALIDATOR
   "DROP TABLE validations",

   "CREATE TABLE `validator_identifiers` (
      `id` int(11) NOT NULL,
      `id_substance` int(11) NOT NULL,
      `id_source` int(11) DEFAULT NULL,
      `server` tinyint(4) DEFAULT NULL,
      `identifier` tinyint(4) NOT NULL,
      `value` text CHARACTER SET utf8 NOT NULL,
      `id_user` int(11) DEFAULT NULL COMMENT 'Author',
      `state` tinyint(4) NOT NULL DEFAULT '0',
      `active` tinyint(4) NOT NULL DEFAULT '0',
      `flag` tinyint(4) DEFAULT NULL,
      `create_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",

    "ALTER TABLE `validator_identifiers`
    ADD PRIMARY KEY (`id`),
    ADD KEY `id_substance` (`id_substance`),
    ADD KEY `id_source` (`id_source`),
    ADD KEY `id_user` (`id_user`),
    ADD KEY `identifier` (`identifier`),
    ADD KEY `server` (`server`);",

    "ALTER TABLE `validator_identifiers`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;",

    "ALTER TABLE `validator_identifiers`
    ADD CONSTRAINT `validator_identifiers_ibfk_1` FOREIGN KEY (`id_substance`) REFERENCES `substances` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `validator_identifiers_ibfk_2` FOREIGN KEY (`id_source`) REFERENCES `validator_identifiers` (`id`),
    ADD CONSTRAINT `validator_identifiers_ibfk_4` FOREIGN KEY (`id_user`) REFERENCES `users` (`id`);",

    "CREATE TABLE `validator_identifiers_changes` (
      `id` int(11) NOT NULL,
      `id_old` int(11) DEFAULT NULL,
      `id_new` int(11) DEFAULT NULL,
      `id_user` int(11) DEFAULT NULL,
      `message` text CHARACTER SET utf8,
      `datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",

    "ALTER TABLE `validator_identifiers_changes`
    ADD PRIMARY KEY (`id`),
    ADD KEY `id_record_1` (`id_old`),
    ADD KEY `id_record_2` (`id_new`),
    ADD KEY `id_user` (`id_user`);",

    "ALTER TABLE `validator_identifiers_changes`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;",

    "ALTER TABLE `validator_identifiers_changes`
    ADD CONSTRAINT `validator_identifiers_changes_ibfk_1` FOREIGN KEY (`id_old`) REFERENCES `validator_identifiers` (`id`),
    ADD CONSTRAINT `validator_identifiers_changes_ibfk_2` FOREIGN KEY (`id_new`) REFERENCES `validator_identifiers` (`id`),
    ADD CONSTRAINT `validator_identifiers_changes_ibfk_3` FOREIGN KEY (`id_user`) REFERENCES `users` (`id`);",

    "CREATE TABLE `validator_identifiers_duplicities` (
      `id` int(11) NOT NULL,
      `id_validator_identifier_1` int(11) NOT NULL,
      `id_validator_identifier_2` int(11) NOT NULL,
      `state` tinyint(4) NOT NULL,
      `id_user` int(11) DEFAULT NULL COMMENT 'Who changed state',
      `datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",

    "ALTER TABLE `validator_identifiers_duplicities`
    ADD PRIMARY KEY (`id`),
    ADD KEY `id_user` (`id_user`),
    ADD KEY `id_validator_identifier_1` (`id_validator_identifier_1`),
    ADD KEY `id_validator_identifier_2` (`id_validator_identifier_2`);",

    "ALTER TABLE `validator_identifiers_duplicities`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;",

    "ALTER TABLE `validator_identifiers_duplicities`
    ADD CONSTRAINT `validator_identifiers_duplicities_ibfk_1` FOREIGN KEY (`id_user`) REFERENCES `users` (`id`),
    ADD CONSTRAINT `validator_identifiers_duplicities_ibfk_2` FOREIGN KEY (`id_validator_identifier_1`) REFERENCES `validator_identifiers` (`id`),
    ADD CONSTRAINT `validator_identifiers_duplicities_ibfk_3` FOREIGN KEY (`id_validator_identifier_2`) REFERENCES `validator_identifiers` (`id`);",

    "CREATE TABLE `validator_identifiers_logs` (
      `id` int(11) NOT NULL,
      `id_substance` int(11) NOT NULL,
      `type` tinyint(4) NOT NULL,
      `state` tinyint(4) NOT NULL,
      `error_text` text CHARACTER SET utf8,
      `error_count` int(11) DEFAULT NULL,
      `datetime` datetime DEFAULT CURRENT_TIMESTAMP
    )",

    "ALTER TABLE `validator_identifiers_logs`
    ADD PRIMARY KEY (`id`),
    ADD KEY `type` (`type`),
    ADD KEY `id_substance` (`id_substance`) USING BTREE;",

    "ALTER TABLE `validator_identifiers_logs`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;",

    "ALTER TABLE `validator_identifiers_logs`
    ADD CONSTRAINT `validator_identifiers_logs_ibfk_1` FOREIGN KEY (`id_substance`) REFERENCES `substances` (`id`);",

    "CREATE TABLE `validator_identifiers_validations` (
      `id` int(11) NOT NULL,
      `id_validator_identifier` int(11) NOT NULL,
      `id_user` int(11) DEFAULT NULL,
      `state` tinyint(4) NOT NULL,
      `message` text CHARACTER SET utf8,
      `datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",

    "ALTER TABLE `validator_identifiers_validations`
    ADD PRIMARY KEY (`id`),
    ADD KEY `user_id` (`id_user`),
    ADD KEY `validator_identifiers_validations_ibfk_1` (`id_validator_identifier`);",

    "ALTER TABLE `validator_identifiers_validations`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;",

    "ALTER TABLE `validator_identifiers_validations`
    ADD CONSTRAINT `validator_identifiers_validations_ibfk_1` FOREIGN KEY (`id_validator_identifier`) REFERENCES `validator_identifiers` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `validator_identifiers_validations_ibfk_2` FOREIGN KEY (`id_user`) REFERENCES `users` (`id`);",

    "CREATE TABLE `validator_structure` (
      `id` int(11) NOT NULL,
      `id_substance` int(11) NOT NULL,
      `id_source` int(11) DEFAULT NULL,
      `server` tinyint(4) DEFAULT NULL,
      `id_user` int(11) DEFAULT NULL,
      `is_default` tinyint(4) NOT NULL DEFAULT '0',
      `description` text,
      `datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
    );",

    "ALTER TABLE `validator_structure`
    ADD PRIMARY KEY (`id`),
    ADD KEY `id_source` (`id_source`),
    ADD KEY `id_substance` (`id_substance`),
    ADD KEY `id_user` (`id_user`);",

    "ALTER TABLE `validator_structure`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;",

    "ALTER TABLE `validator_structure`
    ADD CONSTRAINT `validator_structure_ibfk_1` FOREIGN KEY (`id_source`) REFERENCES `validator_identifiers` (`id`),
    ADD CONSTRAINT `validator_structure_ibfk_2` FOREIGN KEY (`id_substance`) REFERENCES `substances` (`id`),
    ADD CONSTRAINT `validator_structure_ibfk_3` FOREIGN KEY (`id_user`) REFERENCES `users` (`id`);",


   "CREATE VIEW validator_identifiers_logs_newest AS 
   SELECT * 
   FROM validator_identifiers_logs
   WHERE id IN (
          SELECT max_id
          FROM (
               SELECT id, id_substance, MAX(id) as max_id
               FROM validator_identifiers_logs
               WHERE type = 1
               GROUP BY id_substance
               
               UNION 
               
               SELECT id, id_substance, MAX(id) as max_id
               FROM validator_identifiers_logs
               WHERE type = 2
               GROUP BY id_substance
               
               UNION 
               
               SELECT id, id_substance, MAX(id) as max_id
               FROM validator_identifiers_logs
               WHERE type = 3
               GROUP BY id_substance
               UNION 
               
               SELECT id, id_substance, MAX(id) as max_id
               FROM validator_identifiers_logs
               WHERE type = 4
               GROUP BY id_substance
               UNION 
               
               SELECT id, id_substance, MAX(id) as max_id
               FROM validator_identifiers_logs
               WHERE type = 5
               GROUP BY id_substance
               UNION 
               
               SELECT id, id_substance, MAX(id) as max_id
               FROM validator_identifiers_logs
               WHERE type = 6
               GROUP BY id_substance
               UNION 
               
               SELECT id, id_substance, MAX(id) as max_id
               FROM validator_identifiers_logs
               WHERE type = 7
               GROUP BY id_substance
          ) as t
       )",

    "CREATE TABLE `files` ( `id` INT NOT NULL AUTO_INCREMENT , `name` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL , `comment` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL , `mime` VARCHAR(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL , `size` BIGINT(20) NULL , `hash` BINARY(20) NOT NULL , `path` VARCHAR(255) NOT NULL , `id_user` INT NULL DEFAULT NULL , `id_validator_structure` INT NULL DEFAULT NULL , `datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    "ALTER TABLE `files` ADD FOREIGN KEY (`id_user`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
    "ALTER TABLE `files` ADD FOREIGN KEY (`id_validator_structure`) REFERENCES `validator_structure`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;",
);