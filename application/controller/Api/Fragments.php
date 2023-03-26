<?php

/**
 * Internal API class for processing request 
 * related with detail loading of substances
 * 
 */
class ApiFragments extends ApiController
{

    /**
     * Returns structure of given molecule
     * 
     * @GET
     * @public
     * 
     * @param @required $id
     * 
     * @PATH(/structure)
     */
    public function structure($id)
    {
        $s = new Fragments($id);

        if(!$s->id)
        {
            ResponseBuilder::not_found('Fragment not found.');
        }

        $this->responses = array
        (
            HeaderParser::HTML => "<div>" . Html::image_structure($s->smiles) . "<div>INP: " . $s->smiles . "</div></div>"
        );
    }
}