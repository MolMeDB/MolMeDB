<?php
/**
 * RDF Controller
 * 
 * @author Dominik Martinat
 */

require __DIR__ . "../../libraries/Sparqllib.php";

class RdfController extends Controller 
{

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }


    /**
     * get vocabulary file
     */
    public function vocabulary()
    {
        $file = __DIR__."/../../media/files/rdf/vocabulary.owl";
        //nejsem si jisty hlavickama na response
        header("Content-Type: application/rdf+xml");
        header("Content-Length: " . filesize($file));
        header("Content-Disposition: inline; filename=" . basename($file));
        readfile($file);
        die;
    }

    /**
     * make html representation
     */
    public function make_view($suffix, $uri)
    {
        $deref = new UriDeref();
        $out_trips = $deref->html_sparql($uri, True);
        $in_trips = $deref->html_sparql($uri, False);

        $this->title = $suffix;
        $this->view = new View('rdfDeref');
        $this->view->uri = $uri;
        $this->view->n_out = count($out_trips);
        $this->view->out = $out_trips;
        $this->view->n_in = count($in_trips);
        $this->view->in = $in_trips;
    }

    public function compound($suffix=NULL)
    {
        $uri = "http://identifiers.org/molmedb/" . $suffix;
        $this->make_view($suffix, $uri);
        
    }
    
    public function substance($suffix=NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/substance/" . $suffix;
        $this->make_view($suffix, $uri);
    }

    public function interaction($suffix=NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/interaction/" . $suffix;
        $this->make_view($suffix, $uri);
    }

    public function transporter($suffix=NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/transporter/" . $suffix;
        $this->make_view($suffix, $uri);
    }

    public function reference($suffix=NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/reference/" . $suffix;
        $this->make_view($suffix, $uri);
    }

    /**
     * get N-triples file
     */
    public function nt_compound($suffix = NULL)
    {
        $uri = "http://identifiers.org/molmedb/" . $suffix;
        $deref = new UriDeref();
        $resstring = $deref->nt_sparql($uri, True) . $deref->nt_sparql($uri, False);
        $filename = "mmdbsub_$suffix.nt";
        $deref->rdf_publish($filename, $resstring, "application/n-triples");
        die;
    }

    public function nt_substance_attr($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/substance/" . $suffix;
        $deref = new UriDeref();
        $resstring = $deref->nt_sparql($uri, True) . $deref->nt_sparql($uri, False);
        $filename = "mmdbsub_$suffix.nt";
        $deref->rdf_publish($filename, $resstring, "application/n-triples");
        die;
    }

    public function nt_membrane_interaction($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/interaction/" . $suffix;
        $deref = new UriDeref();
        $resstring = $deref->nt_sparql($uri, True) . $deref->nt_sparql($uri, False);
        $filename = "mmdbint_$suffix.nt";
        $deref->rdf_publish($filename, $resstring, "application/n-triples");
        die;
    }

    public function nt_transporter_interaction($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/transporter/" . $suffix;
        $deref = new UriDeref();
        $resstring = $deref->nt_sparql($uri, True) . $deref->nt_sparql($uri, False);
        $filename = "mmdbtra_$suffix.nt";
        $deref->rdf_publish($filename, $resstring, "application/n-triples");
        die;
    }

    public function nt_reference($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/reference/" . $suffix;
        $deref = new UriDeref();
        $resstring = $deref->nt_sparql($uri, True) . $deref->nt_sparql($uri, False);
        $filename = "mmdbref_$suffix.nt";
        $deref->rdf_publish($filename, $resstring, "application/n-triples");
        die;
    }

    /**
     * get RDF/XML file
     */
    public function xml_compound($suffix = NULL)
    {
        $uri = "http://identifiers.org/molmedb/" . $suffix;
        $deref = new UriDeref();
        $resstring = $deref->xml_sparql($uri, True) . $deref->xml_sparql($uri, False);
        $filename = "mmdbsub_$suffix.rdf";
        $deref->rdf_publish($filename, $resstring, "application/rdf+xml");
        die;
    }

    public function xml_substance_attr($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/substance/" . $suffix;
        $deref = new UriDeref();
        $resstring = $deref->xml_sparql($uri, True) . $deref->xml_sparql($uri, False);
        $filename = "mmdbsub_$suffix.rdf";
        $deref->rdf_publish($filename, $resstring, "application/rdf+xml");
        die;
    }

    public function xml_membrane_interaction($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/interaction/" . $suffix;
        $deref = new UriDeref();
        $resstring = $deref->xml_sparql($uri, True) . $deref->xml_sparql($uri, False);
        $filename = "mmdbint_$suffix.rdf";
        $deref->rdf_publish($filename, $resstring, "application/rdf+xml");
        die;
    }

    public function xml_transporter_interaction($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/transporter/" . $suffix;
        $deref = new UriDeref();
        $resstring = $deref->xml_sparql($uri, True) . $deref->xml_sparql($uri, False);
        $filename = "mmdbtra_$suffix.rdf";
        $deref->rdf_publish($filename, $resstring, "application/rdf+xml");
        die;
    }

    public function xml_reference($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/reference/" . $suffix;
        $deref = new UriDeref();
        $resstring = $deref->xml_sparql($uri, True) . $deref->xml_sparql($uri, False);
        $filename = "mmdbref_$suffix.rdf";
        $deref->rdf_publish($filename, $resstring, "application/rdf+xml");
        die;
    }
}