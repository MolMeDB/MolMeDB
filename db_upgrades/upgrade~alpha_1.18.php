<?php 

/**
 * Version 1.18
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    "DELETE FROM `substances_fragments` WHERE id_substance IN (
      SELECT id FROM `substances` WHERE `SMILES` REGEXP '\\\.')"
);