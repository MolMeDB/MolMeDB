<?php

/**
 * Periodic table helper
 * 
 * @author Jakub Juracka
 */
class Periodic_table
{
    /** FILE PATH */
    CONST FILE_PATH = MEDIA_ROOT."files/periodic_table.json";

    private static $content;

    /**
     * Checks, if file exists
     * 
     * @throws Exception
     */
    private static function init()
    {
        if(self::$content)
        {
            return;
        }

        if(!file_exists(self::FILE_PATH) || !file_get_contents(self::FILE_PATH))
        {
            $remote_link = 'https://raw.githubusercontent.com/Bowserinator/Periodic-Table-JSON/master/PeriodicTableJSON.json';
            $content = file_get_contents($remote_link);
            
            if($content)
            {
                // Save
                $file = fopen(self::FILE_PATH, 'w');
                fwrite($file, $content);
                fclose($file);

                self::$content = json_decode($content);
                return;
            }

            throw new Exception("Cannot obtain periodic table data.");
        }

        self::$content = json_decode(file_get_contents(self::FILE_PATH));
    }

    /**
     * Returns atoms list
     * 
     * @return array
     */
    public static function atoms_short($sort_by_strlen = false)
    {
        self::init();

        $atoms = [];

        foreach(self::$content->elements as $atom)
        {
            $atoms[] = $atom->symbol;
        }

        if($sort_by_strlen)
        {
            usort($atoms, 'self::sort_by_strlen');
        }

        return $atoms;
    }

    /**
     * 
     */
    private static function sort_by_strlen($a, $b)
    {
        return strlen($b)-strlen($a);
    }
}