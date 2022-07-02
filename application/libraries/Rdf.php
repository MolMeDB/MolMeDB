<?php
###############################
# Dominik Martinat 2022
#  dominiki.martinat@gmail.com
#
# Library for URI dereference for MolMeDB RDF
#  https://molmedb.upol.cz/
#
# Based on library made by:
# Christopher Gutteridge 2010
#  cjg@ecs.soton.ac.uk
#  LGPL License 
#  http://graphite.ecs.soton.ac.uk/sparqllib/
#  https://github.com/cgutteridge/PHP-SPARQL-Lib
##############################
/**
 * @author Dominik Martinát <dominiki.martinat@gmail.com>
 */
class Rdf extends SparqllibBase
{
	/**
	 * IDSM database connection handler
	 * @var sparql_connection
	 */
	private $db;

	/**
	 * Class constructor
	 */
	function __construct()
	{
		$this->connect();
	}

	/**
	 * Tries to connect to IDSM server
	 */
	public function connect()
	{
		if($this->db)
		{
			return true;
		}

		$this->db = $this->sparql_connect("https://idsm.elixir-czech.cz/sparql/endpoint/molmedb");

		if(!$this->db)
		{
			throw new ApiException('Cannot connect to the idsm server.');
			// TODO Exception logging
			// $this->sparql_errno() . ": " . $this->sparql_error().
		}
	}

	/**
	 * 
	 * Get array [$size_warning, array of predicate description (url, label, nodes) with predicate compact uri as key] from SPARQL
	 * 
	 * @param string $uri
	 * @param bool $out
	 * 
	 * @return array
	 * 
	*/
	function html_sparql($uri, $out)
	{
		/** Check DB connection */
		$this->connect();

		/** Set namespace for rdfs:label */
		#$this->sparql_ns( "rdfs","http://www.w3.org/2000/01/rdf-schema#" );

		// TODO - check $uri content. !injection attack!

		/**find predicates and their possible labels */
		if( $out )
		{
			$sparql = "SELECT DISTINCT ?predicate ?url_predicate ?label WHERE 
						{
							<$uri> ?predicate ?node
							BIND(?predicate AS ?url_predicate)
							OPTIONAL {?predicate rdfs:label ?label}
						}";
		}
		else
		{
			$sparql = "SELECT DISTINCT ?predicate ?url_predicate ?label WHERE
						{
							?node ?predicate <$uri>
							BIND(?predicate as ?url_predicate)
							OPTIONAL {?predicate rdfs:label ?label}
						}";
		}
		$result = $this->sparql_query( $sparql );

	 	$pred_array = array();
	 	while($row = $this->sparql_fetch_array($result))
	 	{
	 		array_push($pred_array, $row);
	 	}
		/** for each predicate find nodes up to limit
		 * if size_limit is hit, then set size_warning to True
		 */
		$size_warning = False;
		$size_limit = 300;
		$res_array = array();
		foreach($pred_array as $pred)
		{
			$predicate = $pred["predicate"];
			if( $out )
			{
				$sparql = "SELECT DISTINCT * WHERE 
							{
								<$uri> <$predicate> ?node
								BIND(?node AS ?url_node)
								BIND(datatype(?node) AS ?type)
								OPTIONAL {?node rdfs:label ?label}
							} LIMIT $size_limit";
			}
			else
			{
				$sparql = "SELECT DISTINCT * WHERE
							{
								?node <$predicate> <$uri>
								BIND(?node AS ?url_node)
								OPTIONAL {?node rdfs:label ?label}
							} LIMIT $size_limit";
			}
			$result = $this->sparql_query( $sparql );
			if( $this->sparql_num_rows( $result ) == $size_limit ){ $size_warning = True; }

			/**prepare array of nodes */
			$node_array = array();
			while($row = $this->sparql_fetch_array($result))
			{
				$row["node"] = $this->shorten_uri($row["node"]);
				$row["url_node"] = $this->html_uri_redir($row["url_node"]);
				array_push($node_array, $row);
			}
			sort($node_array);
			/** create array with predicate description - url, label and array of nodes */
			$pred_desc_array = ["url_predicate" => $pred["url_predicate"], "nodes" => $node_array];
			if(array_key_exists("label",$pred)) {$pred_desc_array["label"] = $pred["label"];}

			/**insert description array with predicate compact uri key into result array */
			$res_array[$this->shorten_uri($pred["predicate"])] = $pred_desc_array;
		}
		return [$size_warning, $res_array];
	}

