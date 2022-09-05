<?php

/**
 * Renders multiple selector
 * 
 * @author Jakub Juracka
 */
class Render_multiple_selector extends Render
{
    /**
     * Required setting
     * 
     * @var array
     */
    private $required_setting = array
    (
        'title',
        'item_value',
        'item_label',
        'item_category'
    );

    /**
     * @var array
     */
    private $setting = array
    (
        "item_category" => "category", // Default value
        'item_sublabel' => null,
        'move_right_callback' => null,
        'move_left_callback' => null,
    );

    /**
     * @var string
     */
    private $name;
    private $raw_name;

    /**
     * @var array
     */
    private $dataset;

    /**
     * Unique prefix
     * 
     * @var string
     */
    private $prefix;

    /**
     * Constructor
     */
    function __construct($name, $setting)
    {
        $this->name = $name;
        $this->raw_name = $name;

        if(!$this->name)
        {
            throw new MmdbException('Invalid name parameter.');
        }

        foreach($this->required_setting as $s)
        {
            if(!isset($setting[$s]))
            {
                throw new MmdbException("Missing $s parameter.", "Missing $s parameter.");
            }

            $this->setting[$s] = $setting[$s];
            unset($setting[$s]);
        }

        // Save another setting
        foreach($setting as $key => $val)
        {
            $this->setting[$key] = $val;
        }

        $this->prefix = (strtotime('now') + rand(0,10000)) . '_';
    }


    /**
    * Assignes dataset
    * @param array $dataset
     */
    public function dataset($dataset)
    {
        $this->dataset = $dataset;
    }


    /**
     * Prepares data for view
     */
    function prepare()
    {
        $t = $this->dataset;
        $this->dataset = [];

        $this->name = preg_replace('/\s+/', '_', $this->name);

        if(!$this->name)
        {
            $this->name = rand(0,1000) . '_' . strtotime('now');
        }

        if(!is_array($t))
        {
            return;
        }

        // Attributes
        $label_a = $this->setting['item_label'];
        $value_a = $this->setting['item_value'];
        $cat_a = $this->setting['item_category'];
        $sublabel = $this->setting['item_sublabel'];

        foreach($t as $row)
        {
            if(!isset($row[$label_a]) || !isset($row[$value_a]))
            {
                continue;
            }

            $category = $row[$cat_a] ? $row[$cat_a] : 0;

            if($category === 0)
            {
                $category = 'Unclassified';
            }

            if(!isset($this->dataset[$category]))
            {
                $this->dataset[$category] = array();
            }

            $this->dataset[$category][] = (object)array
            (
                'name' => $row[$label_a],
                'value' => $row[$value_a],
                'subtitle' => $sublabel ? $row[$sublabel] : null
            );
        }
    }

    /**
     * Returns html with selector
     * 
     * @return string
     */
    function render()
    {
        $this->prepare();

        $view = new View('render/multiple_selector');
        $view->dataset = $this->dataset;
        $view->prefix = $this->name;
        $view->name = $this->raw_name;
        $view->title = $this->setting['title'];
        $view->move_right_callback = $this->setting['move_right_callback'];
        $view->move_left_callback = $this->setting['move_left_callback'];

        return $view->render(false);
    }

    /**
     * 
     */
    function __toString()
    {
        return $this->render();
    }
}