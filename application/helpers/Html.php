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
    private static $IMG = 'files/';

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
     * @param boolean $new_tab
     * 
     * @return string
     */
    public static function anchor($url, $title, $new_tab = FALSE, $include_redirection = FALSE)
    {
        if(!preg_match('/^http/', $url))
        {
            $url = Url::base() . rtrim($url, '/');
        }

        if(!strlen(trim($title)))
        {
            $title = '[_LINK]';
        }

        if($include_redirection)
        {
            $url .= "?redir=" . Url::redirect();
        }

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
    public static function checkbox_input($name, $value, $checked = 'false', $disabled = 'false')
    {
        return "<input type='checkbox' name='$name' value='$value' " . ($checked === 'true' ? 'checked' : ' ') . ($disabled === 'true' ? 'disabled' : '') . ">";
    }

    /**
     * Generates password input
     * 
     * @param string $name
     * @param string $value
     * 
     * @return string
     */
    public static function password_input($name, $value)
    {
        return "<input class='form-control' type='password' name='$name' value='$value'>";
    }

    /**
     * Makes button html
     * 
     * @param string $type
     * @param string $title
     * @param string $class
     */
    public static function button($type, $title, $class = "", $style = null)
    {
        return "<button style='" . ($style !== null ? $style : 'margin-top:10px;') . "' type='$type' class='" . (preg_match("/btn-lg/", $class) ? '' : 'btn-sm' ) . " $class btn'>$title</button>";
    }

    /**
     * Makes image html
     * 
     * @param string $src - prefix: 'files/'
     * @param string $class
     */
    public static function image($src, $class = "")
    {
        if(!preg_match('/^http/', $src, $m))
        {
            $src = self::$IMG . $src;
        }

        return "<img class='$class' src='" . $src . "'>";
    }

    /**
     * Makes molecule 2D structure image from smiles
     * 
     * @param string $smiles
     * 
     * @return string
     */
    public static function image_structure($smiles, $name = NULL, $include_annotation = false)
    {
        return $include_annotation ?  
            self::image('https://molmedb.upol.cz/depict/cow/svg?smi=' . urlencode($smiles) . ($name ? (urlencode(' ') . $name) : '' ) . '&amp;abbr=reagents&amp;hdisp=bridgehead&amp;showtitle=true&amp;zoom=2.2&amp;annotate=none') : 
            self::image('https://molmedb.upol.cz/depict/cow/svg?smi=' . urlencode($smiles));
    }

    /**
     * Returns slider
     */
    public static function slider($label, $input_name, $checked = false, $input_id = null)
    {
        return '<label style="margin-right: 10px;">
            ' . $label . '
            </label>
            <label class="switch">
                <input id="' . $input_id . '" name="' . $input_name . '" class="slider-i" ' . ($checked ? 'checked' : '') . ' type="checkbox">
                <span class="slider round"></span>
            </label>';
    }

    /**
     * Returns slider
     */
    public static function slider_2($label, $option_1, $option_2, $input_name, $checked = false, $input_id = null)
    {
        return '<label style="margin-right: 10px;">
            ' . $label . '
            </label>
            <label style="margin-right: 10px;">
            ' . $option_1 . '
            </label>
            <label class="switch">
                <input id="' . $input_id . '" name="' . $input_name . '" class="slider-i" ' . ($checked ? 'checked' : '') . ' type="checkbox">
                <span class="slider round"></span>
            </label>
            <label style="margin-left: 10px;">
            ' . $option_2 . '
            </label>';
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