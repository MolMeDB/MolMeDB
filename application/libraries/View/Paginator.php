<?php

/**
 * Paginator for PHP views
 * 
 * @author Jakub Juracka
 */
class View_paginator
{
    /**
     * Paginator view
     * 
     * @var string
     */
    private $view_path = APP_ROOT . 'view/core/_paginator.phtml';

    /**
     * URL PATH prefix
     * 
     * @var string
     */
    private $uri_path;

    /**
     * URI suffix
     * 
     * @var string
     */
    private $uri_suffix;

    /**
     * @var int
     */
    private $active;

    /**
     * @var int
     */
    private $total_records;

    /**
     * @var int
     */
    private $records_per_page;

    /**
     * @var int[]
     */
    private static $default_per_page_options = array
    (
        10,20,50,100
    );

    /**
     * Constructor
     */
    function __construct()
    {
        // Get path for given paginator
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,2);
        $err = 'Paginator can be constructed only in some controller instance method.';

        if(count($bt) < 2)
        {
            throw new Exception($err);
        }

        $calling = $bt[1];

        if(!preg_match('/Controller$/', $calling['class']) || $calling['function'] == '')
        {
            throw new Exception($err);
        }

        $controller = strtolower(preg_replace('/Controller$/', '', $calling['class']));
        $method = $calling['function'];

        $this->uri_path = $controller . "/" . $method . "/";
        // Set default values
        $this->active = 1;
        $this->total_records = 0;
        $this->records_per_page = 10;
    }

    /**
     * Sets path for redirection
     * 
     * @param string $path
     * 
     * @return View_paginator
     */
    public function path($path)
    {
        $path = trim($path, '/');
        $this->uri_suffix = $path . '/';
        return $this;
    }

    /**
     * Sets active page
     * 
     * @param int $active
     * 
     * @return View_paginator
     */
    public function active($active)
    {
        $this->active = $active;
        return $this;
    }

    /**
     * Sets total number of records
     * 
     * @param int $total
     * 
     * @return View_paginator
     */
    public function total_records($total)
    {
        $this->total_records = $total;
        return $this;
    }

    /**
     * Sets active page
     * 
     * @param int $per_page
     * 
     * @return View_paginator
     */
    public function records_per_page($per_page)
    {
        if(in_array($per_page, self::$default_per_page_options))
        {
            $this->records_per_page = $per_page;
        }
        return $this;
    }

    /**
     * Returns paginator items
     * 
     * @return array
     */
    private function get_items()
    {
        if(!$this->total_records || !$this->records_per_page)
        {
            return [];
        }

        $max_item = intval($this->total_records / $this->records_per_page)+1;
        $items = [];

        $this->active = $this->active > $max_item ? $max_item : $this->active;
        $this->active = $this->active < 1 ? 1 : $this->active;

        $sw = null;

        foreach(range(1, $max_item) as $i)
        {
            if($max_item <= 10)
            {
                $items[] = new Paginator_option(
                    $i, 
                    $i != $this->active, 
                    $this->uri_path . $this->uri_suffix, 
                    $this->records_per_page, 
                    $i==$this->active);
                continue;
            }

            if($this->active <= 6)
            {
                $sw = 1;
                if($i <= 6 || $i <= $this->active+1)
                {
                    $items[] = new Paginator_option(
                        $i, 
                        $i != $this->active, 
                        $this->uri_path . $this->uri_suffix, 
                        $this->records_per_page, 
                        $i==$this->active);
                    continue;
                }
            }
            else if($this->active > $max_item-6)
            {
                $sw = 2;
                // Add only last six
                if($i >= $max_item-6 || $i >= $this->active-1)
                {
                    $items[] = new Paginator_option(
                        $i, 
                        $i != $this->active, 
                        $this->uri_path . $this->uri_suffix, 
                        $this->records_per_page, 
                        $i==$this->active);
                    continue;
                }
            }
            else
            {
                $sw = 3;
                if($i >= $this->active-2 && $i <= $this->active+2)
                {
                    $items[] = new Paginator_option(
                        $i, 
                        $i != $this->active, 
                        $this->uri_path . $this->uri_suffix, 
                        $this->records_per_page, 
                        $i==$this->active);
                    continue;
                }
            }
        }

        switch($sw)
        {
            case 1: 
                // Add dots and two last values
                $items[] = new Paginator_option('...', false);
                $items[] = new Paginator_option(
                    $max_item-1,
                    $max_item-1 != $this->active, 
                    $this->uri_path . $this->uri_suffix, 
                    $this->records_per_page, 
                    $max_item-1 == $this->active);
                $items[] = new Paginator_option(
                    $max_item,
                    $max_item != $this->active, 
                    $this->uri_path . $this->uri_suffix, 
                    $this->records_per_page, 
                    $max_item == $this->active);
                break;

            case 2:
                // Prepend first two and dots
                array_unshift($items, new Paginator_option('...', false));
                array_unshift($items, new Paginator_option(
                    2,
                    true, 
                    $this->uri_path . $this->uri_suffix, 
                    $this->records_per_page, 
                    false
                ));
                array_unshift($items, new Paginator_option(
                    1,
                    true, 
                    $this->uri_path . $this->uri_suffix, 
                    $this->records_per_page, 
                    false
                ));
                break;

            case 3: 
                // Prepend first two and dost
                array_unshift($items, new Paginator_option('...', false));
                array_unshift($items, new Paginator_option(2));
                array_unshift($items, new Paginator_option(1));
                // Append dots and last two
                $items[] = new Paginator_option('...', false);
                $items[] = new Paginator_option(
                    $max_item-1,
                    $max_item-1 != $this->active, 
                    $this->uri_path . $this->uri_suffix, 
                    $this->records_per_page, 
                    $max_item-1 == $this->active);
                $items[] = new Paginator_option(
                    $max_item,
                    $max_item != $this->active, 
                    $this->uri_path . $this->uri_suffix, 
                    $this->records_per_page, 
                    $max_item == $this->active);
                break;
        }

        return $items;
    }


    /**
     * Renders final paginator
     * 
     * @return string
     */
    public function render()
    {
        $data = array
        (
            'uri' => $this->uri_path . $this->uri_suffix,
            'items' => $this->get_items()
        );

        extract($data);

        ob_start();
        try
        {
            require($this->view_path);
            $s = ob_get_contents();
            ob_end_clean();
            return $s;
        }
        catch(Exception $e)
        {
            if(!DEBUG)
            {
                ob_end_clean();
                return '';
            }
            return $e->getMessage();
        }
    }
}

