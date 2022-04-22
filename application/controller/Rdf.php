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
     * get html representation
     */
    public function compound($suffix=NULL)
    {
        $uri = "http://identifiers.org/molmedb/" . $suffix;
        print "<h2>$uri</h2>";
        html_sparql($uri, True);
        html_sparql($uri, False);
        die;
    }

    public function substance_attr($suffix=NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/substance/" . $suffix;
        print "<h2>$uri</h2>";
        html_sparql($uri, True);
        html_sparql($uri, False);
        die;
    }

    public function membrane_interaction($suffix=NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/interaction/" . $suffix;
        print "<h2>$uri</h2>";
        html_sparql($uri, True);
        html_sparql($uri, False);
        die;
    }

    public function transporter_interaction($suffix=NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/transporter/" . $suffix;
        print "<h2>$uri</h2>";
        html_sparql($uri, True);
        html_sparql($uri, False);
        die;
    }

    public function reference($suffix=NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/reference/" . $suffix;
        print "<h2>$uri</h2>";
        html_sparql($uri, True);
        html_sparql($uri, False);
        die;
    }

    /**
     * get N-triples file
     */
    public function nt_compound($suffix = NULL)
    {
        $uri = "http://identifiers.org/molmedb/" . $suffix;
        $resstring = nt_sparql($uri, True) . nt_sparql($uri, False);
        $filename = "mmdbsub_$suffix.nt";
        rdf_publish($filename, $resstring, "application/n-triples");
        die;
    }

    public function nt_substance_attr($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/substance/" . $suffix;
        $resstring = nt_sparql($uri, True) . nt_sparql($uri, False);
        $filename = "mmdbsub_$suffix.nt";
        rdf_publish($filename, $resstring, "application/n-triples");
        die;
    }

    public function nt_membrane_interaction($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/interaction/" . $suffix;
        $resstring = nt_sparql($uri, True) . nt_sparql($uri, False);
        $filename = "mmdbint_$suffix.nt";
        rdf_publish($filename, $resstring, "application/n-triples");
        die;
    }

    public function nt_transporter_interaction($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/transporter/" . $suffix;
        $resstring = nt_sparql($uri, True) . nt_sparql($uri, False);
        $filename = "mmdbtra_$suffix.nt";
        rdf_publish($filename, $resstring, "application/n-triples");
        die;
    }

    public function nt_reference($suffix = NULL)
    {
        $uri = "http://identifiers.org/reference/" . $suffix;
        $resstring = nt_sparql($uri, True) . nt_sparql($uri, False);
        $filename = "mmdbref_$suffix.nt";
        rdf_publish($filename, $resstring, "application/n-triples");
        die;
    }

    /**
     * get RDF/XML file
     */
    public function xml_compound($suffix = NULL)
    {
        $uri = "http://identifiers.org/molmedb/" . $suffix;
        $resstring = xml_sparql($uri, True) . xml_sparql($uri, False);
        $filename = "mmdbsub_$suffix.rdf";
        rdf_publish($filename, $resstring, "application/rdf+xml");
        die;
    }

    public function xml_substance_attr($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/substance/" . $suffix;
        $resstring = xml_sparql($uri, True) . xml_sparql($uri, False);
        $filename = "mmdbsub_$suffix.rdf";
        rdf_publish($filename, $resstring, "application/rdf+xml");
        die;
    }

    public function xml_membrane_interaction($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/interaction/" . $suffix;
        $resstring = xml_sparql($uri, True) . xml_sparql($uri, False);
        $filename = "mmdbint_$suffix.rdf";
        rdf_publish($filename, $resstring, "application/rdf+xml");
        die;
    }

    public function xml_transporter_interaction($suffix = NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/transporter/" . $suffix;
        $resstring = xml_sparql($uri, True) . xml_sparql($uri, False);
        $filename = "mmdbtra_$suffix.rdf";
        rdf_publish($filename, $resstring, "application/rdf+xml");
        die;
    }

    public function xml_reference($suffix = NULL)
    {
        $uri = "http://identifiers.org/reference/" . $suffix;
        $resstring = xml_sparql($uri, True) . xml_sparql($uri, False);
        $filename = "mmdbref_$suffix.rdf";
        rdf_publish($filename, $resstring, "application/rdf+xml");
        die;
    }

}