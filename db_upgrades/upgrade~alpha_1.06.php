<?php 

/**
 * Adding columns with accuracy to the transporter interaction table
 * Version 1.04 release
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
   "ALTER TABLE `transporters` ADD `Km_acc` FLOAT UNSIGNED NULL DEFAULT NULL AFTER `Km`;",
   "ALTER TABLE `transporters` ADD `EC50_acc` FLOAT UNSIGNED NULL DEFAULT NULL AFTER `EC50`;",
   "ALTER TABLE `transporters` ADD `Ki_acc` FLOAT UNSIGNED NULL DEFAULT NULL AFTER `Ki`;",
   "ALTER TABLE `transporters` ADD `IC50_acc` FLOAT UNSIGNED NULL DEFAULT NULL AFTER `IC50`;",

   // Trim text fields
   "UPDATE `substances` SET `name` = TRIM(`name`), `SMILES` = TRIM(`SMILES`), `inchikey`= TRIM(`inchikey`);"
);