	/**
	 * Adjusts uri for redirections in DEBUG mode
	 * 
	 * @param string $uri
	 * 
	 * @return string
	 */
	function html_uri_redir($uri)
	{
		if(DEBUG_API)
		{
			return preg_replace("/rdf\.molmedb\.upol\.cz/", Url::domain() . "/api/rdf", "$uri");
		}
		return $uri;
	}

	/**	 
	 * Get array [predicate1 =>[["node"=> node1, "type" => type1],...],...]  from SPARQL
	 * 
	 * @param string $uri
	 * @param bool $out
	*/
	function rdf_sparql($uri, $out)
	{
		/** Check DB connection */
		$this->connect();
		
		/** find predicates*/
		if( $out )
		{
			$sparql = "SELECT DISTINCT ?predicate WHERE 
						{
							<$uri> ?predicate ?node
						}";
		}
		else
		{
			$sparql = "SELECT DISTINCT ?predicate WHERE
						{
							?node ?predicate <$uri>
						}";
		}
		$result = $this->sparql_query( $sparql );
		$pred_array = array();
		while($row = $this->sparql_fetch_array($result))
		{
			array_push($pred_array, $row["predicate"]);
		}


		/** fill  result array with predicate_uri=>[["node"=>node1,"type"=>node1_type]...] */
		$res_array = array();
		foreach($pred_array as $predicate)
		{
			if( $out )
			{
				$sparql = "SELECT DISTINCT * WHERE 
							{
								<$uri> <$predicate> ?node
								BIND(datatype(?node) AS ?type)
							}";
			}
			else
			{
				$sparql = "SELECT DISTINCT * WHERE
							{
								?node <$predicate> <$uri>
								BIND(datatype(?node) AS ?type)
							}";
			}
			$result = $this->sparql_query( $sparql );
			$node_array = array();
			while($row = $this->sparql_fetch_array($result))
			{
				array_push($node_array, $row);
			}
			$res_array[$predicate] = $node_array;
		}
		return $res_array;
	}


	//TODO - rework file generators to work with new rdf_sparql()
	/******* 
	 * 
	 * transform string of rdf triples and object types into N-triples format
	 * 
	*/
	function nt_sparql($uri, $out)
	{ 
		$result = $this->rdf_sparql($uri, $out); //still old!!

		if( !$result && DEBUG ) 
		{ 
			echo $this->sparql_errno() . ": " . $this->sparql_error(). "\n"; 
			exit; 
		}

		$fields = $this->sparql_field_array( $result );
		$resstring = "";
		while( $row = $this->sparql_fetch_array( $result ) )
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
	* Takes URI and shortens inti CURI
	*/
	function shorten_uri($uri)
	{
		$namespace = array(
			"w3.org/1999/02/22-rdf-syntax-ns" => "rdf:",
			"bioassayontology.org/bao" => "bao:",
			"purl.org/spar/cito" => "cito:",
			"w3.org/2000/01/rdf-schema" => "rdfs:",
			"w3id.org/reproduceme" => "repr:",
			"purl.org/dc/elements/1.1" => "dc:",
			"purl.org/dc/terms" => "dcterms:",
			"semanticscience.org/resource" => "sio:",
			"purl.org/ontology/bibo" => "bibo:",
			"purl.obolibrary.org/obo" => "obo:",
			"w3.org/2004/02/skos/core" => "skos:",
			"rdf.molmedb.upol.cz/substance" => "mmdbsub:",
			"rdf.molmedb.upol.cz/interaction" => "mmdbint:",
			"rdf.molmedb.upol.cz/transporter" => "mmdbtra:",
			"rdf.molmedb.upol.cz/reference" => "mmdbref:",
			"rdf.molmedb.upol.cz/vocabulary" => "mmdbvoc:",
			"rdf.ncbi.nlm.nih.gov/pubchem/compound" => "pubchem:",
			"rdf.ebi.ac.uk/resource/chembl/molecule" => "ebi:",
			"purl.uniprot.org/uniprot/" => "uniprot:"
		);

		$prefix = $uri;
		$suffix = '';
		
		if (strrpos($uri,"#"))
		{
			$splituri = explode("#",$uri);
			$prefix = $splituri[0];
			$suffix = $splituri[1];
		}

		// Remove unimportant prefixes
		$prefix = preg_replace('/^(http:\/\/|https:\/\/)/', '', $prefix);
		$prefix = preg_replace('/^www\./', '', $prefix);

		foreach($namespace as $ns => $sc)
		{
			if(Text::startsWith($prefix, $ns))
			{
				$ns = preg_replace('/\/+$/', '', $ns);
				$end = substr($prefix, strlen($ns)+1);
				return $sc . $end . ($suffix ? '#' . $suffix : '');
			}
		}
		
		return $uri;
	}
}




###############################
# Christopher Gutteridge 2010
#  cjg@ecs.soton.ac.uk
#  LGPL License 
#  http://graphite.ecs.soton.ac.uk/sparqllib/
#  https://github.com/cgutteridge/PHP-SPARQL-Lib
###############################

# to document: CGIParams
class SparqllibBase
{
	function sparql_connect( $endpoint ) { return new sparql_connection( $endpoint ); }

