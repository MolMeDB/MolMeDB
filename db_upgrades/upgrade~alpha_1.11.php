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
   "ALTER TABLE `substances`
      DROP `validated`,
      DROP `prev_validation_state`,
      DROP `waiting`,
      DROP `invalid_structure_flag`;",
   
   /////



   "SELECT * 
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
       )"
);