<?php

/**
 * Api for connect to EuropePMC
 */
class EuropePMC
{
    /** HOLDS info about connection */
    private $client;



    /**
     * Constructor
     */
    function __construct()
    {
        $this->client = new Http_request('https://www.ebi.ac.uk/europepmc/webservices/rest/');
    }

    /**
     * Search articles for given query on EuropePMC server
     */
    public function search($query)
    {
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

            return self::get_data($response);
        }  
        catch(Exception $e)
        {
            throw new Exception('Server error.');
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