<?php

use EasyRdf\Http\Response;

/**
 * Internal API class for processing request 
 * related with detail loading of substances
 * 
 */
class ApiConformers extends ApiController
{
    ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ################################################################################
    ############################### PUBLIC #########################################
    ################################################################################
    ################################################################################

    ////////////////////////////////////////////////////////////////////////////////


    ##############################################################
    ########## conformers/file/<id>  #############################
    ##############################################################

    /**
     * Returns molecule conformers by specification
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @required $idfragment
     * @param @optional $idion
     * 
     * @PATH(/files/<idfragment:\d+>)
     */
    public function P_fileBySpec($idfragment, $idion = null)
    {
        if(!$idfragment)
        {
            ResponseBuilder::bad_request('Invalid parameters.');
        }

        $fileModel = new File();
        $fragment_model = new Fragments();
        $ion = new Fragment_ionized($idion);

        if(!$ion->id)
        {
            // Just return potential file structure
            $this->responses = array
            (
                HeaderParser::JSON => [array
                (
                    'name' => 'empty',
                    'charge' => 0,
                    'folder' => str_replace(File::FOLDER_CONFORMERS . "/", "", $fileModel->prepare_conformer_folder($idfragment)),
                    'content' => 'empty'
                )]
            );
            return;
        }

        $path = $fileModel->prepare_conformer_folder($idfragment, $idion);

        if(!is_dir($path))
        {
            ResponseBuilder::not_found('Folder not found.');
        }

        $files = array_filter(scandir($path), function($a){return preg_match('/\.sdf$/', $a);});
        $charge = $fragment_model->get_charge($ion->smiles);

        $result = [];
        foreach($files as $f)
        {
            $result[] = array
            (
                'name' => $f,
                'charge' => $charge,
                'folder' => str_replace(File::FOLDER_CONFORMERS . "/", "", $path),
                'content' => file_get_contents($path . $f)
            );
        }

        $this->responses = array
        (
            HeaderParser::JSON => $result
        );
    }

    /**
     * Checks, if COSMO results exists for given conformers
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @required $idfragment
     * @param @required $idion
     * 
     * @PATH(/cosmo_results_exists/<idfragment:\d+>/<idion:\d+>)
     */
    public function cosmo_results_exists($idfragment, $idion)
    {
        if(!$idfragment || !$idion)
        {
            ResponseBuilder::bad_request('Invalid parameters.');
        }

        $fileModel = new File();

        $path = $fileModel->prepare_conformer_folder($idfragment, $idion);

        if(!is_dir($path))
        {
            ResponseBuilder::not_found('Folder not found.');
        }

        $files = array_filter(scandir($path), function($a){return preg_match('/\.sdf$/', $a);});

        $result = [];
        foreach($files as $f)
        {
            $result[] = array
            (
                'name' => $f,
                'folder' => str_replace(File::FOLDER_CONFORMERS . "/", "", $path),
                'content' => file_get_contents($path . $f)
            );
        }

        $this->responses = array
        (
            HeaderParser::JSON => $result
        );
    }
}