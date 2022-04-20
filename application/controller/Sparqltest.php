<?php
/******* 
 * 
 * get HTML string from SPARQL
 * 
*/



function html_sparql($uri, $out)
{
    require_once __DIR__ . "../../libraries/sparqllib.php" ;
    $db = sparql_connect( "https://idsm.elixir-czech.cz/sparql/endpoint/molmedb" );
    if( !$db ) { print sparql_errno() . ": " . sparql_error(). "\n"; exit; }

    if( $out )
    {
        $sparql = "SELECT DISTINCT * WHERE { <$uri> ?predicate ?object }";
    }
    else
    {
        $sparql = "SELECT DISTINCT ?predicate ?subject WHERE { ?subject ?predicate <$uri> }";
    }
    $result = sparql_query( $sparql ); 
    if( !$result ) { print sparql_errno() . ": " . sparql_error(). "\n"; exit; }

    $fields = sparql_field_array( $result );
    if( $out )
    {
        print "<h3>outgoing triples</h3>";
        print "<p>Number of outgoing triples: ".sparql_num_rows( $result )." results.</p>";       
    }
    else
    {
        print "<h3>incoming triples</h3>";
        print "<p>Number of incoming triples: ".sparql_num_rows( $result )." results.</p>";  
    }
    print "<table style='width:100%'>";
    print "<tr>";
    foreach( $fields as $field )
    {
        print "<th>$field</th>";
    }
    print "</tr>";
    while( $row = sparql_fetch_array( $result ) )
    {
        print "<tr>";
        foreach( $fields as $field )
        {
            if (substr( $row[$field], 0, 4 ) === "http")
            {
                print "<td><a href='$row[$field]'>$row[$field]</a></td>";
            }
            else
            {
                print "<td>$row[$field]</td>"; 
            }
        }
        print "</tr>";
    }
    print "</table>";


}

/******* 
 * 
 * get string of rdf triples and object types from SPARQL
 * 
*/
function rdf_sparql($uri, $out)
{
    require_once __DIR__ . "../../libraries/sparqllib.php" ;
    $db = sparql_connect( "https://idsm.elixir-czech.cz/sparql/endpoint/molmedb" );
    if( !$db ) { print sparql_errno() . ": " . sparql_error(). "\n"; exit; }

    if( $out )
    {
        $sparql = "SELECT DISTINCT ?subject ?predicate ?object ?type WHERE { <$uri> ?predicate ?object 
            BIND(datatype(?object) AS ?type) BIND(<$uri> AS ?subject) }";
    }
    else
    {
        $sparql = "SELECT DISTINCT ?subject ?predicate ?object WHERE { ?subject ?predicate <$uri>
            BIND(<$uri> AS ?object) }";
    }
    $result = sparql_query( $sparql );
    return $result;
}

/******* 
 * 
 * transform string of rdf triples and object types into N-triples format
 * 
*/
function nt_sparql($uri, $out)
{ 
    $result = rdf_sparql($uri, $out);
    if( !$result ) { print sparql_errno() . ": " . sparql_error(). "\n"; exit; }

    $fields = sparql_field_array( $result );
    $resstring = "";
    while( $row = sparql_fetch_array( $result ) )
    {
        foreach( $fields as $field )
        {
            if (array_key_exists($field, $row))
            {
                if($field == "type")
                {
                    $resstring = $resstring . "^^<$row[$field]> ";
                }
                elseif (substr( $row[$field], 0, 4 ) === "http")
                {
                    $resstring = $resstring . "<$row[$field]> ";
                }
                else
                {
                    $resstring = $resstring . '"' . $row[$field] . '"';
                }
            }
            else
            {
                continue;
            }
        }
        $resstring = $resstring . ".\n";
    }
    return $resstring;
}
/**
*takes URI and shortens inti CURI
*/
function shorten_uri($uri)
{
    $namespace = array(
        "http://www.w3.org/1999/02/22-rdf-syntax-ns" => "rdf:",
        "http://www.bioassayontology.org/bao" => "bao:",
        "http://purl.org/spar/cito/" => "cito:",
        "http://www.w3.org/2000/01/rdf-schema" => "rdfs:",
        "https://w3id.org/reproduceme" => "repr:",
        "http://purl.org/dc/elements/1.1" => "dc:",
        "http://purl.org/dc/terms" => "dcterms:",
        "http://semanticscience.org/resource" => "sio:",
        "http://purl.org/ontology/bibo" => "bibo:",
        "http://purl.obolibrary.org/obo" => "obo:",
        "http://www.w3.org/2004/02/skos/core" => "skos:",
    );
    if (strrpos($uri,"#"))
    {
        $splituri = explode("#",$uri);
        $prefix = $splituri[0];
        $localname = $splituri[1];
    }
    else
    {
    $splituri = explode("/",$uri);
    $localname = array_pop($splituri);
    $prefix = implode('/',$splituri);
    }
    $curi = $namespace[$prefix] . $localname;
    return $curi;
}

