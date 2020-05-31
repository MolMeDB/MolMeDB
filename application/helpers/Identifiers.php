<?php

class Identifiers
{
	CONST PREFIX = 'MM';
	CONST min_len = 7;
	
	/**
	 * Generates new identifier
	 * 
	 * @param int $id
	 * 
	 * @return string
	 */
	public static function generate_substance_identifier($id = NULL)
	{
		if(!$id)
		{
			$id = Db::getLastIdSubstance() + 1;
		}
		
		// Get string value
		$id = strval($id);
		$id_len = strlen($id);
		
		$zero_count = self::min_len - strlen(self::PREFIX) - $id_len;
		
		for($i = 0; $i < $zero_count; $i++)
		{
			$id = '0' . $id;
		}
			
		return self::PREFIX . $id;
	}

	/**
	 * Checks, if given identifier is valid
	 * 
	 * @param string $identifier
	 * 
	 * @return boolean
	 */
	public static function is_valid($identifier)
	{
		if(!$identifier	|| trim($identifier) == '')
		{
			return false;
		}

		return true;
	}
}