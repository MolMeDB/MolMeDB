<?php

/**
 * API HANDLER for RDF requests
 */
class ApiRdf extends ApiController
{   
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
     * @Path(/compound)
     */
    public function compound($id=NULL)
    {
        $id = strtoupper($id);

        $uri = "http://identifiers.org/molmedb/" . $id;
        $this->dereference_response($uri);
    }

    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix
     * 
     * @Path(/substance)
     */
    public function substance($suffix=NULL)
    {
        $suffix = $this->format_parameter($suffix);

        $uri = Url::rdf_domain() . "substance/" . $suffix;
        $this->dereference_response($uri);
    }

    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix
     * 
     * @Path(/interaction)
     */
    public function interaction($suffix=NULL)
    {
        $suffix = $this->format_parameter($suffix);

        $uri = Url::rdf_domain() . "interaction/" . $suffix;
        $this->dereference_response($uri);
    }

    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix
     * 
     * @Path(/transporter)
     */
    public function transporter($suffix=NULL)
    {
        $suffix = $this->format_parameter($suffix);

        $uri = Url::rdf_domain() . "transporter/" . $suffix;
        $this->dereference_response($uri);
    }

    /**
     * Returns ...
     * 
     * @GET
     * @param @required $suffix
     * 
     * @Path(/reference)
     */
    public function reference($suffix=NULL)
    {
        $suffix = $this->format_parameter($suffix);
        
        $uri = Url::rdf_domain() . "reference/" . $suffix;
        $this->dereference_response($uri);
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
    private function make_view($uri)
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
            HeaderParser::HTML => $this->make_view($uri),
            HeaderParser::RDF_XML => $uri,
            HeaderParser::NTriples => $uri,
            HeaderParser::NQuads => $uri,
            HeaderParser::Turtle => $uri,
            HeaderParser::TSV => $uri,
            HeaderParser::TriG => $uri,
            HeaderParser::RDF_CSV => $uri
        ];
    }

}