	function sparql_ns( $short, $long, $db = null ) { return $this->_sparql_a_connection( $db )->ns( $short, $long ); }
	function sparql_query( $sparql, $db = null ) { return $this->_sparql_a_connection( $db )->query( $sparql ); }
	function sparql_errno( $db = null ) { return $this->_sparql_a_connection( $db )->errno(); }
	function sparql_error( $db = null ) { return $this->_sparql_a_connection( $db )->error(); }

	function sparql_fetch_array( $result ) { return $result->fetch_array(); }
	function sparql_num_rows( $result ) { return $result->num_rows(); }
	function sparql_field_array( $result ) { return $result->field_array(); }
	function sparql_field_name( $result, $i ) { return $result->field_name( $i ); }

	function sparql_fetch_all( $result ) { return $result->fetch_all(); }

	function sparql_get( $endpoint, $sparql ) 
	{ 
		$db = $this->sparql_connect( $endpoint );
		if( !$db ) { return; }
		$result = $db->query( $sparql );
		if( !$result ) { return; }
		return $result->fetch_all(); 
	}

	function _sparql_a_connection( $db )
	{
		global $sparql_last_connection;
		if( !isset( $db ) )
		{
			if( !isset( $sparql_last_connection ) )
			{
				print( "No currect SPARQL connection (connection) in play!" );
				return;
			}
			$db = $sparql_last_connection;
		}
		return $db;
	}
}		

#	$timeout = 20;
#	$old = ini_set('default_socket_timeout', $timeout);
#	ini_set('default_socket_timeout', $old);
class sparql_connection
{
	var $db;
	var $debug = false;
	var $errno = null;
	var $error = null;
	var $ns = array();
	var $params = null;
	# capabilities are either true, false or null if not yet tested.

	function __construct( $endpoint )
	{
		$this->endpoint = $endpoint;
		global $sparql_last_connection;
		$sparql_last_connection = $this;
	}

	function ns( $short, $long )
	{
		$this->ns[$short] = $long;
	}

	function errno() { return $this->errno; }
	function error() { return $this->error; }

	function cgiParams( $params = null )
	{
		if( $params === null ) { return $this->params; }
		if( $params === "" ) { $this->params = null; return; }
		$this->params = $params;
	}

	function query( $query, $timeout=null )
	{	
		$prefixes = "";
		foreach( $this->ns as $k=>$v )
		{
			$prefixes .= "PREFIX $k: <$v>\n";
		}
		$output = $this->dispatchQuery( $prefixes.$query, $timeout );
		if( $this->errno ) { return; }
		$parser = new xx_xml($output, 'contents');
		if( $parser->error() ) 
		{ 
			$this->errno = -1; # to not clash with CURLOPT return; }
			$this->error = $parser->error();
			return;
		}
		return new sparql_result( $this, $parser->rows, $parser->fields );
	}

	function alive( $timeout=3 )
	{
		$result = $this->query( "SELECT * WHERE { ?s ?p ?o } LIMIT 1", $timeout );

		if( $this->errno ) { return false; }

		return true;
	}	

