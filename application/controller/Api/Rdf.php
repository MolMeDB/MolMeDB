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
        
        $this->responses = [
            HeaderParser::HTML => $this->make_view($uri)
        ];
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

        $this->responses = [
            HeaderParser::HTML => $this->make_view($uri)
        ];
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

        $this->responses = [
            HeaderParser::HTML => $this->make_view($uri)
        ];
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

        $this->responses = [
            HeaderParser::HTML => $this->make_view($uri)
        ];
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

        $this->responses = [
            HeaderParser::HTML => $this->make_view($uri)
        ];
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
     * get N-triples file
     */
    // public function nt_compound($suffix = NULL)
    // {
    //     $uri = "http://identifiers.org/molmedb/" . $suffix;
    //     $deref = new Rdf();
    //     $resstring = $deref->nt_sparql($uri, True) . $deref->nt_sparql($uri, False);
    //     $filename = "mmdbsub_$suffix.nt";
    //     $deref->rdf_publish($filename, $resstring, "application/n-triples");
    //     die;
    // }

    // public function nt_substance_attr($suffix = NULL)
    // {
    //     $uri = "http://rdf.molmedb.upol.cz/substance/" . $suffix;
    //     $deref = new Rdf();
    //     $resstring = $deref->nt_sparql($uri, True) . $deref->nt_sparql($uri, False);
    //     $filename = "mmdbsub_$suffix.nt";
    //     $deref->rdf_publish($filename, $resstring, "application/n-triples");
    //     die;
    // }

    // public function nt_membrane_interaction($suffix = NULL)
    // {
    //     $uri = "http://rdf.molmedb.upol.cz/interaction/" . $suffix;
    //     $deref = new Rdf();
    //     $resstring = $deref->nt_sparql($uri, True) . $deref->nt_sparql($uri, False);
    //     $filename = "mmdbint_$suffix.nt";
    //     $deref->rdf_publish($filename, $resstring, "application/n-triples");
    //     die;
    // }

    // public function nt_transporter_interaction($suffix = NULL)
    // {
    //     $uri = "http://rdf.molmedb.upol.cz/transporter/" . $suffix;
    //     $deref = new Rdf();
    //     $resstring = $deref->nt_sparql($uri, True) . $deref->nt_sparql($uri, False);
    //     $filename = "mmdbtra_$suffix.nt";
    //     $deref->rdf_publish($filename, $resstring, "application/n-triples");
    //     die;
    // }

    // public function nt_reference($suffix = NULL)
    // {
    //     $uri = "http://rdf.molmedb.upol.cz/reference/" . $suffix;
    //     $deref = new Rdf();
    //     $resstring = $deref->nt_sparql($uri, True) . $deref->nt_sparql($uri, False);
    //     $filename = "mmdbref_$suffix.nt";
    //     $deref->rdf_publish($filename, $resstring, "application/n-triples");
    //     die;
    // }

    // /**
    //  * get RDF/XML file
    //  */
    // public function xml_compound($suffix = NULL)
    // {
    //     $uri = "http://identifiers.org/molmedb/" . $suffix;
    //     $deref = new Rdf();
    //     $resstring = $deref->xml_sparql($uri, True) . $deref->xml_sparql($uri, False);
    //     $filename = "mmdbsub_$suffix.rdf";
    //     $deref->rdf_publish($filename, $resstring, "application/rdf+xml");
    //     die;
    // }

    // public function xml_substance_attr($suffix = NULL)
    // {
    //     $uri = "http://rdf.molmedb.upol.cz/substance/" . $suffix;
    //     $deref = new Rdf();
    //     $resstring = $deref->xml_sparql($uri, True) . $deref->xml_sparql($uri, False);
    //     $filename = "mmdbsub_$suffix.rdf";
    //     $deref->rdf_publish($filename, $resstring, "application/rdf+xml");
    //     die;
    // }

    // public function xml_membrane_interaction($suffix = NULL)
    // {
    //     $uri = "http://rdf.molmedb.upol.cz/interaction/" . $suffix;
    //     $deref = new Rdf();
    //     $resstring = $deref->xml_sparql($uri, True) . $deref->xml_sparql($uri, False);
    //     $filename = "mmdbint_$suffix.rdf";
    //     $deref->rdf_publish($filename, $resstring, "application/rdf+xml");
    //     die;
    // }

    // public function xml_transporter_interaction($suffix = NULL)
    // {
    //     $uri = "http://rdf.molmedb.upol.cz/transporter/" . $suffix;
    //     $deref = new Rdf();
    //     $resstring = $deref->xml_sparql($uri, True) . $deref->xml_sparql($uri, False);
    //     $filename = "mmdbtra_$suffix.rdf";
    //     $deref->rdf_publish($filename, $resstring, "application/rdf+xml");
    //     die;
    // }

    // public function xml_reference($suffix = NULL)
    // {
    //     $uri = "http://rdf.molmedb.upol.cz/reference/" . $suffix;
    //     $deref = new Rdf();
    //     $resstring = $deref->xml_sparql($uri, True) . $deref->xml_sparql($uri, False);
    //     $filename = "mmdbref_$suffix.rdf";
    //     $deref->rdf_publish($filename, $resstring, "application/rdf+xml");
    //     die;
    // }


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
}