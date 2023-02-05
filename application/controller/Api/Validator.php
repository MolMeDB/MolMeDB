<?php

class Validator extends ApiController
{
    /**
     * @POST
     *
     * @param int $substance_id_1
     * @param int $substance_id_2
     * @param int $state
     *
     * @Path(/validator/resolve_duplicity)
     *
     * @throws \ApiException|\DbException
     */
    public function resolve_duplicity(
        int $substance_id_1,
        int $substance_id_2,
        int $state
    ): void {
        if (
            $state !== Validator_identifier_duplicities::STATE_DUPLICITY_LEFT
            ||
            $state !== Validator_identifier_duplicities::STATE_DUPLICITY_RIGHT
        ) {
            throw new \ApiException("Provided state is invalid.");
        }

        $res = $this->queryOne('
            SELECT *
            FROM validator_identifiers_duplicities vid
            WHERE vid.id_validator_identifier_1 = ? AND vid.id_validator_identifier_2 = ?
        ', array($substance_id_1, $substance_id_2,));

        if (!$res->id) {
            throw new \ApiException("No duplicities found for given substances.");
        }

        Db::instance()->queryOne('
            UPDATE validator_identifiers_duplicities 
            SET state = ?, progress = ? 
            WHERE validator_identifiers_duplicities.id = ?; 
        ', array($state, Validator_identifier_duplicities::PROGRESS_PENDING, $res->id));

        ResponseBuilder::ok_no_content();
    }
}