	function dispatchQuery( $sparql, $timeout=null )
	{
		$url = $this->endpoint."?query=".urlencode( $sparql );
		if( $this->params !== null )
		{
			$url .= "&".$this->params;
		}
		if( $this->debug ) { print "<div class='debug'><a href='".htmlspecialchars($url)."'>".htmlspecialchars($prefixes.$query)."</a></div>\n"; }
		$this->errno = null;
		$this->error = null;
		$ch = curl_init($url);
		#curl_setopt($ch, CURLOPT_HEADER, 1);
		if( $timeout !== null )
		{
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeout );
		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER,array (
			"Accept: application/sparql-results+xml"
		));

		$output = curl_exec($ch);      
		$info = curl_getinfo($ch);
		if(curl_errno($ch))
		{
			$this->errno = curl_errno( $ch );
			$this->error = 'Curl error: ' . curl_error($ch);
			return;
		}
		if( $output === '' )
		{
			$this->errno = "-1";
			$this->error = 'URL returned no data';
			return;
		}
		if( $info['http_code'] != 200) 
		{
			$this->errno = $info['http_code'];
			$this->error = 'Bad response, '.$info['http_code'].': '.$output;
			return;
		}
		curl_close($ch);

		return $output;
	}

	####################################
	# Endpoint Capability Testing
	####################################

	# This section is very limited right now. I plan, in time, to
	# caching so it can save results to a cache to save re-doing them 
	# and many more capability options (suggestions to cjg@ecs.soton.ac.uk)

	var $caps = array();
	var $caps_desc = array(
		"select"=>"Basic SELECT",
		"constant_as"=>"SELECT (\"foo\" AS ?bar)",
		"math_as"=>"SELECT (2+3 AS ?bar)",
		"count"=>"SELECT (COUNT(?a) AS ?n) ?b ... GROUP BY ?b",
		"max"=>"SELECT (MAX(?a) AS ?n) ?b ... GROUP BY ?b",
		"sample"=>"SELECT (SAMPLE(?a) AS ?n) ?b ... GROUP BY ?b",
		"load"=>"LOAD <...>",
	); 

	var $caps_cache;
	var $caps_anysubject;
	function capabilityCache( $filename, $dba_type='db4' )
	{
		$this->caps_cache = dba_open($filename, "c", $dba_type );
	}
	function capabilityCodes()
	{
		return array_keys( $this->caps_desc );
	}
	function capabilityDescription($code)
	{
		return $this->caps_desc[$code];
	}

	# return true if the endpoint supports a capability
	# nb. returns false if connecion isn't authoriased to use the feature, eg LOAD
	function supports( $code ) 
	{
		if( isset( $this->caps[$code] ) ) { return $this->caps[$code]; }
		$was_cached = false;
		if( isset( $this->caps_cache ) )
		{
			$CACHE_TIMEOUT_SECONDS = 7*24*60*60;
			$db_key = $this->endpoint.";".$code;
			$db_val = dba_fetch( $db_key, $this->caps_cache );
			if( $db_val !== false )
			{
				list( $result, $when ) = preg_split( '/;/', $db_val );
				if( $when + $CACHE_TIMEOUT_SECONDS > time() )
				{
					return $result;
				}
				$was_cached = true;
			}
		}
		$r = null;

		if( $code == "select" ) { $r = $this->test_select(); }
		elseif( $code == "constant_as" ) { $r = $this->test_constant_as(); }
		elseif( $code == "math_as" ) { $r = $this->test_math_as(); }
		elseif( $code == "count" ) { $r = $this->test_count(); }
		elseif( $code == "max" ) { $r = $this->test_max(); }
		elseif( $code == "load" ) { $r = $this->test_load(); }
		elseif( $code == "sample" ) { $r = $this->test_sample(); }
		else { print "<p>Unknown capability code: '$code'</p>"; return false; }
		$this->caps[$code] = $r;
		if( isset( $this->caps_cache ) )
		{
			$db_key = $this->endpoint.";".$code;
			$db_val = $r.";".time();
			if( $was_cached )
			{
				dba_replace( $db_key, $db_val, $this->caps_cache );
			}
			else
			{
				dba_insert( $db_key, $db_val, $this->caps_cache );
			}
		}
		return $r;
	}

