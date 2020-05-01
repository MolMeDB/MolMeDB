<?php

/**
 * Api for connect to EuropePMC
 */
class EuropePMC
{
    /** HOLDS info about connection */
    private $client;
    private $connected;


    /**
     * Constructor
     */
    function __construct()
    {
        $config = new Config();

        $uri = $config->get(Configs::EUROPEPMC_URI);
        if(!$uri || $uri === '')
        {
            $$this->connected = false;
        }
        else
        {
            $this->client = new Http_request($uri);
            $this->connected = true;
        }
    }

    /**
     * 
     */
    public function is_connected()
    {
        return $this->connected;
    }

    /**
     * Search articles for given query on EuropePMC server
     */
    public function search($query)
    {
        if(!$this->is_connected())
        {
            return false;
        }

        $uri = 'search';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'query' => $query,
            'format' => 'json',
            'resultType' => 'lite'
        );

        try
        {
            $response = $this->client->request($uri, $method, $params);

            if(count($response->resultList->result) > 1) // Get best result on the top
            {
                // Try to find best result
                $best = $response->resultList->result[0];
                $rows = array();

                foreach($response->resultList->result as $row)
                {
                    $pmid = property_exists($row, 'pmid') ? $row->pmid : NULL;
                    $doi = property_exists($row, 'doi') ? $row->doi : NULL;

                    if($pmid === trim($query) || $doi === trim($query))
                    {
                        $best = $row;
                    }
                    else
                    {
                        $rows[] = $row;
                    }
                }

                array_unshift($rows, $best);

                $response->resultList->result = $rows;
            }

            return self::get_data($response);
        }  
        catch(Exception $e)
        {
            throw new Exception('Europe PMC server error.');
        }
    }

    /**
     * Returns data from server response
     * 
     * @param object $response
     * 
     * @return array|string|object
     */
    private static function get_data($response)
    {
        $data = $response->resultList->result;

        $result = array();

        foreach($data as $row)
        {
            $result[] = array
            (
                'pmid'  => property_exists($row, "pmid") ? $row->pmid : "",
                'doi'   => property_exists($row, "doi") ? $row->doi : "",
                'title' => property_exists($row, "title") ? $row->title : "",
                'authors' => property_exists($row, "authorString") ? $row->authorString : "",
                'journal' => property_exists($row, "journalTitle") ? $row->journalTitle : "",
                'issue'   => property_exists($row, "issue") ? $row->issue : '',
                'volume'    => property_exists($row, "journalVolume") ? $row->journalVolume : "",
                'year'      => property_exists($row, "pubYear") ? $row->pubYear : "",
                'pages'     => property_exists($row, "pageInfo") ? $row->pageInfo : '',
                'citedByCount' => property_exists($row, "citedByCount") ? $row->citedByCount : "",
                'publicatedDate' => property_exists($row, "firstPublicationDate") ? 
                    date('d-m-Y', strtotime($row->firstPublicationDate)) : ""
            );
        }

        return $result;
    }
}