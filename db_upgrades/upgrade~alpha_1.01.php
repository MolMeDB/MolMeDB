<?php 

/**
 * New publications table structure
 */

/**
 * Version 1.01 release
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    'ALTER TABLE `publications` CHANGE `reference` `citation` TEXT CHARACTER SET utf8 COLLATE utf8_general_ci;',
    'ALTER TABLE `publications` DROP `description`;',
    'ALTER TABLE `publications` ADD `pmid` INT NULL AFTER `doi`, ADD `title` TEXT NULL AFTER `pmid`, ADD `authors` TEXT NULL AFTER `title`, ADD `journal` TEXT NULL AFTER `authors`, ADD `page` VARCHAR(50) NULL AFTER `journal`, ADD `year` INT NULL AFTER `page`, ADD `publicated_date` DATE NULL AFTER `year`;',
    'ALTER TABLE `publications` DROP `editDateTime`;',
    'ALTER TABLE `publications` ADD `volume` VARCHAR(50) NULL AFTER `journal`, ADD `issue` VARCHAR(50) NULL AFTER `volume`;',
    'UPDATE publications SET doi = NULL WHERE doi = ""',
    'ALTER TABLE `publications` ADD UNIQUE (`doi`);',
    'ALTER TABLE `publications` ADD UNIQUE (`pmid`);',
);
