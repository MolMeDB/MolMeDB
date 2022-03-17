<?php

/**
 * Creates basic HTML table element
 * 
 * @author Jakub Juracka
 */
class Table
{
    /**
     * Holds info about table columns
     * 
     * @var array
     */
    private $columns = [];

    /**
     * @var Iterable_object[]
     */
    private $datasource = [];

    /**
     * @var bool
     */
    private $add_row_numbers = false;

    /**
     * @var bool
     */
    private $sorting = true;

    /**
     * @var array
     */
    private $css = [];

    /** Pagination setting */
    private $show_pagination = TRUE;
    private $per_page_options = [10,25,50,100,500];
    private $total_per_page = 10;
    private $active_page = 1;
    private $paginator_position = 'right';

    /** SETTINGS ATTRIBUTES */
    const S_SHOW_PAGINATION = 'show_pagination';
    const S_ACTIVE_PAGE     = 'active_page';
    const S_TOTAL_PER_PAGE  = 'total_per_page';
    const S_PER_PAGE_OPTIONS= 'per_page_options';
    const S_PAGINATIOR_POSITION = 'paginator_position';
    const S_CSS             = 'css';
    
    // SETTING FILTERS
    const FILTER_VISIBLE        = 'filter_visible';
    const FILTER_HIDE_COLUMNS      = 'hide_empty_columns';
    const FILTER_HIDE_COLUMNS_DEFAULT      = 'hide_empty_columns_default';

    /**
     * Filters holder
     */
    private $filters = array();


    /**
     * Constructor
     * 
     * @param array $setting
     */
    function __construct(array $setting = [])
    {
        // Parse input params
        $s = new Iterable_object($setting);

        if($s->show_pagination !== null)
        {
            $this->show_pagination = $s->show_pagination;
        }

        if($s->active_page)
        {
            $this->active_page = $s->active_page;
        }

        if($s->total_per_page)
        {
            $this->total_per_page = $s->total_per_page;
        }

        if($s->css)
        {
            $this->css = $s->css;
        }

        if($s->paginator_position)
        {
            $this->paginator_position = $s->paginator_position;
        }

        if($s->hide_empty_columns)
        {
            $this->filters[self::FILTER_HIDE_COLUMNS] = $s->hide_empty_columns;
        }

        if($s->filter_visible)
        {
            $this->filters[self::FILTER_VISIBLE] = $s->filter_visible;
        }

        if($s->hide_empty_columns_default)
        {
            $this->filters[self::FILTER_HIDE_COLUMNS_DEFAULT] = $s->hide_empty_columns_default;
        }

        if($s->per_page_options && 
            is_array($s->per_page_options) && 
            !empty($s->per_page_options))
        {
            // Check
            $t = array_map('is_numeric', $s->per_page_options);

            if(!in_array(false, $t))
            {
                $this->per_page_options = $s->per_page_options;
            }
        }
    }

    /**
     * Prints CSS
     */
    private function css()
    {
        $res = '';

        foreach($this->css as $key => $val)
        {
            $res .= "$key:$val;";
        }

        return $res;
    }

    /**
     * Checks, if filter row should be visible
     * 
     * @return bool
     */
    public function has_filter()
    {
        return (isset($this->fitlers[self::FILTER_VISIBLE]) && 
            $this->filters[self::FILTER_VISIBLE]) || 
            (isset($this->filters[self::FILTER_HIDE_COLUMNS]) && 
                $this->filters[self::FILTER_HIDE_COLUMNS]);
    }

    /**
     * Pagination setting
     * 
     * @param int $active_page 
     * @param int $items_per_page
     * 
     * @return Table
     */
    public function pagination($active_page = 1, $total_per_page = 10)
    {
        $this->active_page = $active_page;
        $this->total_per_page = $total_per_page;

        return $this;
    }

    /**
     * Disabled pagination and shows all rows
     * 
     * @return Table
     */
    public function show_all()
    {
        $this->show_pagination = false;
        return $this;
    }

    /**
     * Adds new column for current instance
     * 
     * @param string $key - Attribute key
     * 
     * @return Table_column
     */
    public function column($key = NULL)
    {
        $t = new Table_column($key);
        $this->columns[] = &$t;
        return $t;
    }

    /**
     * Adds row numbers (not from attributes)
     * 
     * @return Table
     */
    public function add_row_numbers()
    {
        $this->add_row_numbers = true;
        return $this;
    }

    /**
     * Disalbes/enables sorting of table
     * 
     * @param bool $sort_table - Should be table sortable?
     * 
     * @return Table
     */
    public function sorting(bool $sort_table)
    {
        if(is_bool($sort_table))
        {
            $this->sorting = $sort_table;
        }
        return $this;
    }

