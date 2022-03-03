<?php

class Mol_fingerprint
{
    /**
     * Is string valid decoded fingerprint?
     * 
     * @return bool
     */
    public static function is_valid_decoded($string)
    {
        return ctype_digit($string) && !preg_match('/[2-9]+/', $string);
    }

    /**
     * Is string valid encoded fingerprint?
     * 
     * @return bool
     */
    public static function is_valid_encoded($string)
    {
        return True && preg_match('/^[0-9|a-f]+$/i', $string);
    }

    /**
     * Encodes fingerprint for DB saving
     * 
     * @param string|Vector $fingerprint
     * 
     * @return string - Encoded fingerprint
     */
    public static function encode($fingerprint)
    {
        if($fingerprint instanceof Vector)
        {
            $fingerprint = $fingerprint->__toString();
        }

        if(!self::is_valid_decoded($fingerprint))
        {
            return false;
        }

        $encoded = "";

        $len = 8;
        $str = $fingerprint;

        while($str != "")
        {
            $s = substr($str, 0, $len);
            $str = substr($str, $len);

            if(strlen($s) < 8)
            {
                $missing = 8-strlen($s);
                $s = str_pad('0', $missing, '0') . $s;
            }

            $en = strval(dechex(bindec($s)));

            if(strlen($en) < 2)
            {
                $en = "0" . $en;
            }

            $encoded .= $en;
        }

        return $encoded;
    }


    /**
     * Decodes string to fingerprint
     * 
     * @param string $encoded_fingerprint
     * 
     * @return string - Decoded fingerprint
     */
    public static function decode($encoded_fingerprint)
    {
        if($encoded_fingerprint instanceof Vector)
        {
            $encoded_fingerprint = $encoded_fingerprint->__toString();
        }

        if(!self::is_valid_encoded($encoded_fingerprint))
        {
            return false;
        }

        $decoded = '';
        $t = $encoded_fingerprint;
        while($t != '')
        {
            $part = substr($t, 0, 2);
            $t = substr($t, 2);

            $de = decbin(hexdec($part));

            if(strlen($de) < 8)
            {
                $missing = 8-strlen($de);
                $de = str_pad('0', $missing, '0') . $de;
            }

            $decoded .= $de;
        }

        return $decoded;
    }

    /**
     * Returns fingerprint string as vector
     * 
     * @param string $fingerprint
     * 
     * @return Vector
     */
    public static function as_vector($fingerprint)
    {
        if($fingerprint instanceof Vector)
        {
            return $fingerprint;
        }

        $fingerprint = strval($fingerprint);

        // Is encoded?
        if(preg_match('/[a-f]+|[2-9]+/i', $fingerprint))
        {
            $fingerprint = self::decode($fingerprint);
        }

        $arr = str_split($fingerprint);
        $arr = array_map('intval', $arr);

        return new Vector($arr);
    }
}