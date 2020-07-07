<?php

class Identifiers
{
	CONST PREFIX = 'MM';
	CONST min_len = 7;
	CONST PATTERN = '/^MM{1}[0-9]+/';
	
	/**
	 * Generates new identifier
	 * 
	 * @param int $id
	 * 
	 * @return string
	 */
	public static function generate_substance_identifier($id = NULL)
	{
		$subst_model = new Substances();
		$identifier = self::get_identifier($id);
		
		// Check, if already exists
		$exists = $subst_model->where('identifier', $identifier)
			->get_one();

		if(!$id || $exists->id)
		{
			$id = Db::getLastIdSubstance() + 1;
			return self::get_identifier($id);
		}
		else
		{
			return $identifier;
		}
		
	}

	/**
	 * Generates identifier
	 * 
	 * @param int $id
	 * 
	 * @return string
	 */
	private static function get_identifier($id)
	{
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
		if(!$identifier	|| trim($identifier) == '' || strlen($identifier) < self::min_len)
		{
			return false;
		}

		return preg_match(self::PATTERN, $identifier);
	}
}