class Paginator_option
{
    /**
     * Params
     */
    private $content;
    private $uri_prefix;
    private $per_page;
    private $is_active;
    private $is_clickable;

    /**
     * Constructor
     */
    function __construct($content, $is_clickable = true, $uri_prefix = '', $per_page = 10, $is_active = false)
    {
        $this->content = $content;
        $this->uri_prefix = $uri_prefix;
        $this->per_page = $per_page;
        $this->is_clickable = $is_clickable;
        $this->is_active = $is_active;
    }

    /**
     * Prepares final uri
     * 
     * @return string
     */
    public function prepare_uri()
    {
        $get_params = $_GET;
        $params_suffix = '';

        foreach($get_params as $key => $v)
        {
            $params_suffix .= "&$key=$v";
        }

        $params_suffix = '?' . ltrim($params_suffix, '&');

        return $this->is_clickable ? Url::base() . $this->uri_prefix . $this->content . '/' . $this->per_page . $params_suffix : 'javascript:void(0)';
    }

    /**
     * Renders html
     */
    function render()
    {
        $uri = $this->prepare_uri();

        return $this->is_active ?
            '<li class="active"><a href="' . $uri . '">' . $this->content . '</a></li>' : 
            '<li><a href="' . $uri . '">' . $this->content . '</a></li>';
        return $this->content;
    }
}