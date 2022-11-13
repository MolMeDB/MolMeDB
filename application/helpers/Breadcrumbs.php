<?php

/**
 * Breadcrumbs implementation
 * 
 * @author Jakub Juracka
 */
class Breadcrumbs 
{
    /** @var Breadcrumb_item[] */
    private $items = [];

    /**
     * Constructor
     */
    function __construct() {}

    /**
     * Instance maker
     * 
     * @return Breadcrumbs
     */
    public static function instance()
    {
        return new Breadcrumbs();
    }

    /**
     * Adds new breadcrumb to the list
     * 
     * @param string $title
     * @param string $anchor
     * 
     * @return Breadcrumbs
     */
    public function add($title, $anchor = NULL, $is_active = TRUE)
    {
        $this->items[] = new Breadcrumb_item($title, $anchor, $is_active);
        return $this;
    }

    /**
     * Return string formated
     * 
     * @return string
     */
    public function __toString()
    {
        if(!count($this->items))
        {
            return '';
        }

        $sp = '&nbsp;&Gt;&nbsp;';
        $first = array_shift($this->items);

        $r = $first->__toString();

        foreach($this->items as $i)
        {
            $r .= $sp . $i->__toString(); 
        }

        return $r;
    }
}

/**
 * Breadcrumb item
 */
class Breadcrumb_item
{
    /** @var string */
    private $title;

    /** @var string */
    private $anchor;

    /** @var bool */
    private $is_active;

    /**
     * Constructor
     */
    function __construct($title, $anchor = NULL, $is_active = TRUE)
    {
        $this->title = $title;
        $this->anchor = $anchor;
        $this->is_active = $is_active;
    }

    /**
     * Formats anchor
     * 
     * @return string
     */
    private function format_anchor()
    {
        $a = trim($this->anchor);

        if(!$a)
        {
            return null;
        }

        if(preg_match('/^http/', $a))
        {
            return $a;
        }

        $a = ltrim($a, '/');

        return Url::base() . $a;
    }


    /**
     * Print
     * 
     * @return string
     */
    public function __toString()
    {
        if($this->anchor && $this->is_active)
        {
            return Html::anchor($this->format_anchor(), $this->title);
        }
        else
        {
            return '<span>' . $this->title . '</span>';
        }
    }
}