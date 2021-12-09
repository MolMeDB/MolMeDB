<?php

/**
 * Class for handling validator fragmentation info
 * 
 * @author Jakub Juracka
 */
class Validator_fragmentation extends Validator_state
{
    /**
     * Object handler
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
     * Current instance variables
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
            ($record->type == self::TYPE_FRAGMENTED || $record->type == self::TYPE_FRAGMENT_ERROR))
        {
            $this->params = $record->values;
            $this->record = $record;

            // Fill instance variables
            $this->type = $record->type;

            if($record->type == self::TYPE_FRAGMENTED)
            {
                $this->message = 'Molecule was successfully fragmented.';
            }
            else
            {
                $this->message = 'Error occured during molecule fragmentation.';
            }
        }
    }

    /**
     * Adds new fragmentation info
     * 
     * @param Substances $substance
     * @param int $type
     * @param string|null $additional_info
     * 
     * @return Validator - Returns instance of Validator record
     * 
     * @author Jakub Juracka
     */
    public static function add($substance, $type, $additional_info = NULL)
    {
        if($type !== self::TYPE_FRAGMENT_ERROR &&
            $type !== self::TYPE_FRAGMENTED)
        {
            throw new Exception('Invalid message type.');
        }

        if(!$substance->id)
        {
            throw new Exception('Invalid substance record.');
        }

        $record = new Validator();

        try
        {
            $record->beginTransaction();

            // If fragmentation done, close all previous fragmentation errors
            if($type == self::TYPE_FRAGMENTED)
            {
                $exists = Validator::factory()->where(array
                    (
                        'id_substance_1'    => $substance->id,
                        'type'              => self::TYPE_FRAGMENT_ERROR,
                        'state !='          => Validator::STATE_CLOSED   
                    ))
                    ->get_all();

                foreach($exists as $r)
                {
                    $r->state = Validator::STATE_CLOSED;
                    $r->save();
                }
            }

            // Check, if already exists
            $record = Validator::factory()->where(array
            (
                'id_substance_1'    => $substance->id,
                'type'              => $type
            ))->get_one();

            if($record->id)
            {
                $record->dateTime = date('Y-m-d H:i:s', strtotime('now'));
                $record->save();
            }
            else
            {
                $record->id_substance_1 = $substance->id;
                $record->id_substance_2 = NULL;
                $record->type = $type;
                $record->state = $type == self::TYPE_FRAGMENTED ? Validator::STATE_CLOSED : Validator::STATE_OPENED;
                $record->description = $additional_info;

                $record->save();
            }

            $record->commitTransaction();
        }
        catch(Exception $e)
        {
            $record->rollbackTransaction();
            throw new Exception($e->getMessage());
        }

        return new Validator_fragmentation(new Validator($record->id));
    }
}