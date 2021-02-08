<?php 

/**
 * Stats improvement
 * Version 1.08
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
   "CREATE TABLE `stats` ( `id` INT NOT NULL AUTO_INCREMENT , `type` SMALLINT NOT NULL , `content` TEXT NULL DEFAULT NULL , `update_date` DATE NOT NULL DEFAULT CURRENT_TIMESTAMP , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
   "ALTER TABLE `stats` ADD UNIQUE (`type`);"
);
