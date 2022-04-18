<?php
/*SPARQL*/
function ask_sparql($uri, $out)
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


class SparqltestController extends Controller 
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function compound($suffix=NULL)
    {
        $uri = "http://identifiers.org/molmedb/" . $suffix;
        print "<h2>$uri</h2>";
        ask_sparql($uri, True);
        ask_sparql($uri, False);
        die;
    }

    public function substance_atr($suffix=NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/substance/" . $suffix;
        print "<h2>$uri</h2>";
        ask_sparql($uri, True);
        ask_sparql($uri, False);
        die;
    }

    public function membrane_interaction($suffix=NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/interaction/" . $suffix;
        print "<h2>$uri</h2>";
        ask_sparql($uri, True);
        ask_sparql($uri, False);
        die;
    }

    public function transporter_interaction($suffix=NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/transporter/" . $suffix;
        print "<h2>$uri</h2>";
        ask_sparql($uri, True);
        ask_sparql($uri, False);
        die;
    }

    public function reference($suffix=NULL)
    {
        $uri = "http://rdf.molmedb.upol.cz/reference/" . $suffix;
        print "<h2>$uri</h2>";
        ask_sparql($uri, True);
        ask_sparql($uri, False);
        die;
    }

}