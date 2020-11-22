<?php

/**
 * Forge class for making forms
 */
class Forge
{
    /**
     * Table title
     */
    protected $title;

    /**
     * Form rows holder
     */
    protected $items = [];

    /**
     * Variable prefix holder
     */
    protected $prefix = '';

    /**
     * Form action target
     */
    protected $target = '';

    /**
     * Form method
     */
    protected $method = "POST";

    /**
     * Submit button
     */
    protected $submit;

    /**
     * Has error?
     */
    protected $has_error = FALSE;

    /**
     * Constructor
     */
    function __construct($title, $target = '', $prefix = '')
    {
        $this->title = $title;
        $this->target = $target !== '' ? $target : $_SERVER['REQUEST_URI'];
        $this->prefix = $prefix;
    }

    /**
     * Adds new row to the form
     * 
     * @param string $name - Input name
     * 
     * @return ForgeItem
     */
    function add($name)
    {
        $item = new ForgeItem($name);
        $this->items[] = &$item;

        return $item;
    }

    /**
     * Adds sumbit button
     * 
     * @param string $title
     */
    function submit($title)
    {
        $this->submit = Html::button('submit', $title, 'btn-success pull-right');
    }

    /**
     * Adds error to the input
     * 
     * @param string $name
     * @param string $err_text
     */
    function error($name, $err_text)
    {
        $this->has_error = true;

        foreach($this->items as $item)
        {
            if($item->name === $name)
            {
                $item->error($err_text);
                break;
            }
        }
    }

    function has_error()
    {
        return $this->has_error;
    }

    /**
     * Fill variables with post values
     * 
     */
    function init_post()
    {
        foreach($this->items as $item)
        {
            $param = $this->prefix && $this->prefix !== '' ? $this->prefix . '_' . $item->name : $item->name;

            if(isset($_POST[$param]))
            {
                switch($item->type)
                {
                    case 'checkbox':
                        $item->checked = 'true';
                        break;

                    default:
                        $item->value = $_POST[$param];
                        break;
                }
            }
            else if($_POST)
            {
                switch($item->type)
                {
                    case 'checkbox':
                        $item->checked = 'false';
                        break;
                } 
            }
        }
    }

    /**
     * Generates final form from given settings
     * 
     * @return string HTML
     */
    public function form()
    {
        $result = '<div class="setting-form"><form method="' . $this->method . '" action="' . $this->target . '">';
        $result .= "<table class='setting-form-table'>";
        $result .= "<thead><tr><td> $this->title </td></tr></thead>";
        $result .= '<tbody>';

        foreach($this->items as $item)
        {
            $result .= '<tr><td>' . $item->title . '</td><td>';

            switch($item->type)
            {
                case 'checkbox':
                    $result .= '' . Html::checkbox_input($item->name, $item->value, $item->checked);
                    break;

                default:
                    $result .= '' . Html::text_input($item->name, $item->value);
                    break;
            }

            // add error if exists
            if($item->error && $item->error !== '')
            {
                $result .= "<div class='setting-form-error'>$item->error</div>";
            }
            $result .= '</td></tr>';
        }

        $result .= "</tbody></table>$this->submit</form></div>";

        return $result;
    }

}


/**
 * Forge item
 */
class ForgeItem
{
    /**
     * 
     */
    public $name;

    /**
     * 
     */
    public $title;

    /** 
     * 
     */
    public $value;

    /**
     * Type holder
     */
    public $type;
    
    /**
     * 
     */
    public $error;

    /**
     * 
     */
    public $checked;

    /**
     * Valid types
     */
    private static $valid_types = array
    (
        'text',
        'number',
        'checkbox'
    );

    /**
     * Constructor
     * 
     * @param string $name
     */
    function __construct($name)
    {
        $this->name = $name;
    }

    /**
     * Set title
     * 
     * @param string $title
     * 
     * @return ForgeItem
     */
    public function title($title)
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Set value
     * 
     * @param string $value
     * 
     * @return ForgeItem
     */
    public function value($value)
    {
        $this->value = $value;
        return $this;
    }

    /**
     * Is checked?
     * 
     * @param bool $ch
     */
    public function checked($ch)
    {
        if($ch === NULL || $ch === FALSE || strtolower($ch) === 'false' || $ch === '' || strval($ch) === '0')
        {
            $this->checked = 'false';
        }
        else
        {
            $this->checked = 'true';
        }
        return $this;
    }

    /**
     * Set type of input
     * 
     * @param string $type
     * 
     * @return ForgeItem
     */
    public function type($type)
    {
        if(!in_array($type, self::$valid_types))
        {
            throw new Exception('Invalid form type.');
        }

        $this->type = $type;
        return $this;
    }

    /**
     * Adds error text
     * 
     * @param string $err_text
     */
    public function error($err_text)
    {
        $this->error = $err_text;
        return $this;
    }

}