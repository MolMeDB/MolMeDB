<?php

/**
 * Holds info about COSMO upload data
 * 
 * @property int $id
 * @property string $comment
 * @property int $id_user
 * @property Users $user
 * @property string $token
 * @property int $id_file
 * @property Files $file
 * @property string $create_date
 * 
 * @property Run_cosmo[] $cosmo_runs
 * 
 * @author Jakub Juračka
 */
class Run_cosmo_datasets extends Db
{
    /**
     * Constructor
     */
    function __construct($id = null)
    {
        $this->table = 'run_cosmo_datasets';
        parent::__construct($id);
    }

    protected $has_one = array
    (
        'id_user',
        'id_file'
    );

    protected $has_many_and_belongs_to = array
    (
        'run_cosmo_cosmo_datasets' => array
        (
            'id_run_cosmo' => array
            (
                'var' => 'cosmo_runs',
                'class' => 'Run_cosmo'
            ),
            'id_cosmo_dataset'
        )
    );

    /**
     * Returns dataset by token
     * 
     * @param string $token
     * 
     * @return Cosmo_datasets
     */
    public static function get_by_token($token)
    {
        return self::instance()->where('token' , $token)->get_one();
    }

    /**
     * Returns the portion of completed computations
     * 
     * @return float
     */
    public function get_portion_completed()
    {
        if(!$this->id)
        {
            return null;
        }

        return $this->queryOne(
            "SELECT ds.id, ROUND(SUM(c.done)/COUNT(c.id), 2) as portion
            FROM run_cosmo_datasets ds
            JOIN run_cosmo_cosmo_datasets rccd ON rccd.id_cosmo_dataset = ds.id
            LEFT JOIN (
                SELECT *, IF(state > " . Run_cosmo::STATE_COSMO_RUNNING . " OR status != " . Run_cosmo::STATUS_OK . ", 1, 0) as done
                FROM run_cosmo
            ) c ON c.id = rccd.id_run_cosmo
            WHERE ds.id = ?
            GROUP BY id
        ", array($this->id))->portion;
    }

    /**
     * Returns number of running datasets
     * 
     * @param int $id_user
     * 
     * @return int
     */
    public function get_total_running($id_user)
    {
        if(!$id_user)
        {
            return 0;
        }

        $all = Run_cosmo_datasets::instance()->where('id_user', $id_user)->get_all();

        $total_running = 0;

        foreach($all as $ds)
        {
            $total_running += ($ds->get_portion_completed() > 0.9) ? 0 : 1;
        }

        return $total_running;
    }

    /**
     * Returns all results
     * 
     * @param $pagination
     * @param $per_page
     */
    public function get_list($pagination, $per_page, $comment_query = NULL, $id_user = null, $token = null)
    {
        $where = '';

        if($comment_query)
        {
            $comment_query = "%$comment_query%";
            $where = 'd.comment LIKE \'' . $comment_query . '\'';
        }

        if($id_user)
        {
            $where .= $where == '' ? '' : ' AND ';
            $where .= 'd.id_user = ' . $id_user;
        }

        if($token)
        {
            $where .= $where == '' ? '' : ' AND ';
            $where .= "d.token LIKE  '" . $token . "'";
        }

        $where = $where != '' ? 'WHERE ' . $where : '';

        return Run_cosmo_datasets::instance()->queryAll(
            "SELECT d.id, d.comment, u.id as id_user, u.name as author, CONCAT(COUNT(c.id), ' (', ROUND(SUM(c.done)/COUNT(c.id)*100, 2), '%)') as total_runs, 
                SUM(IF(c.state = 0 and c.status = 1, 1, 0)) as total_pending,
                SUM(IF(c.state > 0 and c.state < 5 and c.status = 1, 1, 0)) as total_running,
                SUM(IF(c.state >= 5 and c.status = 1, 1, 0)) as total_done,
                SUM(IF(c.status = 3, 1, 0)) as total_error,
                d.create_date, MAX(c.last_update) as last_update
            FROM run_cosmo_datasets d
            LEFT JOIN run_cosmo_cosmo_datasets rccd ON rccd.id_cosmo_dataset = d.id
            LEFT JOIN (
                SELECT *, IF(state > " . Run_cosmo::STATE_COSMO_RUNNING . " OR status != " . Run_cosmo::STATUS_OK . ", 1, 0) as done
                FROM run_cosmo
            ) c ON c.id = rccd.id_run_cosmo
            LEFT JOIN users u ON u.id = d.id_user
            $where
            GROUP BY id
            ORDER BY last_update DESC
            LIMIT ?,?"
        , array(($pagination-1) * $per_page, $per_page));
    }

    /**
     * Returns count of all results
     */
    public function get_list_count($comment_query = NULL, $id_user = null)
    {
        $where = '';

        if($comment_query)
        {
            $comment_query = "%$comment_query%";
            $where = 'd.comment LIKE \'' . $comment_query . '\'';
        }

        if($id_user)
        {
            $where .= $where == '' ? '' : ' AND ';
            $where .= 'd.id_user = ' . $id_user;
        }

        $where = $where != '' ? 'WHERE ' . $where : '';

        return Run_cosmo_datasets::instance()->queryOne(
            " SELECT COUNT(*) as count 
            FROM (
            SELECT d.id, d.comment, u.id as id_user, u.name as author, COUNT(c.id) as total_runs, 
                SUM(IF(c.state = 0 and c.status = 1, 1, 0)) as total_pending,
                SUM(IF(c.state > 0 and c.state < 5 and c.status = 1, 1, 0)) as total_running,
                SUM(IF(c.state >= 5 and c.status = 1, 1, 0)) as total_done,
                SUM(IF(c.status = 3, 1, 0)) as total_error,
                d.create_date
            FROM run_cosmo_datasets d
            LEFT JOIN run_cosmo_cosmo_datasets rccd ON rccd.id_cosmo_dataset = d.id
            LEFT JOIN run_cosmo c ON c.id = rccd.id_run_cosmo
            LEFT JOIN users u ON u.id = d.id_user
            $where
            GROUP BY id ) as t"
        )->count;
    }

    const PROGRESS_NEW = Messages::TYPE_COSMO_DATASET_PROGRESS_NEW;
    const PROGRESS_DONE = Messages::TYPE_COSMO_DATASET_PROGRESS_DONE;

    /**
     * Informs user about dataset progress
     * 
     * @param int $progress
     * 
     */
    public function notify_progress($progress = self::PROGRESS_DONE)
    {
        if(!$this->id)
        {
            throw new MmdbException('Invalid instance.');
        }

        if(!Regexp::is_valid_email($this->user->email))
        {
            return null;
        }

        $message = Messages::get_by_type($progress);

        if(!$message->id)
        {
            throw new MmdbException('Cannot find target message.');
        }

        $url = $this->generate_public_url();
        $url = Html::anchor($url, $url);

        $text = str_replace("{dataset_url}", $url, $message->email_text);
        $text = str_replace("{lab_dataset_url}", Url::base() . 'lab/datasets', $text);

        $ds = new Run_cosmo_datasets($this->id);

        // Get stats
        $stats = $this->get_list(1,10,null,null,$ds->token)[0];

        $text = str_replace("{total}", $stats->total_runs, $text);
        $text = str_replace("{error}", $stats->total_error, $text);
        $text = str_replace("{done}", $stats->total_done, $text);

        // Send email
        $eq = new Email_queue();

        $eq->recipient_email = $this->user->email;
        $eq->subject = $message->email_subject . ' - Dataset ' . $this->id;
        $eq->text = $text;
        $eq->status = $eq::SENDING;
        $eq->save();
    }

    /**
     * Generates dataset public URL
     * 
     * @return string
     */
    public function generate_public_url()
    {
        if(!$this->id)
        {
            return null;
        }

        if($this->token)
        {
            return Url::base() . 'lab/datasets/' . $this->token;
        }

        // Get and save token
        $this->token = md5($this->id_user . '_' . $this->id . $this->create_date);
        $this->save();

        return Url::base() . 'lab/datasets/' . $this->token;
    }
}