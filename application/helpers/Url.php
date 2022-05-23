<?php

/**
 * Url class
 * 
 * @author Jakub Juracka
 */
class Url
{
    const URL_PATTERN = "/((([A-Za-z]{3,9}:(?:\/\/)?)(?:[-;:&=\+\$,\w]+@)?[A-Za-z0-9.-]+(:[0-9]+)?|(?:www.|[-;:&=\+\$,\w]+@)[A-Za-z0-9.-]+)((?:\/[\+~%\/.\w\-_]*)?\??(?:[-\+=&;%@.\w_]*)#?(?:[\w]*))?)/";

    /**
     * Checks, if given url is valid
     * 
     * @param string $url
     * 
     * @return boolean
     */
    public static function is_valid($url)
    {
        return preg_match(self::URL_PATTERN, $url);
    }

    /**
     * Checks, if given URL is reachable (returns 200)
     * 
     * @param string $url
     * 
     * @return boolean
     */
    public static function is_reachable($url)
    {
        list($status) = get_headers($url);
        return strpos($status, '200') !== FALSE;
    }

    /**
     * Returns current base of url address
     * 
     * @return string
     */
    public static function base()
    {
        $base_url = url::protocol().'://'.url::domain().url::suffix();
		return $base_url;
    }

    /**
	 * Returns protocol
	 * 
	 * @return string
	 */
	public static function protocol()
	{
		// if exists settings key, return it
        return (
                !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ||
                @$_SERVER['SERVER_PORT'] == 443
        ) ? 'https' : 'http';
	}

    /**
	 * @return string
	 */
	public static function suffix()
	{
        return substr(server::script_name(),0,-9);
	}

    /**
	 * Returns domain
	 * @return string
	 */
	public static function domain()
	{
        return server::http_host();
	}

    /**
	 * Returns RDF prefix
	 * @return string
	 */
	public static function rdf_domain($strict_remote = false)
	{
        // TODO
        if(DEBUG && !$strict_remote)
        {
            return self::base() . 'api/rdf/';
        }
        return "https://rdf.molmedb.upol.cz/";
	}

    /**
     * Returns url for generating 2d structure
     * 
     * @return string
     */
    public static function get_2d_structure_source($smiles)
    {
        return "https://molmedb.upol.cz/depict/cow/svg?smi=" . urlencode($smiles);
    }
}