	function anySubject()
	{
		if( !isset( $this->caps_anysubject ) )
		{
			$results = $this->query( 
			  "SELECT * WHERE { ?s ?p ?o } LIMIT 1" );
			if( sizeof($results)) 
			{
				$row = $results->fetch_array();
				$this->caps_anysubject = $row["s"];
			}
		}
		return $this->caps_anysubject;
	}

	# return true if the endpoint supports SELECT 
	function test_select() 
	{
		$output = $this->dispatchQuery( 
		  "SELECT ?s ?p ?o WHERE { ?s ?p ?o } LIMIT 1" );
		return !isset( $this->errno );
	}

	# return true if the endpoint supports AS
	function test_math_as() 
	{
		$output = $this->dispatchQuery( 
		  "SELECT (1+2 AS ?bar) WHERE { ?s ?p ?o } LIMIT 1" );
		return !isset( $this->errno );
	}

	# return true if the endpoint supports AS
	function test_constant_as() 
	{
		$output = $this->dispatchQuery( 
		  "SELECT (\"foo\" AS ?bar) WHERE { ?s ?p ?o } LIMIT 1" );
		return !isset( $this->errno );
	}

	# return true if the endpoint supports SELECT (COUNT(?x) as ?n) ... GROUP BY 
	function test_count() 
	{
		# assumes at least one rdf:type predicate
		$s = $this->anySubject();
		if( !isset($s) ) { return false; }
		$output = $this->dispatchQuery( 
		  "SELECT (COUNT(?p) AS ?n) ?o WHERE { <$s> ?p ?o } GROUP BY ?o" );
		return !isset( $this->errno );
	}

	function test_max() 
	{
		$s = $this->anySubject();
		if( !isset($s) ) { return false; }
		$output = $this->dispatchQuery( 
		  "SELECT (MAX(?p) AS ?max) ?o WHERE { <$s> ?p ?o } GROUP BY ?o" );
		return !isset( $this->errno );
	}

	function test_sample() 
	{
		$s = $this->anySubject();
		if( !isset($s) ) { return false; }
		$output = $this->dispatchQuery( 
		  "SELECT (SAMPLE(?p) AS ?sam) ?o WHERE { <$s> ?p ?o } GROUP BY ?o" );
		return !isset( $this->errno );
	}

	function test_load() 
	{
		$output = $this->dispatchQuery( 
		  "LOAD <http://graphite.ecs.soton.ac.uk/sparqllib/examples/loadtest.rdf>" );
		return !isset( $this->errno );
	}


}

class sparql_result
{
	var $rows;
	var $fields;
	var $db;
	var $i = 0;
	function __construct( $db, $rows, $fields )
	{
		$this->rows = $rows;
		$this->fields = $fields;
		$this->db = $db;
	}

	function fetch_array()
	{
		if( !@$this->rows[$this->i] ) { return; }
		$r = array();
		foreach( $this->rows[$this->i++]  as $k=>$v )
		{
			$r[$k] = $v["value"];
			$r["$k.type"] = $v["type"];
			if( isset( $v["language"] ) )
			{
				$r["$k.language"] = $v["language"];
			}
			if( isset( $v["datatype"] ) )
			{
				$r["$k.datatype"] = $v["datatype"];
			}
		}
		return $r;
	}

	function fetch_all()
	{
		$r = new sparql_results();
		$r->fields = $this->fields;
		foreach( $this->rows as $i=>$row )
		{
			$r []= $this->fetch_array();
		}
		return $r;
	}

	function num_rows()
	{
		return sizeof( $this->rows );
	}

	function field_array()
	{
		return $this->fields;
	}

	function field_name($i)
	{
		return $this->fields[$i];
	}
}


# class xx_xml adapted code found at http://php.net/manual/en/function.xml-parse.php
# class is cc-by 
# hello at rootsy dot co dot uk / 24-May-2008 09:30
class xx_xml {

	// XML parser variables
	var $parser;
	var $name;
	var $attr;
	var $data  = array();
	var $stack = array();
	var $keys;
	var $path;
	var $looks_legit = false;
	var $error;
  
	// either you pass url atau contents.
	// Use 'url' or 'contents' for the parameter
	var $type;

