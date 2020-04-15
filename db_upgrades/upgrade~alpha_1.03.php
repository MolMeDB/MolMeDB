<?php 

/**
 * Issue 7 - Distinguish between primary and secondary citations
 */

/**
 * Version 1.03 release
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    // Issue 7
    'ALTER TABLE `interaction` CHANGE `id_reference` `id_reference` INT(11) NULL DEFAULT NULL COMMENT "primary citation"',
    'DROP TRIGGER IF EXISTS `ds_AU`;CREATE DEFINER=`root`@`localhost` TRIGGER `ds_AU` AFTER UPDATE ON `datasets` FOR EACH ROW BEGIN UPDATE interaction SET visibility = NEW.visibility, id_membrane = NEW.id_membrane, id_method = NEW.id_method WHERE id_dataset = NEW.id; END',
    'ALTER TABLE `datasets` CHANGE `id_publication` `id_publication` INT(11) NULL DEFAULT NULL COMMENT "secondary publictiaon";',
    'ALTER TABLE `publications` ADD `pattern` TEXT NULL DEFAULT NULL AFTER `publicated_date`;',
    ''
);
