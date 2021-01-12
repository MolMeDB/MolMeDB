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

    "INSERT INTO `config` (`id`, `attribute`, `value`) VALUES
    (1, 'europepmc_uri', 'https://www.ebi.ac.uk/europepmc/webservices/rest/'),
    (2, 'rdkit_uri', 'http://molmedb.upol.cz:9696/'),
    (3, 'db_drugbank_pattern', '/^DB\\d+$/'),
    (4, 'db_pdb_pattern', '/^[a-zA-Z\\d]{4}$/'),
    (5, 'db_pubchem_pattern', '');",
);
