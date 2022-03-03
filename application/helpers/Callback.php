<?php

/**
 * Callbacks
 * 
 * - All methods, should be PROTECTED STATIC!
 * 
 * @author Jakub Juracka
 */
class Callback
{
    /**
     * Prepare params for function
     */
    public static function __callStatic($name, $arguments)
    {
        $called = new ReflectionMethod('Callback', $name);

        $required_params = $called->getNumberOfRequiredParameters();
        $total_params = $called->getNumberOfParameters();

        if(count($arguments) < $required_params)
        {
            // Cannot be called
            return null;
        }

        return call_user_func_array(array(get_called_class(), $name), $arguments);
    }


    /**
     * 
     * @param string $text
     * @param int $limit
     * 
     * @return string
     */
    protected static function limit_text($text, $limit = 50)
    {
        if(!is_string($text))
        {
            return '';
        }

        if(strlen($text) < $limit)
        {
            return $text;
        }

        $new_text = substr($text, 0, $limit);

        return $new_text . '...';
    }
}