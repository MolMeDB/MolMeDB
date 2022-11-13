<?php

/**
 * Renders pagination for given settings
 * 
 * @author Jakub Juracka
 */
class Render_pagination extends Render
{
    /** 
     * @var int
     */
    const MAX_OPTIONS = 10;

    /**
     * Constructor
     */
    function __construct($total_items, $items_per_page, $active_page, $callback)
    {
        $this->view = new View('render/pagination');

        // Check input
        if($items_per_page > $total_items)
        {
            $items_per_page = $total_items;
        }

        if(!$items_per_page)
        {
            $items_per_page = 10;
        }

        if($active_page > intval($total_items / $items_per_page))
        {
            $active_page = intval($total_items / $items_per_page);
        }

        $this->view->total_items = $total_items;
        $this->view->items_per_page = $items_per_page;
        $this->view->active_page = $active_page;
        $this->view->callback = $callback;
    }

    /**
     * Prepares final view to display
     */
    public function prepare()
    {
        return TRUE; // Just prepared in constructor
    }

    /**
     * Prints pagination content
     * 
     * @param int $total_items
     * @param int $items_per_page
     * @param int $active_page
     * @param string $callback - Callback function with specified parameter {pagination}
     * 
     * @return string 
     */
    static function print($total_items, $items_per_page, $active_page, $callback)
    {
        $view = new Render_pagination($total_items, $items_per_page, $active_page, $callback);
        return $view->html();
    }

    /**
     * Prints pagination content
     * 
     * @param array $settings
     * 
     * @return string
     */
    static function print_arr($settings = [])
    {
        if(!is_array($settings) || 
            !isset($settings['total_items']) || 
            !isset($settings['items_per_page']) || 
            !isset($settings['active_page']) ||
            !isset($settings['callback']))
        {
            return '';
        }

        return self::print($settings['total_items'], $settings['items_per_page'], $settings['active_page'], $settings['callback']);
    }

    /**
     * Prints result view
     * 
     * @return string
     */
    function html()
    {
        return parent::__toString();
    }

    /**
     * Prepares callback string
     * 
     * @param string $callback
     * @param int $pagination
     * 
     * @return string
     */
    public static function prepare_callback($callback, $pagination)
    {
        if(preg_match('/\{pagination\}/', $callback))
        {
            $callback = preg_replace('/\{pagination\}/', $pagination, $callback);
        }
        else // If not present, add as last parameter
        {
            $callback = preg_replace('/\/+$/', '', $callback);
            $callback .= '/' . $pagination;
        }

        $host = Url::base();

        $callback = preg_replace('/^\/+/', '', $callback);
        $callback = $host . $callback;

        return $callback;
    }
}