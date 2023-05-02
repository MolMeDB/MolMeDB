<?php

/**
 * Api Validator controller
 */
class ApiValidator extends ApiController
{
    /**
     * @POST
     * @INTERNAL
     *
     * @param @required $substance_id_left
     * @param @required $substance_id_right
     * @param @required $state
     *
     * @Path(/resolve_duplicity)
     */
    public function resolve_duplicity(
        $substance_id_left,
        $substance_id_right,
        $state
    ): void {
        if (
            $state != Validator_identifier_duplicities::STATE_DUPLICITY_LEFT
            &&
            $state != Validator_identifier_duplicities::STATE_DUPLICITY_RIGHT
            &&
            $state != Validator_identifier_duplicities::STATE_NON_DUPLICITY
        ) {
            ResponseBuilder::bad_request('Provided state is invalid.');
        }

        $res = Db::instance()->queryOne('
            SELECT *
            FROM validator_identifiers_duplicities vid
            WHERE vid.id_validator_identifier_1 = ? AND vid.id_validator_identifier_2 = ?
        ', array($substance_id_left, $substance_id_right,));
        
        if (!$res->id) {
            ResponseBuilder::bad_request("No duplicities found for given substances.");
        }

        Db::instance()->queryOne('
            UPDATE validator_identifiers_duplicities 
            SET state = ?, progress = ? 
            WHERE validator_identifiers_duplicities.id = ?; 
        ', array($state, Validator_identifier_duplicities::PROGRESS_PENDING, $res->id));

        ResponseBuilder::ok_no_content();
    }
}