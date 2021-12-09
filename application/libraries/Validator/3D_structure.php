<?php

/**
 * Class for handling molecule 3D structure files changes
 * 
 * @author Jakub Juracka
 */
class Validator_3D_structure extends Validator_state
{
    /**
     * Object handler
     * 
     * @var Validator
     */
    protected $record;

    /**
     * Printable message
     */
    protected $message;

    /**
     * Message parameters
     * @var Validation_values[]
     */
    protected $params = [];

    /**
     * Types of message params
     */
    protected $message_params_types = array();

    /**
     * Instance info
     */
    public $type;

    /**
     * Constructor
     * 
     * @param Validator $record
     */
    public function __construct($record = NULL)
    {
        if($record && $record->id && 
            ($record->type == self::TYPE_3D_STRUCTURE_ADDED || $record->type == self::TYPE_3D_STRUCTURE_EDITED))
        {
            $this->params = $record->values;
            $this->record = $record;
            $this->type = $record->type;

            if($record->type == self::TYPE_3D_STRUCTURE_ADDED)
            {
                $this->message = 'New 3D structure [@] added from [@].';
                $this->message_params_types = array
                (
                    self::VAR_FILE,
                    self::VAR_SOURCE
                );
            }
            else
            {
                $this->message = '3D structure file edited. Old [@], New [@]. Source: [@].';
                $this->message_params_types = array
                (
                    self::VAR_FILE,
                    self::VAR_FILE,
                    self::VAR_SOURCE
                );
            }
        }
    }

    /**
     * Returns instance source
     * 
     * @return int
     */
    public function get_source()
    {
        if(!$this->record || !$this->type)
        {
            return null;
        }

        // Get index of source param
        $key = array_search(self::VAR_SOURCE, $this->message_params_types);

        if($key === false || !$this->params[$key])
        {
            return null;
        }

        return $this->params[$key]->value;
    }

    /**
     * Adds new 3D file info
     * 
     * @param Substances $substance
     * @param int $type
     * @param string $source
     * @param File $new_file
     * @param File $old_file
     * @param string $source_url
     * @param string $additional_info
     * 
     * @return Validator_3D_structure - Returns instance of the new record
     * 
     * @author Jakub Juracka
     */
    public static function add($substance, $type, $source, $new_file, $old_file = NULL, $source_url = NULL, $additional_info = NULL)
    {
        if($type !== self::TYPE_3D_STRUCTURE_ADDED &&
            $type !== self::TYPE_3D_STRUCTURE_EDITED)
        {
            throw new Exception('Invalid message type.');
        }

        if(!$new_file || !$new_file->path)
        {
            throw new Exception('Invalid new_file parameter.');
        }

        if($type == self::TYPE_3D_STRUCTURE_ADDED && $old_file !== NULL)
        {
            throw new Exception('Invalid validation type.');
        }

        if($type == self::TYPE_3D_STRUCTURE_EDITED && (!$old_file || !$old_file->path))
        {
            throw new Exception('Invalid old_file parameter.');
        }

        if(!$substance->id)
        {
            throw new Exception('Invalid substance record.');
        }

        $record = new Validator();

        try
        {
            $record->beginTransaction();

            $record->id_substance_1 = $substance->id;
            $record->id_substance_2 = NULL;
            $record->type = $type;
            $record->state = Validator::STATE_OPENED;
            $record->description = $additional_info;

            $record->save();

            // Add info
            $val = new Validator_value();
            $val->id_validation = $record->id;
            $val->value = $new_file->path;
            $val->save();

            if($record->type == self::TYPE_3D_STRUCTURE_EDITED)
            {
                $val = new Validator_value();
                $val->id_validation = $record->id;
                $val->value = $old_file->path;
                $val->save();
            }

            // Add source info
            self::add_source($record->id, $source, $source_url);

            $record->commitTransaction();
        }
        catch(Exception $e)
        {
            $record->rollbackTransaction();
            throw new Exception($e->getMessage());
        }

        return new Validator_3D_structure(new Validator($record->id));
    }
}