	// function with the default parameter value
	function __construct($url='http://www.opocot.com', $type='url') {
		$this->type = $type;
		$this->url  = $url;
		$this->parse();
	}
  
	function error() { return $this->error; }

	// parse XML data
	function parse()
	{
		$this->rows = array();
		$this->fields = array();
		$data = '';
		$this->parser = xml_parser_create ("UTF-8");
		xml_set_object($this->parser, $this);
		xml_set_element_handler($this->parser, 'startXML', 'endXML');
		xml_set_character_data_handler($this->parser, 'charXML');

		xml_parser_set_option($this->parser, XML_OPTION_CASE_FOLDING, false);

		if ($this->type == 'url') {
			// if use type = 'url' now we open the XML with fopen
		  
			if (!($fp = fopen($this->url, 'rb'))) {
				$this->error("Cannot open {$this->url}");
			}

			while (($data = fread($fp, 8192))) {
				if (!xml_parse($this->parser, $data, feof($fp))) {
					$this->error = sprintf('XML error at line %d column %d',
						xml_get_current_line_number($this->parser),
						xml_get_current_column_number($this->parser));
					return;
				}
			}
	 	} else if ($this->type == 'contents') {
	  	// Now we can pass the contents, maybe if you want
			// to use CURL, SOCK or other method.
			$lines = explode("\n",$this->url);
			foreach ($lines as $val) {
				$data = $val . "\n";
				if (!xml_parse($this->parser, $data)) {
					$this->error = $data."\n".sprintf('XML error at line %d column %d',
						xml_get_current_line_number($this->parser),
				 		xml_get_current_column_number($this->parser));
					return;
				}
			}
		}
		if( !$this->looks_legit ) 
		{
			$this->error = "Didn't even see a sparql element, is this really an endpoint?";
		}
	}

	function startXML($parser, $name, $attr)	
	{
		if( $name == "sparql" ) { $this->looks_legit = true; }
		if( $name == "result" )
		{
			$this->result = array();
		}
		if( $name == "binding" )
		{
			$this->part = $attr["name"];
		}
		if( $name == "uri" || $name == "bnode" )
		{
			$this->part_type = $name;
			$this->chars = "";
		}
		if( $name == "literal" )
		{
			$this->part_type = "literal";
			if( isset( $attr["datatype"] ) )
			{
				$this->part_datatype = $attr["datatype"];
			}
			if( isset( $attr["xml:lang"] ) )
			{
				$this->part_lang = $attr["xml:lang"];
			}
			$this->chars = "";
		}
		if( $name == "variable" )
		{
			$this->fields[] = $attr["name"];
		}
	}

	function endXML($parser, $name)	{
		if( $name == "result" )
		{
			$this->rows[] = $this->result;
			$this->result = array();
		}
		if( $name == "uri" || $name == "bnode" || $name == "literal" )
		{
			$this->result[$this->part] = array( "type"=>$name, "value"=>$this->chars );
			if( isset( $this->part_lang ) )
			{
				$this->result[$this->part]["lang"] = $this->part_lang;
			}
			if( isset( $this->part_datatype ) )
			{
				$this->result[$this->part]["datatype"] = $this->part_datatype;
			}
			$this->part_datatype = null;
			$this->part_lang = null;
		}
	}

	function charXML($parser, $data)	{
		@$this->chars .= $data;
	}

}

class sparql_results extends ArrayIterator
{
	var $fields;
	function fields() { return $this->fields; }

	function render_table()
	{
		$html = "<table class='sparql-results'><tr>";
		foreach( $this->fields as $i=>$field )
		{
			$html .= "<th>?$field</th>";
		}
		$html .= "</tr>";
		foreach( $this as $row )
		{
			$html.="<tr>";
			foreach( $row as $cell )
			{
				$html .= "<td>".htmlspecialchars( $cell )."</td>";
			}
			$html.="</tr>";
		}
		$html.="</table>
<style>
table.sparql-results { border-collapse: collapse; }
table.sparql-results tr td { border: solid 1px #000 ; padding:4px;vertical-align:top}
table.sparql-results tr th { border: solid 1px #000 ; padding:4px;vertical-align:top ; background-color:#eee;}
</style>
";
		return $html;exit;
	}

}