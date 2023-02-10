<?php
/**
 * ApiRequest class for request header parsing
 * 
 * @author Jaromír Hradil
 */
class ResponseBuilder
{
    /** RESPONSE CODES */
    const CODE_OK = 200;
    const CODE_OK_NO_CONTENT = 204;
    const CODE_SEE_OTHER = 303;
    const CODE_BAD_REQUEST = 400;
    const CODE_UNAUTHORIZED = 401;
    const CODE_FORBIDDEN = 403;
    const CODE_NOT_FOUND = 404;
    const CODE_SERVER_ERROR = 500;

    /**
     * Method for creating HTTP 400 response code and error message
     * 
     * @param string $err_text - OPTIONAL
     * 
     * @author Jaromír Hradil 
     */
    public static function bad_request($err_text = NULL)
    {
        http_response_code(self::CODE_BAD_REQUEST);
        if($err_text)
        {
            echo('400 Bad Request: '. $err_text);
        }
        die;
    }

    /**
     * Method for creating HTTP 303 response code with corresponding header
     * 
     * @param string $target - Where to redirect request
     * 
     * @author Jakub Juracka
     */
    public static function see_other($target)
    {
        http_response_code(self::CODE_SEE_OTHER);
        // TODO
        header("Location: " . $target);
        die;
    }

    /**
     * 
     * Method for creating HTTP 401 response code and error message
     * 
     * @param string $err_text - OPTIONAL
     * 
     * @author Jaromír Hradil
     */
    public static function unauthorized($err_text = NULL)
    {
        http_response_code(self::CODE_UNAUTHORIZED);
        if($err_text)
        {
            echo('401 Unauthorized: '.$err_text);
        }
        die;
    }

    /**
     * 
     * Method for creating HTTP 200 response 
     * 
     * @param string $response
     * @param array|string $header_content
     * 
     * @author Jaromír Hradil
     */
    public static function ok($response, $header_content = [])
    {
        if(!is_array($header_content))
        {
            $header_content = [$header_content];
        }

        foreach($header_content as $hc)
        {
            header($hc);
        }

        http_response_code(self::CODE_OK);

        if($response && is_string($response))
        {
            echo($response);
        }
        die;
    }

    /**
     * 
     * Method for creating HTTP 200 response code and error message
     * 
     * @author Jaromír Hradil
     */
    public static function ok_no_content()
    {
        http_response_code(self::CODE_OK_NO_CONTENT);
        die;
    }

    /**
     * 
     * Method for creating HTTP 403 response code and error message
     * 
     * @param string $err_text - OPTIONAL
     * 
     * @author Jaromír Hradil
     */
    public static function forbidden($err_text = NULL)
    {
        http_response_code(self::CODE_FORBIDDEN);
        if($err_text)
        {
            echo('403 Forbidden: '.$err_text);
        }
        die;        
    }  

    /**
     * Method for creating HTTP 404 response code and error message
     * 
     * @param string $err_text - OPTIONAL
     * 
     * @author Jaromír Hradil
     */
    public static function not_found($err_text = NULL)
    {
        http_response_code(self::CODE_NOT_FOUND);
        if($err_text)
        {
            echo('404 Not Found: '.$err_text);
        }
        die;
    } 

    /**
     * Method for creating HTTP 500 response code and error message
     * 
     * @param string|Exception $err_text - OPTIONAL
     *  
     * @author Jaromír Hradil
     */
    public static function server_error($err_text = NULL)
    {
        if($err_text instanceof Exception)
        {
            $err_text = $err_text->getMessage();
        }

        http_response_code(self::CODE_SERVER_ERROR);
        if($err_text && DEBUG)
        {
            echo('500 Server error: Internal error occured. ');
            echo $err_text;
        }
        //TODO logging of errors
        die;
    } 

    /**
     * Parses final response to XML format
     * 
     * @param string $content
     * 
     * @return array
     */
    public static function xml($content)
    {
        return $content;
    }

    /**
     * Parses final response to CSV format (2D-array)
     * 
     * @param string $content
     * 
     * @return array
     * @throws Exception
     */
    public static function csv($content)
    {
        if($content instanceof Iterable_object)
        {
            $content = $content->as_array();
        }

        return self::create_csv($content);
        
    }

    /**
     * Parses final response to json format
     * 
     * @param string $content
     * 
     * @return string
     * @throws Exception
     */
    public static function json($content)
    {
        if($content instanceof Iterable_object)
        {
            $content = $content->as_array();
        }

        return json_encode($content);
    }

    /**
     * Parses final response to HTML format
     * 
     * @param string $content
     * 
     * @return string
     * @throws Exception
     */
    public static function html($content)
    {
        if($content instanceof View)
        {
            return $content->render(FALSE);
        }

        if(is_string($content))
        {
            return $content;
        }

        if(is_object($content) && method_exists($content, '__toString'))
        {
            return $content->__toString();
        }

        return false;
    }

