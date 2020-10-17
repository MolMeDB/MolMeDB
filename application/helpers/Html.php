<?php

/**
 * HTML class for making basic html elements
 * 
 * @author Jakub Juračka
 */
class Html 
{
    /** HTML TYPES with defined default media root directory */
    private static $JS = 'js';
    private static $CSS = 'css';
    private static $IMG = 'files/pictures';

    /**
     * Returns IMG element in string
     * 
     * @param string $fileName - Name of JS file (base is directory /media/js/)
     * @param string $class
     * 
     * @return string
     */
    // public static function img($filePath, $class = Null)
    // {
    //     if(substr($filePath, 0, strlen(File::PICTURE_PREFIX)) !== File::PICTURE_PREFIX)
    //     {
    //         $filePath = self::get_media_path($filePath, self::$IMG);
    //     }

    //     // If file doesn't exists, set default picture path
    //     if(!File::file_exists($filePath) || $filePath[strlen($filePath)-1] === '/')
    //     {
    //         $filePath = System::DEFAULT_PIC_URL;
    //     }

    //     $classTag = '';

    //     if($class)
    //     {
    //         $classTag = "class='" . $class . "'";
    //     }

    //     return "<img $classTag src='" . $filePath . "'>";
    // }

    /**
     * Returns HTML ANCHOR
     * 
     * @param string $url
     * @param string $title
     * 
     * @return string
     */
    public static function anchor($url, $title, $new_tab = FALSE)
    {
        $url = PROTOCOL . URL . '/' . $url;

        return "<a href='$url'" . ($new_tab ? 'target="_blank"' : '') . ">$title</a>";
    }

    /**
     * Returns JS element in string
     * 
     * @param string $fileName - Name of JS file (base is directory /media/js/)
     * 
     * @return string   
     */
    public static function javascript($filePath)
    {
        $filePath = self::get_media_path($filePath, self::$JS);

        return "<script src='$filePath?ver=" . JS_VERSION . "'></script>";
    }

    /**
     * Returns html link element in string
     * 
     * @param string $filePath
     * 
     * @return string
     */
    public static function css($filePath)
    {
        $filePath = self::get_media_path($filePath, self::$CSS);

        return "<link rel='stylesheet' href='$filePath?ver=" . CSS_VERSION . "'></link>";
    }

    /**
     * Generates text input
     * 
     * @param string $name
     * @param string $value
     * 
     * @return string
     */
    public static function text_input($name, $value)
    {
        return "<input class='form-control' type='text' name='$name' value='$value'>";
    }

    /**
     * Generates checkbox input
     * 
     * @param string $name
     * @param string $value
     * 
     * @return string
     */
    public static function checkbox_input($name, $value, $checked = 'false')
    {
        return "<input type='checkbox' name='$name' value='$value' " . ($checked === 'true' ? 'checked' : '') . ">";
    }

    /**
     * Makes button html
     * 
     * @param string $type
     * @param string $title
     * @param string $class
     */
    public static function button($type, $title, $class = "")
    {
        return "<button style='margin-top: 10px;' type='$type' class='$class btn btn-sm'>$title</button>";
    }


    /**
     * Returns path of file
     * 
     * @param string $fileName
     * @param string $type
     * 
     * @return string
     */
    private static function get_media_path($fileName, $type)
    {
        $fileName = trim($fileName);

        if($fileName === '')
        {
            return "";
        }

        // Append prefix with path
        if($fileName[0] !== '/')
        {
            $fileName = MEDIA_ROOT . $type . '/' . $fileName;
        }
        else
        {
            $fileName = ltrim($fileName, '/');
        }
        
        return $fileName;
    }
}