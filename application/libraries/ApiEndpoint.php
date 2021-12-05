<?php 

class ApiEndpoint
{


    public function __construct($endPoint, $function)
    {
        //Checking if valid method and endpoint are used
        if(!$endPoint || !$function)
        {
            ResponseBuilder::not_found('Funtion or endpoint not specified');
        }
        elseif(!$this->checkValidEndpoint($endPoint))
        {
            ResponseBuilder::not_found('Specified endpoint does not exist');
        }
        
    }


     /**
     * Checks if requested endpoint really exists
     * 
     * @author Jaromir Hradil
     * 
     * @param string $endpoint_to_check
     * 
     * @return bool 
     */
    
    private function checkValidEndpoint($endpoint_to_check)
    {
        $dir = 'application/controller/Api';
        //Getting valid endpoints dynamically
        foreach (new DirectoryIterator($dir) as $file) 
        {
            if($file->isDot())
            {
                continue;
            }

            $existing_endpoint = $file->getFilename();
            $existing_endpoint = strtolower(explode('.', $existing_endpoint)[0]);  
           
            if($existing_endpoint == $endpoint_to_check)
            {
                return true;
            }   
        }

        return false;
    }



}   