    /**
     * Overloading of __toString function
     * 
     * @return string
     */
    public function __toString() : string
    {
        return $this->html();
    }

    /**
     * Prints final html
     * 
     * @return string
     */
    public function html() : string
    {
        $output = new View('table/basic');

        $output->columns = $this->columns;
        $output->data = $this->datasource;
        $output->sorting_enabled = $this->sorting;
        $output->show_row_numbers = $this->add_row_numbers;
        $output->empty_data = count($this->datasource) < 1;
        $output->total_rows = count($this->datasource);
        $output->show_pagination = $this->show_pagination;
        $output->active_page = $this->active_page;
        $output->css = $this->css();
        $output->paginator_position = $this->paginator_position;
        $output->has_filter = $this->has_filter();
        $output->default_hide_empty_columns = isset($this->filters[self::FILTER_HIDE_COLUMNS_DEFAULT]) ? $this->filters[self::FILTER_HIDE_COLUMNS_DEFAULT] : false;
        
        $output->total_columns = count($this->columns);
        
        if($this->add_row_numbers)
        {
            $output->total_columns = $output->total_columns+1;
        }

        $output->per_page_options = $this->per_page_options;

        if(in_array($this->total_per_page, $this->per_page_options))
        {
            $output->total_per_page = $this->total_per_page;
        }
        else
        {
            $output->total_per_page = $this->per_page_options[0];
        }

        // Paginator info
        if($this->show_pagination)
        {
            $output->pag_from = (($this->active_page-1)*$this->total_per_page)+1;
            $output->pag_to = $this->active_page * $this->total_per_page;
            $output->pag_to = $output->pag_to > $output->total_rows ? $output->total_rows : $output->pag_to;
        }
        else
        {
            $output->pag_from = 1;
            $output->pag_to = $output->total_rows;
        }

        if(empty($this->columns))
        {
            return '';
        }
        
        return $output->__toString();
    }

    /**
     * Adds datasource for given table
     * 
     * @param Iterable_object|array $data
     */
    public function datasource($data)
    {
        $this->datasource = $data;
    }
}

/**
 * Table column class
 * 
 * @author Jakub Juracka
 */
class Table_column
{
    /**
     * @var string $key
     */
    private $key;

    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $tooltip;

    /**
     * @var string 
     */
    private $help;

    /**
     * @var bool
     */
    private $sortable = true;

    /**
     * @var string
     */
    private $callback;

    /**
     * @var array
     */
    private $callback_params = [];

    /**
     * @var array
     */
    private $css = [];

    /**
     * @var array
     */
    private $header_css = [];

    /**
     * @var bool
     */
    private $is_long_text = false;

    /**
     * @var bool
     */
    private $is_numeric = false;

    /**
     * @var string
     */
    private $link_prefix;

    /**
     * @var string
     */
    private $link_suffix_key;

    /**
     * @var bool
     */
    private $link_new_tab;

    /**
     * @var string
     */
    private $acc_key;

    /**
     * @var string
     */
    private $acc_suffix;

    /**
     * Constructor
     * 
     * @param string $key
     */
    function __construct($key = NULL)
    {
        $this->attribute($key);
    }

    /**
     * Returns properties
     */
    public function __get($attr)
    {
        if(property_exists($this, $attr))
        {
            return $this->$attr;
        }

        return null;
    }

    /**
     * Sets indicator, that given value can be long text
     * 
     * @return Table_column
     */
    public function long_text()
    {
        $this->is_long_text = true;
        return $this;
    }

    /**
     * Sets indicator, that given value is numeric
     * 
     * @return Table_column
     */
    public function numeric()
    {
        $this->is_numeric = true;
        return $this;
    }

    /**
     * Set width of column
     * 
     * @param float $width
     * 
     * @return Table_column
     */
    public function width($width)
    {
        if(is_numeric($width))
        {
            $this->css['width'] = $width . 'px';
        }
        return $this;
    }

    /**
     * Sets CSS for whole column
     * 
     * @param array $css
     * 
     * @return Table_column|string
     */
    public function css($css = null, $include_headers = false)
    {
        if($css === null)
        {
            $result = '';

            foreach($this->css as $key => $val)
            {
                $result .= "$key: $val;";
            }

            return $result;
        }
        else if(is_array($css))
        {
            if($include_headers)
            {
                $this->header_css($css);
            }

            foreach($css as $key => $val)
            {
                if(is_numeric($key))
                {
                    continue;
                }

                $this->css[$key] = $val;
            }

            return $this;
        }
    }