/******* 
 * 
 * transform string of rdf triples and object types into RDF/XML format
 * 
*/
function xml_sparql($uri, $out)
{ 

    $result = rdf_sparql($uri, $out);
    if( !$result ) { print sparql_errno() . ": " . sparql_error(). "\n"; exit; }

    //$fields = sparql_field_array( $result );
    $resstring = "";
    if ($out)
    {//neumim delat XML tohle mmi pridje prasacky
        $resstring .= '<?xml version="1.0" encoding="utf-8"?>
<rdf:RDF
        xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
        xmlns:bao="http://www.bioassayontology.org/bao#"
        xmlns:cito="http://purl.org/spar/cito/"
        xmlns:rdfs="http://www.w3.org/2000/01/rdf-schema#"
        xmlns:repr="https://w3id.org/reproduceme#"
        xmlns:dc="http://purl.org/dc/elements/1.1/"
        xmlns:dcterms="http://purl.org/dc/terms/"
        xmlns:sio="http://semanticscience.org/resource/"
        xmlns:bibo="http://purl.org/ontology/bibo/"
        xmlns:obo="http://purl.obolibrary.org/obo/"
        xmlns:skos="http://www.w3.org/2004/02/skos/core#" >
  <rdf:Description rdf:about="' . $uri . '">
';
    

        while( $row = sparql_fetch_array( $result ) )
        {
            $cpred = shorten_uri($row["predicate"]);
            
            if (substr( $row["object"], 0, 4 ) === "http")
            {
                $resstring .= '    <' . $cpred . ' rdf:resource="' . $row["object"] . '" />
';
            }
            else
            {
                $resstring .= '    <' . $cpred;
                if (array_key_exists("type", $row))
                {
                    $resstring .= ' rdf:datatype="' . $row["type"] . '"';
                }
                $resstring .= '>' . $row["object"] . '</'. $cpred .'>
';
            }
        }
        $resstring .= '  </rdf:Description>
';
    }
    else
    {
        while( $row = sparql_fetch_array( $result ) )
        {
            $cpred = shorten_uri($row["predicate"]);
            $resstring .= '  <rdf:Description rdf:about="' . $row["subject"] . '">
';
            $resstring .= '    <' . $cpred . ' rdf:resource="' . $uri . '" />
  </rdf:Description>
';
        }
        $resstring .= "</rdf:RDF>";
    }
    return $resstring;
}

/******* 
 * 
 * create and publish rdf file in selected mime format
 * 
*/
function rdf_publish($filename, $resstring, $mime)
{
    $filepath = "media/files/rdf/$filename";
    $file = fopen($filepath,"w") or die("Unable to open file!");
    fwrite($file,$resstring);
    fclose($file);
    //nejsem si jisty hlavickama
    header("Content-Type: " . $mime);
    header("Content-Length: " . filesize($filepath));
    header('Content-Disposition: inline; filename=' . basename($filepath));
    readfile($filepath);
    unlink($filepath);
}

class SparqltestController extends Controller 
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
        //timhle si nejsem jisty
        $file = __DIR__."/../../media/files/rdf/vocabulary.owl";
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