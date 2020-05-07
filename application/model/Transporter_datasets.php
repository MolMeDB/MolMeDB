<?php

/**
 * Transporter dataset class
 * 
 * @property integer $id
 * @property integer $visibility
 * @property string $name
 * @property integer $id_reference
 * @property integer $id_user_upload
 * @property integer $id_user_edit
 * @property datetime $update_datetime
 * @property datetime $create_datetime
 */
class Transporter_datasets extends Db
{
    /** VISIBILITY CONSTANTS */
	const VISIBLE = 1;
	const INVISIBLE = 2;

	private $enum_visibilities = array
	(
		self::VISIBLE => 'Visible',
		self::INVISIBLE => 'Invisible'
	);

    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'transporter_datasets';
        parent::__construct($id);
    }

    /** Links to other tables */
    public $has_one = array
    (
        'id_reference' => array
        (
            'var' => 'reference',
            'class' => 'Publications'
        ),
        'id_user_upload' => array
        (
            'var' => 'author',
            'class' => 'Users'
        ),
        'id_user_edit' => array
        (
            'var' => 'editor',
            'class' => 'Users'
        )
    );

    /**
	 * Gets enum visibility by const number
	 * 
	 * @param integer $visibility
	 * 
	 * @return string
	 */
	public function get_enum_visibility($visibility = NULL)
	{
		if(!$visibility && !$this)
		{
			return '';
		}

		if(!$visibility)
		{
			$visibility = $this->visibility;
		}

		if(!array_search($visibility, $this->enum_visibilities))
		{
			return $this->enum_visibilities[$visibility];
		}

		return '';
	}  
}