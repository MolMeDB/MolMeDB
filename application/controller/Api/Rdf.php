<?php

/**
 * API HANDLER for RDF requests
 */
class ApiRdf extends ApiController
{   
    /**
     * Defines allowed data format
     * 
     * @var array
     */
    private static $method_allowed_types = array
    (
        HeaderParser::RDF_XML,
        HeaderParser::HTML,
        HeaderParser::NTriples, 
        HeaderParser::NQuads, 
        HeaderParser::Turtle, 
        HeaderParser::TSV, 
        HeaderParser::TriG, 
        HeaderParser::CSV
    );

    // TODO - Add "allowed" param - e.g. @allowed XML

    /**
     * Returns MolMeDB vocabulary file
     * 
     * @GET
     * @Path(/vocabulary)
     * 
     */
    public function get_vocabulary()
    {
        // TODO
        // TEMPORARY SOLUTION
        $filepath = MEDIA_ROOT . "files/rdf/vocabulary.owl";
        $file = Files::instance()->get_by_type(Files::T_RDF_VOCABULARY);

        if(!$file->id && file_exists($filepath))
        {
            $file->path = $filepath;
            $file->name = 'vocabulary';
            $file->type = Files::T_RDF_VOCABULARY;
            $file->save();
        }

        if(!$file->id)
        {
            ResponseBuilder::not_found('File not found.');
        }

        $this->responses[HeaderParser::XML] = file_get_contents($file->path);
    }

    /**
     * Returns ...
     * 
     * @GET
     * @param @required $id
     * 
     * @Path(/compound/<id:^MM[0-9]{5,}>)
     */
    public function compound($id)
    {
        // Check compound id
        $substance = Substances::instance()->where('identifier', $id)->get_one();

        if(!$substance->id)
        {
            ResponseBuilder::not_found('Compound not found.');
        }

        // Get requested type
        $accept_type = $this->get_preffered_accept_from_list(self::$method_allowed_types);
        $accept_type_enum = HeaderParser::get_enum_accept_type($accept_type);

        if(!$accept_type || !$accept_type_enum)
        {
            ResponseBuilder::bad_request('Invalid accept type header.');
        }

        // Redirect by type
        ResponseBuilder::see_other(Url::rdf_domain() . 'compound/' . $id . '/' . $accept_type_enum);
    }

