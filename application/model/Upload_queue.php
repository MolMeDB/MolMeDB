<?php

/**
 * Upload queue model
 * 
 * @property integer $id
 * @property integer $type
 * @property integer $id_dataset_passive
 * @property Datasets $dataset_passive
 * @property integer $id_dataset_active
 * @property Transporter_datasets $dataset_active
 * @property integer $id_user
 * @property Users $user
 * @property Files $file
 * @property integer $state
 * @property string $error_text
 * @property string $settings
 * @property string $run_info
 * @property string $create_date
 * 
 * @property Iterable_object $params
 * 
 * @author Jakub Juracka
 */
class Upload_queue extends Db
{
    // Types
    const TYPE_PASSIVE_DATASET = 1;
    const TYPE_ACTIVE_DATASET = 2;
    const TYPE_ENERGY = 3;

    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'upload_queue';
        parent::__construct($id);

        if($this->id && $this->settings)
        {
            $this->params = json_decode($this->settings);
        }
    }

    /**
     * Links
     */
    protected $has_one = array
    (
        'id_user',
        'id_file',
        'id_dataset_passive' => array
        (
            'var' => 'dataset_passive',
            'class' => 'Datasets'
        ),
        'id_dataset_active' => array
        (
            'var'   => 'dataset_active',
            'class' => 'Transporter_datasets'
        )
    );

    /** STATES */
    const STATE_PENDING = 0;
    const STATE_RUNNING = 1;
    const STATE_DONE = 2;
    const STATE_ERROR = 3;   
    const STATE_CANCELED = 4;   

    /**
     * Enum states
     */
    private static $enum_states = array
    (
        self::STATE_PENDING     => 'Pending',
        self::STATE_RUNNING     => 'Running',
        self::STATE_DONE        => 'Done',
        self::STATE_ERROR       => 'Error',
        self::STATE_CANCELED    => 'Canceled',
    );

    /**
     * @return string
     */
    public function get_enum_state()
    {
        if(!array_key_exists($this->state, self::$enum_states))
        {
            return null;
        }

        return self::$enum_states[$this->state];
    }

    /**
     * Starts queue job
     */
    public function start()
    {
        if(!$this->id || $this->state !== self::STATE_PENDING)
        {
            throw new MmdbException('Cannot start file uploader. Invalid object instance.');
        }

        try
        {
            if($this->id_dataset_passive)
            {
                $this->save_passive_dataset();
            }
            else if($this->id_dataset_active)
            {
                $this->save_active_dataset();
            }
        }
        catch(Exception $e)
        {
            $this->state = self::STATE_ERROR;
            $this->run_info = json_encode(['error' => $e->getMessage()]);
            $this->save();
            throw $e;
        }

        return;
    }

    /**
     * Saves passive interaction dataset
     * 
     */
    private function save_passive_dataset()
    {
        $msg = [];
        try
        {
            $setting = $this->params;

            $this->state = self::STATE_RUNNING;
            $this->save();

            Db::beginTransaction();

            if(!$setting->delimiter)
            {
                throw new MmdbException('Invalid queue setting. Cannot find file delimiter.');
            }

            if(!$setting->attributes)
            {
                throw new MmdbException('Invalid queue setting. Cannot find attributes order.');
            }

            if(!$setting->temperature)
            {
                throw new MmdbException('Invalid queue setting. Cannot find interactions temperature value.');
            }

            if(!file_exists($this->file->path))
            {
                throw new MmdbException('Invalid queue setting. Cannot find target file - try to re-upload it.');
            }

            $file = new File($this->file->path);
            $file_content = $file->parse_csv_content($setting->delimiter);
            $attrs = $setting->attributes;
            $temperature = $setting->temperature;
            $dataset = $this->dataset_passive;

            // Remove header
            array_shift($file_content);

            // process data
            $values = [];
            foreach($file_content as $row)
            {
                $new = array();

                foreach($attrs as $key => $attr)
                {
                    $new[$attr] = Upload_validator::get_attr_val($attr, $row[$key]);
                }

                $values[] = $new;
            }

            $line = 1;
            $no_error_lines = 0;

            // Make upload report
            $report = new Upload_report();
            $uploader = new Uploader();
            $interaction_model = new Interactions();

            // Add dataset rows
            foreach($values as $detail) 
            {
                try 
                {
                    $uploader->insert_interaction(
                        $this->dataset_passive->id,
                        self::get_value($detail, Upload_validator::PRIMARY_REFERENCE),
                        self::get_value($detail, Upload_validator::NAME),
                        self::get_value($detail, Upload_validator::NAME),
                        self::get_value($detail, Upload_validator::MW),
                        self::get_value($detail, Upload_validator::X_MIN),
                        self::get_value($detail, Upload_validator::X_MIN_ACC),
                        self::get_value($detail, Upload_validator::G_PEN),
                        self::get_value($detail, Upload_validator::G_PEN_ACC),
                        self::get_value($detail, Upload_validator::G_WAT),
                        self::get_value($detail, Upload_validator::G_WAT_ACC),
                        self::get_value($detail, Upload_validator::LOG_K),
                        self::get_value($detail, Upload_validator::LOG_K_ACC),
                        self::get_value($detail, Upload_validator::AREA),
                        self::get_value($detail, Upload_validator::VOLUME),
                        self::get_value($detail, Upload_validator::LOG_P),
                        self::get_value($detail, Upload_validator::LOG_PERM),
                        self::get_value($detail, Upload_validator::LOG_PERM_ACC), 
                        self::get_value($detail, Upload_validator::THETA),
                        self::get_value($detail, Upload_validator::THETA_ACC),
                        self::get_value($detail, Upload_validator::ABS_WL),
                        self::get_value($detail, Upload_validator::ABS_WL_ACC),
                        self::get_value($detail, Upload_validator::FLUO_WL),
                        self::get_value($detail, Upload_validator::FLUO_WL_ACC),
                        self::get_value($detail, Upload_validator::QY),
                        self::get_value($detail, Upload_validator::QY_ACC),
                        self::get_value($detail, Upload_validator::LT),
                        self::get_value($detail, Upload_validator::LT_ACC),
                        self::get_value($detail, Upload_validator::Q),
                        self::get_value($detail, Upload_validator::COMMENT),
                        self::get_value($detail, Upload_validator::SMILES),
                        self::get_value($detail, Upload_validator::DRUGBANK),
                        self::get_value($detail, Upload_validator::PUBCHEM),
                        self::get_value($detail, Upload_validator::PDB),
                        self::get_value($detail, Upload_validator::CHEMBL_ID),
                        self::get_value($detail, Upload_validator::CHEBI_ID),
                        $this->dataset_passive->membrane,
                        $this->dataset_passive->method,
                        $temperature
                    );

                    $no_error_lines++;
                }
                catch(UploadLineException $e)
                {   
                    $report->add($line, $e->getMessage());
                }

                $line++;
            }

            $line--;

            // Save final messages
            $msg = array
            (
                'success' => [],
                'error'   => [],
                'warning' => []
            );

            // Show report message if not empty
            if (!$report->is_empty()) 
            {
                $msg['error'][] = 'Some lines[' . $report->count() . '] weren\'t saved.<br/><a href="/' . $report->get_report() . '">Download report</a>';
            }

            // Get number of interactions in dataset
            $total_interactions = $interaction_model->where('id_dataset', $dataset->id)->count_all();

            if(!$total_interactions)
            {
                if($dataset->is_deletable())
                {
                    $dataset->delete();
                }

                $msg['warning'][] = 'No interactions added to the dataset. Perhaps, an error occured during processing each line. New dataset can be discarded.';
            }

            if($no_error_lines != $line)
            {
                $msg['warning'][] = ($line - $no_error_lines) . " values were probably assigned to the another datasets.";
            }

            $total_saved = $line - $report->count();
            if($total_saved > 0)
            {
                $msg['success'][] = "$total_saved lines successfully saved!";
            }

            // Save final messages
            $this->state = self::STATE_DONE;

            Db::commitTransaction();
        }
        catch(Exception $e)
        {
            Db::rollbackTransaction();
            $this->state = self::STATE_ERROR;
            throw new MmdbException($e->getMessage(),$e->getMessage(), $e->getCode(), $e) ;
        }

        $this->run_info = json_encode($msg);
        $this->save();
    }

    /**
     * Saves active interaction dataset
     * 
     */
    private function save_active_dataset()
    {
        $msg = [];
        try
        {
            $setting = $this->params;
            $this->state = self::STATE_RUNNING;
            $this->save();

            Db::beginTransaction();

            if(!$setting->delimiter)
            {
                throw new MmdbException('Invalid queue setting. Cannot find file delimiter.');
            }

            if(!$setting->attributes)
            {
                throw new MmdbException('Invalid queue setting. Cannot find attributes order.');
            }

            if(!file_exists($this->file->path))
            {
                throw new MmdbException('Invalid queue setting. Cannot find target file - try to re-upload it.');
            }

            $file = new File($this->file->path);
            $file_content = $file->parse_csv_content($setting->delimiter);
            $attrs = $setting->attributes;
            $dataset = $this->dataset_active;

            // Remove header
            array_shift($file_content);

            // process data
            $values = [];
            foreach($file_content as $row)
            {
                $new = array();

                foreach($attrs as $key => $attr)
                {
                    $new[$attr] = Upload_validator::get_attr_val($attr, $row[$key]);
                }

                $values[] = $new;
            }

            $line = 1;
            $no_error_lines = 0;

            // Make upload report
            $report = new Upload_report();
            $uploader = new Uploader();
            $interaction_model = new Transporters();

            // Add dataset rows
            foreach($values as $detail) 
            {
                try 
                {
                    $uploader->insert_transporter(
                        $dataset,
                        self::get_value($detail, Upload_validator::NAME),
                        self::get_value($detail, Upload_validator::SMILES),
                        self::get_value($detail, Upload_validator::MW),
                        self::get_value($detail, Upload_validator::LOG_P),
                        self::get_value($detail, Upload_validator::PDB),
                        self::get_value($detail, Upload_validator::PUBCHEM),
                        self::get_value($detail, Upload_validator::DRUGBANK),
                        self::get_value($detail, Upload_validator::UNIPROT_ID),
                        self::get_value($detail, Upload_validator::PRIMARY_REFERENCE),
                        self::get_value($detail, Upload_validator::TYPE),
                        self::get_value($detail, Upload_validator::TARGET),
                        self::get_value($detail, Upload_validator::IC50),
                        self::get_value($detail, Upload_validator::EC50),
                        self::get_value($detail, Upload_validator::KI),
                        self::get_value($detail, Upload_validator::KM),
                        self::get_value($detail, Upload_validator::IC50_ACC),
                        self::get_value($detail, Upload_validator::EC50_ACC),
                        self::get_value($detail, Upload_validator::KI_ACC),
                        self::get_value($detail, Upload_validator::KM_ACC),
                        self::get_value($detail, Upload_validator::COMMENT)
                    );

                    $no_error_lines++;
                }
                catch(UploadLineException $e)
                {   
                    $report->add($line, $e->getMessage());
                }

                $line++;
            }

            $line--;

            // Save final messages
            $msg = array
            (
                'success' => [],
                'error'   => [],
                'warning' => []
            );

            // Show report message if not empty
            if (!$report->is_empty()) 
            {
                $msg['error'][] = 'Some lines[' . $report->count() . '] weren\'t saved.<br/><a href="/' . $report->get_report() . '">Download report</a>';
            }

            // Get number of interactions in dataset
            $total_interactions = $interaction_model->where('id_dataset', $dataset->id)->count_all();

            if(!$total_interactions)
            {
                if($dataset->is_deletable())
                {
                    $dataset->delete();
                }

                $msg['warning'][] = 'No interactions added to the dataset. Perhaps, an error occured during processing each line. New dataset can be discarded.';
            }

            if($no_error_lines != $line)
            {
                $msg['warning'][] = ($line - $no_error_lines) . " values were probably assigned to the another datasets.";
            }

            $total_saved = $line - $report->count();
            if($total_saved > 0)
            {
                $msg['success'][] = "$total_saved lines successfully saved!";
            }

            // Save final messages
            $this->state = self::STATE_DONE;
            Db::commitTransaction();
        }
        catch(Exception $e)
        {
            Db::rollbackTransaction();
            $this->state = self::STATE_ERROR;
            throw new MmdbException($e->getMessage(),'', $e->getCode(), $e) ;
        }

        $this->run_info = json_encode($msg);
        $this->save();
    }

    /**
     * Returns value for array if exists
     * 
     * @param array $arr
     * @param string $attr - Attribute
     * 
     */
    private static function get_value($arr, $attr)
    {
        if(!is_array($arr) || !array_key_exists($attr, $arr))
        {
            return NULL;
        }

        return $arr[$attr];
    }
}