    /**
     * Sets CSS for column header
     * 
     * @param array $css
     * 
     * @return Table_column|string
     */
    public function header_css($css = null, $value = null)
    {
        if($value && is_string($css))
        {
            $this->header_css[$css] = $value;
        }
        else if(is_array($css))
        {
            foreach($css as $key => $val)
            {
                if(is_numeric($key))
                {
                    continue;
                }

                $this->header_css[$key] = $val;
            }
        }
        else if(!$css)
        {
            $result = '';

            // Is sortable? Change cursor
            if($this->sortable)
            {
                $this->header_css('cursor', 'pointer');
            }

            foreach($this->header_css as $key => $val)
            {
                $result .= "$key: $val;";
            }

            return $result;
        }
    }


    /**
     * Add column name
     * 
     * @param string $name
     * 
     * @return Table_column
     */
    public function title($name, $tooltip = '')
    {
        $this->name = $name;
        $this->tooltip = $tooltip;
        return $this;
    }

    /**
     * Add attribute key
     * 
     * @param string $attribute
     * 
     * @return Table_column
     */
    public function attribute($attr)
    {
        $this->key = $attr;
        return $this;
    }

    /**
     * Sets callback for value
     * 
     * @param int $callback
     * 
     * @return Table_column
     */
    public function callback($callback)
    {
        $this->callback = $callback;
        $all_args = func_get_args();

        // Remove first argument
        array_shift($all_args);
        $this->callback_params = $all_args;

        return $this;
    }

    /**
     * Invokes callback function and returns result
     * 
     * @return mixed
     */
    public function invoke_callback($value)
    {
        if(!$this->callback)
        {
            return $value;
        }

        $params = array_merge([$value], $this->callback_params);

        return call_user_func($this->callback, ...$params);
    }

    /**
     * Should be column sortable?
     * 
     * @param bool $sortable
     * 
     * @return Table_column
     */
    public function sortable($sortable = TRUE)
    {
        if(is_bool($sortable))
        {
            $this->sortable = $sortable;
        }
        return $this;
    }

    /**
     * Adds helper content
     * 
     * @param string $help
     * 
     * @return Table_column
     */
    public function help($help)
    {
        $this->help = $help;
        return $this;
    }

    /**
     * Returns value of given column for given object
     * 
     * @param Iterable_object $object
     * 
     * @return int|float|double|string|null
     */
    public function value($object, $invoke_callback = true)
    {
        if(!is_object($object))
        {
            throw new Exception('Invalid instance of object.');
        }

        $attribute = $this->key;
        $as = explode('.',$attribute);

        $value = $object;

        foreach($as as $k)
        {
            if(!$k)
            {
                continue;
            }

            $value = $value->$k;
        }

        $value = $invoke_callback ? $this->invoke_callback($value) : $value;

        if($this->link_prefix && $this->link_suffix_key)
        {
            $suff = $object;
            $as = explode('.', $this->link_suffix_key);

            foreach($as as $k)
            {
                if(!$k)
                {
                    continue;
                }

                $suff = $suff->$k;
            }

            $value = Html::anchor($this->link_prefix . $suff, $value, $this->link_new_tab);
        }

        return $value;
    }

    
    /**
     * Returns accuracy value of given column for given object
     * 
     * @param Iterable_object $object
     * @param bool $invoke_callback - Call callback on accuracy value?
     * 
     * @return float|null
     */
    public function acc_value($object, $invoke_callback = false)
    {
        if(!($object instanceof Iterable_object))
        {
            throw new Exception('Invalid instance of object.');
        }

        if(!$this->acc_key)
        {
            return;
        }

        $attribute = $this->acc_key;
        $as = explode('.',$attribute);

        $value = $object;

        foreach($as as $k)
        {
            if(!$k)
            {
                continue;
            }

            $value = $value->$k;
        }

        if($value === null)
        {
            return;
        }

        $value = $invoke_callback ? $this->invoke_callback($value) : $value;

        return $this->acc_prefix . " $value";
    }

    /**
     * Creates link from printed value
     * 
     * @param string $prefix
     * @param string $key - used as suffix
     * 
     * @return Table_column
     */
    public function link($prefix, $key, $new_tab = true)
    {
        $this->link_prefix = $prefix;
        $this->link_suffix_key = $key;
        $this->link_new_tab = $new_tab;

        return $this;
    }

    /**
     * Adds accuracy for numeric values
     * 
     * @param string $key
     * 
     * @return Table_column
     */
    public function accuracy($key, $prefix = '+/-')
    {
        // Must be numeric
        $this->is_numeric = true;
        $this->acc_key = $key;
        $this->acc_prefix = $prefix;
        return $this;
    }
}