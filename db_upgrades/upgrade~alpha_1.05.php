<?php 

/**
 * Issue 20 - CRON SERVICE
 */

/**
 * Version 1.05 release
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    // Get ready for data validators
    'UPDATE `substances` SET `validated` = 0 WHERE 1;',
    'UPDATE `interaction` SET `validated` = 0 WHERE 1;',

    
);
