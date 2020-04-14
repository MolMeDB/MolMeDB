<?php 

/**
 * Added config table
 */

/**
 * Version 1.02 release
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    'CREATE TABLE `config` ( `id` INT NOT NULL AUTO_INCREMENT , `attribute` VARCHAR(150) NOT NULL , `value` TEXT NOT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;',
    'ALTER TABLE `config` ADD UNIQUE (`attribute`);',

);