    /**
     * Returns ...
     * 
     * @GET
     * @param @required $id
     * @param @required $type
     * 
     * @Path(/compound/<id:^MM[0-9]{5,}>/<type:\w+>)
     */
    public function compound_print($id, $type)
    {
        $suffix = $this->format_parameter($id);

        // Check if compound exists
        $substance = Substances::instance()->where('identifier', $suffix)->get_one();

        if(!$substance->id)
        {
            ResponseBuilder::not_found('Compound not found.');
        }
        
        $accept_type = HeaderParser::get_accept_type_by_enum($type);

        if(!$accept_type)
        {
            ResponseBuilder::bad_request('Invalid accept type parameter.');
        }

        $uri = 'https://identifiers.org/molmedb/' . $suffix;

        if($accept_type == HeaderParser::HTML)
        {
            $this->responses[$accept_type] = $this->make_html_response($uri);
        }
        else
        {
            $this->responses[$accept_type] = $uri;
        }
    }

    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix - Substance identifier
     * 
     * @Path(/substance/<suffix:^MM\d{5,}\w*$>)
     */
    public function substance($suffix=NULL)
    {
        $item_arr = explode('_',$suffix);
        $identifier = $item_arr[0];

        $substance = Substances::by_identifier($identifier);

        if(!$substance->id)
        {
            ResponseBuilder::not_found('Compound not found.');
        }

        // Get requested type
        $accept_type = $this->get_preffered_accept_from_list(self::$method_allowed_types);
        $accept_type_enum = HeaderParser::get_enum_accept_type($accept_type);

        if(!$accept_type || !$accept_type_enum)
        {
            ResponseBuilder::bad_request('Invalid accept type header.');
        }

        // Redirect by type
        ResponseBuilder::see_other(Url::rdf_domain() . 'substance/' . $suffix . '/' . $accept_type_enum);
    }

    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix - Substance identifier
     * @param @reqiured $type
     * 
     * @Path(/substance/<suffix:^MM\d{5,}\w*$>/<type:\w+>)
     */
    public function substance_print($suffix, $type)
    {
        $suffix = $this->format_parameter($suffix);
        
        $accept_type = HeaderParser::get_accept_type_by_enum($type);

        if(!$accept_type)
        {
            ResponseBuilder::bad_request('Invalid accept type parameter.');
        }

        $uri = Url::rdf_domain(true) . "substance/" . $suffix;

        if($accept_type == HeaderParser::HTML)
        {
            $this->responses[$accept_type] = $this->make_html_response($uri);
        }
        else
        {
            $this->responses[$accept_type] = $uri;
        }
    }

    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix - Substance identifier
     * 
     * @Path(/interaction/<suffix:^\w*int[0-9]+$>)
     */
    public function interaction_by_id($suffix=NULL)
    {
        $item_arr = explode('_',$suffix);
        $id = preg_replace("/^int/", "", end($item_arr));

        //Check item id
        $interaction = new Interactions($id);

        if(!$interaction->id)
        {
            ResponseBuilder::not_found('Requested interaction not found.');
        }

        // Get requested type
        $accept_type = $this->get_preffered_accept_from_list(self::$method_allowed_types);
        $accept_type_enum = HeaderParser::get_enum_accept_type($accept_type);

        if(!$accept_type || !$accept_type_enum)
        {
            ResponseBuilder::bad_request('Invalid accept type header.');
        }

        // Redirect by type
        ResponseBuilder::see_other(Url::rdf_domain() . 'interaction/' . $suffix . '/' . $accept_type_enum);
    }

    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix - Substance identifier
     * 
     * @Path(/interaction/<suffix:^membrane\d+$>)
     */
    public function membrane($suffix=NULL)
    {
        $item_arr = explode('_',$suffix);
        $id = preg_replace("/^membrane/", "", end($item_arr));

        //Check item id
        $membrane = new Membranes($id);

        if(!$membrane->id)
        {
            ResponseBuilder::not_found('Requested membrane not found.');
        }

        // Get requested type
        $accept_type = $this->get_preffered_accept_from_list(self::$method_allowed_types);
        $accept_type_enum = HeaderParser::get_enum_accept_type($accept_type);

        if(!$accept_type || !$accept_type_enum)
        {
            ResponseBuilder::bad_request('Invalid accept type header.');
        }

        // Redirect by type
        ResponseBuilder::see_other(Url::rdf_domain() . 'interaction/' . $suffix . '/' . $accept_type_enum);
    }

    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix - Substance identifier
     * 
     * @Path(/interaction/<suffix:^method\d+$>)
     */
    public function method($suffix=NULL)
    {
        $item_arr = explode('_',$suffix);
        $id = preg_replace("/^method/", "", end($item_arr));

        //Check item id
        $membrane = new Methods($id);

        if(!$membrane->id)
        {
            ResponseBuilder::not_found('Requested method not found.');
        }

        // Get requested type
        $accept_type = $this->get_preffered_accept_from_list(self::$method_allowed_types);
        $accept_type_enum = HeaderParser::get_enum_accept_type($accept_type);

        if(!$accept_type || !$accept_type_enum)
        {
            ResponseBuilder::bad_request('Invalid accept type header.');
        }

        // Redirect by type
        ResponseBuilder::see_other(Url::rdf_domain() . 'interaction/' . $suffix . '/' . $accept_type_enum);
    }


    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix - Substance identifier
     * @param @reqiured $type
     * 
     * @Path(/interaction/<suffix:^(method\d+|membrane\d+|\w*int[0-9]+)$>/<type:\w+>)
     */
    public function interaction_print($suffix, $type)
    {
        $suffix = $this->format_parameter($suffix);
        
        $accept_type = HeaderParser::get_accept_type_by_enum($type);

        if(!$accept_type)
        {
            ResponseBuilder::bad_request('Invalid accept type parameter.');
        }

        $uri = Url::rdf_domain() . "interaction/" . $suffix;

        if($accept_type == HeaderParser::HTML)
        {
            $this->responses[$accept_type] = $this->make_html_response($uri);
        }
        else
        {
            $this->responses[$accept_type] = $uri;
        }
    }

    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix - Transporter identifier
     * 
     * @Path(/transporter/<suffix:^\w*tra[0-9]+$>)
     */
    public function transporter($suffix=NULL)
    {
        $item_arr = explode('_',$suffix);
        $id = preg_replace("/^tra/", "", end($item_arr));

        // Check transporter id
        $transporter = new Transporters($id);

        if(!$transporter->id)
        {
            ResponseBuilder::not_found('Transporter interaction not found.');
        }

        // Get requested type
        $accept_type = $this->get_preffered_accept_from_list(self::$method_allowed_types);
        $accept_type_enum = HeaderParser::get_enum_accept_type($accept_type);

        if(!$accept_type || !$accept_type_enum)
        {
            ResponseBuilder::bad_request('Invalid accept type header.');
        }

        // Redirect by type
        ResponseBuilder::see_other(Url::rdf_domain() . 'transporter/' . $suffix . '/' . $accept_type_enum);
    }


    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix - Substance identifier
     * @param @reqiured $type
     * 
     * @Path(/transporter/<suffix:^\w*tra[0-9]+>/<type:\w+>)
     */
    public function transporter_print($suffix, $type)
    {
        $suffix = $this->format_parameter($suffix);
        
        $accept_type = HeaderParser::get_accept_type_by_enum($type);

        if(!$accept_type)
        {
            ResponseBuilder::bad_request('Invalid accept type parameter.');
        }

        $uri = Url::rdf_domain(true) . "transporter/" . $suffix;

        if($accept_type == HeaderParser::HTML)
        {
            $this->responses[$accept_type] = $this->make_html_response($uri);
        }
        else
        {
            $this->responses[$accept_type] = $uri;
        }
    }

    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix - Reference id
     * 
     * @Path(/reference/<suffix:^ref\d+$>)
     */
    public function reference($suffix=NULL)
    {
        // Check compound id
        $id = substr($suffix, 3);
        $publication = Publications::instance()->where('id', $id)->get_one();

        if(!$publication->id || !is_numeric($id) || (strlen($id) > 1 && $id[0] == '0') || substr($suffix,0,3) != 'ref')
        {
            ResponseBuilder::not_found('Reference not found.');
        }

        // Get requested type
        $accept_type = $this->get_preffered_accept_from_list(self::$method_allowed_types);
        $accept_type_enum = HeaderParser::get_enum_accept_type($accept_type);

        if(!$accept_type || !$accept_type_enum)
        {
            ResponseBuilder::bad_request('Invalid accept type header.');
        }

        // Redirect by type
        ResponseBuilder::see_other(Url::rdf_domain() . 'reference/' . $suffix . '/' . $accept_type_enum);
    }


    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix - Reference id
     * @param @reqiured $type
     * 
     * @Path(/reference/<suffix:^ref\d+$>/<type:\w+>)
     */
    public function reference_print($suffix, $type)
    {
        $suffix = $this->format_parameter($suffix);
        
        $accept_type = HeaderParser::get_accept_type_by_enum($type);

        if(!$accept_type)
        {
            ResponseBuilder::bad_request('Invalid accept type parameter.');
        }

        $uri = Url::rdf_domain(true) . "reference/" . $suffix;

        if($accept_type == HeaderParser::HTML)
        {
            $this->responses[$accept_type] = $this->make_html_response($uri);
        }
        else
        {
            $this->responses[$accept_type] = $uri;
        }
    }


    /**
     * Changes parameter to final format
     * 
     * @param string $param
     * 
     * @return string
     */
    private function format_parameter($param)
    {
        if(preg_match('/^MM[0-9]+/i', $param))
        {
            $param = preg_replace('/^MM/i', 'MM', $param);
        }

        if(preg_match('/^MM[0-9]+_(.*)/', $param, $matches))
        {
            if(count($matches) == 2)
            {
                $t = strtolower($matches[1]);
                $param = preg_replace('/^(MM[0-9]+_)(.*)/', "$1".$t, $param);
            }
        }

        return $param;
    }


    /**
     * Makes final html representation
     * 
     * @return string 
     */
    private function make_html_response($uri)
    {
        $deref = new Rdf();
        $out_trips = $deref->html_sparql($uri, True);
        $in_trips = $deref->html_sparql($uri, False);

        $view = new View('rdfDeref');
        $view->uri = $uri;
        $view->out = $out_trips;
        $view->in = $in_trips;

        return $view->render(FALSE);
    }
}