    /**
    * Method for creating XMLX output
    * 
    * @param array $
    *  
    */
    public static function xlsx($input)
    {
        $req_keys = ['filename','sheets', 'headers', 'data'];

        if(!is_array($input) && !($input instanceof Iterable_object))
        {
            throw new Exception('Invalid xlsx_builder input.');
        }

        $writer = new XLSXWriter();
        $writer->setAuthor('MolMeDB core');

        // Iterate over sheets
        foreach($input['sheets'] as $key => $sheet_name)
        {
            $attrs = $input['headers'][$key];
            // Prepare data
            $data = [];

            foreach($input['data'][$key] as $raw_row)
            {
                $row = [];
                foreach($attrs as $att_path => $att_name)
                {
                    $a2 = explode('.', $att_path);
                    $v = $raw_row;
                    foreach($a2 as $a)
                    {
                        if(!array_key_exists($a,$v))
                        {
                            throw new MmdbException('Cannot find `' . $a . '` attribute.');
                        }
                        $v = $v[$a];
                    }
                    if(is_array($v) && isset($v[0]) && is_array($v[0]))
                    {
                        throw new MmdbException('Cannot insert array value to the table cell.');
                    }

                    $row[] = is_array($v) ? implode(', ', $v) : $v;
                }
                $data[] = $row;
            }

            // Prepare header
            $h = [];

            foreach(array_values($input['headers'][$key]) as $index => $th)
            {
                $all_numeric = TRUE;

                foreach($data as $row)
                {
                    $all_numeric = $all_numeric && (is_numeric($row[$index]) || empty($row[$index]));
                }

                $h[$th] = ($all_numeric ? 'number' : 'string');
            }

            // Create sheet
            $writer->writeSheet($data, $sheet_name, $h);
        }

        // Create file and save
        $path = tempnam("/tmp", "");
        $writer->writeToFile($path);

        header('Content-Tpe: application/zip');
        // tell the browser we want to save it instead of displaying it
        header('Content-Disposition: attachment; filename="'.$input['filename'].'.xlsx";');
        header("Content-length: " . filesize($path));
        header("Pragma: no-cache"); 
        header("Expires: 0"); 
        readfile("$path");

        die;
    }

    /**
    * Method for creating CSV output
    * 
    * @param array $arr
    *  
    * @author Jaromír Hradil
    */
    private static function create_csv($arr)
    {
        if(!$arr || !is_array($arr) || count($arr) === 0)
        {
            throw new Exception('Only 2D arrays can be converted to CSV format.');
        }

        $arr_vals = array_values($arr);
        
        //If input array is 1D it will be converted into 2D array
        $nested = true; 

        for ($i=0; $i < count($arr_vals); $i++)
        { 
            if(!is_array($arr_vals[$i]))
            {
                $nested = false;
            }
        }
        //Converting into 2D array
        $arr = $nested ? $arr : array($arr);
        
        //Preparing for inner values checking
        $csv_output = "";
        $keys = array_keys($arr[0]);
        $key_format_check = array_keys(range(1, count($keys)));

        if($keys !== $key_format_check)
        {
            for ($i=0; $i < count($keys); $i++)
            { 
                $keys[$i] = strval($keys[$i]);
            }

            $csv_output = $csv_output.implode(";", $keys).PHP_EOL;  
        }        

        foreach($arr as $arr_item)
        {
            $arr_item_keys = array_keys($arr_item);
            $csv_content_line = array();

            //Csv keys are not the same
            if($arr_item_keys != $keys)
            {
                return false;
            }
            else
            {
                foreach($arr_item as $arr_item_content)
                {
                    //There are nested elements
                    if(is_array($arr_item_content))
                    {
                        return false;
                    }
            
                    $csv_content_line[] = strval($arr_item_content);
                }
            }
            
            $csv_output = $csv_output.implode(";", $csv_content_line).PHP_EOL;
        }
        
        return $csv_output;
    }

    /** method for machine readable RDF formats */
    public static function RDF($uri)
    {
        http_response_code(self::CODE_SEE_OTHER);
        $query = "DESCRIBE <" . $uri . ">";
        $query = rawurlencode($query);
        header("Location: https://idsm.elixir-czech.cz/sparql/endpoint/molmedb?query=$query");
        die;
    }

    /**
     * Returns SDF file attachment
     * 
     * @param string $sdf_content
     */
    public static function sdf($sdf_content)
    {
        // Create file and save
        $path = tempnam("/tmp", "");
        file_put_contents($path, $sdf_content);

        header('Content-Tpe: chemical/x-mdl-sdfile');
        // tell the browser we want to save it instead of displaying it
        header('Content-Disposition: attachment; filename="structure.sdf";');
        header("Content-length: " . filesize($path));
        header("Pragma: no-cache"); 
        header("Expires: 0"); 
        readfile("$path");

        die;
    }

    /**
     * Returns PNG file attachment
     * 
     * @param string $sdf_content
     */
    public static function svg($content)
    {
        // Create file and save
        $path = tempnam("/tmp", "");
        file_put_contents($path, $content);

        header('Content-Tpe: image/svg+xml');
        // tell the browser we want to save it instead of displaying it
        header('Content-Disposition: attachment; filename="structure.svg";');
        header("Content-length: " . filesize($path));
        header("Pragma: no-cache"); 
        header("Expires: 0"); 
        readfile("$path");

        die;
    }
}