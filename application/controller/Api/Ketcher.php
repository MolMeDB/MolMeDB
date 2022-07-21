<?php

/**
 * Ketcher app
 */
class ApiKetcher extends ApiController
{
    /**
     * Runs ketcher app
     * 
     * @GET
     * @public
     * 
     * @Path(/run)
     */
    public function run()
    {
        $this->view = new View('ketcher/ketcher');

        $this->responses = [
            HeaderParser::HTML => $this->view->render(FALSE)
        ];
    }
}