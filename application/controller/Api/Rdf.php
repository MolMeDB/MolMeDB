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

        $uri = 'http://identifiers.org/molmedb/' . $suffix;

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
     * @Path(/substance/<suffix:\w+>)
     */
    public function substance($suffix=NULL)
    {
        // Check compound id
        // $substance = Substances::instance()->where('identifier', strtok($suffix,'_'))->get_one();

        // if(!$substance->id)
        // {
        //     ResponseBuilder::not_found('Compound not found.');
        // }

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
     * @Path(/substance/<suffix:\w+>/<type:\w+>)
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
     * @Path(/interaction/<suffix:\w+>)
     */
    public function interaction($suffix=NULL)
    {
        // $item_type = substr($suffix,0,3);
        // // Check item id
        // if($item_type == 'int')
        // {
        //     $item = Interactions::instance()->where('id', strtok(substr($suffix,3),'_'))->get_one();
        // }
        // elseif($item_type == 'mem')
        // {
        //     $item = Membranes::instance()->where('id', substr($suffix,8))->get_one();
        // }
        // else
        // {
        //     $item = Methods::instance()->where('id', substr($suffix,6))->get_one();
        // }

        // if(!$item->id)
        // {
        //     ResponseBuilder::not_found('item not found.');
        // }

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
     * @Path(/interaction/<suffix:\w+>/<type:\w+>)
     */
    public function interaction_print($suffix, $type)
    {
        $suffix = $this->format_parameter($suffix);
        
        $accept_type = HeaderParser::get_accept_type_by_enum($type);

        if(!$accept_type)
        {
            ResponseBuilder::bad_request('Invalid accept type parameter.');
        }

        $uri = Url::rdf_domain(true) . "interaction/" . $suffix;

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
     * @Path(/transporter/<suffix:\w+>)
     */
    public function transporter($suffix=NULL)
    {
        // Check compound id
        // $transporter = Transporters::instance()->where('id', strtok(substr($suffix, 3),'_'))->get_one();

        // if(!$transporter->id)
        // {
        //     ResponseBuilder::not_found('Transporter interaction not found.');
        // }

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
     * @Path(/transporter/<suffix:\w+>/<type:\w+>)
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
     * @param @required $suffix - Substance identifier
     * 
     * @Path(/reference/<suffix:\w+>)
     */
    public function reference($suffix=NULL)
    {
        // Check compound id
        // $publication = Publications::instance()->where('id', substr($suffix, 3))->get_one();

        // if(!$publication->id)
        // {
        //     ResponseBuilder::not_found('Compound not found.');
        // }

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
     * @param @required $suffix - Substance identifier
     * @param @reqiured $type
     * 
     * @Path(/reference/<suffix:\w+>/<type:\w+>)
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


    /**
     * Creates dereference response according to Accept header
     */
    private function dereference_response($uri)
    {
        $this->responses = [
            HeaderParser::HTML => $this->make_html_response($uri),
            HeaderParser::RDF_XML => $uri,
            HeaderParser::NTriples => $uri,
            HeaderParser::NQuads => $uri,
            HeaderParser::Turtle => $uri,
            HeaderParser::TSV => $uri,
            HeaderParser::TriG => $uri,
            HeaderParser::CSV => $uri
        ];
    }
}