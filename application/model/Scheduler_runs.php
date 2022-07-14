<?php

/**
 * Holds info about scheduler runs
 * 
 * @property int $id
 * @property int $pid
 * @property string $method
 * @property string $params
 * @property int $status
 * @property int $id_exception
 * @property Exceptions $exception
 * @property string $note
 * @property string $datetime
 * 
 * @author Jakub Juracka
 */
class Scheduler_runs extends Db
{
    /** JOB STATES */
    const STATE_RUNNING = 1;
    const STATE_DONE = 2;
    const STATE_ERROR = 3;

    private static $valid_status = array
    (
        self::STATE_DONE,
        self::STATE_ERROR,
        self::STATE_RUNNING
    );

    /**
     * Constructor
     */
    function __construct($id = null)
    {
        $this->table = 'scheduler_runs';
        parent::__construct($id);
    }

    /**
     * Returns last record by method name
     * 
     * @param string $name
     * 
     * @return Scheduler_runs
     */
    public function get_by_method_name($name, $status = null)
    {
        $where = ['method' => $name];

        if($status && in_array($status, self::$valid_status))
        {
            $where['status'] = $status;
        }

        return $this->where($where)->order_by('id', 'DESC')->get_one();
    }

    /**
     * Checks, if process is still running
     * 
     * @param int $pid
     * 
     * @return boolean
     */
    public function is_pid_running($pid = null)
    {
        if(!$pid && $this->pid)
        {
            $pid = $this->pid;
        }

        if(!$pid)
        {
            return null;
        }

        return file_exists("/proc/$pid");
    }

    /**
     * Reports process crash
     * 
     * @param int $pid
     *
     */
    public function report_crash($pid, $prev_exception = null)
    {
        $all = $this->where('pid', $pid)->get_all();

        // Save exception
        $ex = new SchedulerException('Unreported end of process.', '',0, $prev_exception);

        foreach($all as $r)
        {
            $r->status = self::STATE_ERROR;
            $r->id_exception = $ex->getExceptionId();
            $r->save();
        }
    }
    
    /**
     * String format 
     * 
     * @return string
     */
    public function __toString()
    {
        return "PID:" . $this->pid;
    }

    /**
     * Changes status of process
     */
    public function change_status($status)
    {
        if(!in_array($status, self::$valid_status))
        {
            return;
        }

        $this->status = $status;
        $this->save();
    }
}