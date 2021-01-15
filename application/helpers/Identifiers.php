<?php

class Identifiers
{
	CONST PREFIX = 'MM';
	CONST min_len = 7;
	CONST PATTERN = '/^MM{1}[0-9]+$/';
	
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
		$subst_link_model = new Substance_links();
		$identifier = self::get_identifier($id);
		
		// Check, if already exists
		$exists = $subst_model->where('identifier', $identifier)
			->get_one();

		$exists_2 = $subst_link_model->where('identifier', $identifier)->get_one();

		if(!$id || $exists->id || $exists_2->id)
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

	/**
	 * Checks, if given string is known identifier form
	 * 
	 * @param string $string
	 * 
	 * @return boolean
	 */
	public static function is_identifier($string)
	{
		$string = trim($string);

		return self::is_valid($string) || 
			Upload_validator::check_identifier_format($string, Upload_validator::DRUGBANK, True) ||
			Upload_validator::check_identifier_format($string, Upload_validator::PUBCHEM, True) ||
			Upload_validator::check_identifier_format($string, Upload_validator::CHEMBL_ID, True) || 
			Upload_validator::check_identifier_format($string, Upload_validator::CHEBI_ID, True);
		}
}