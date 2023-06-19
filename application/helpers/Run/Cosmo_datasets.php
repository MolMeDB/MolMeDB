<?php

/**
 * Holds info about COSMO upload data
 * 
 * @property int $id
 * @property string $comment
 * @property int $id_user
 * @property Users $user
 * @property int $id_file
 * @property Files $file
 * @property string $create_date
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

    /**
     * Returns all results
     * 
     * @param $pagination
     * @param $per_page
     */
    public function get_list($pagination, $per_page, $comment_query = NULL)
    {
        $where = '';

        if($comment_query)
        {
            $comment_query = "%$comment_query%";
            $where = 'd.comment LIKE \'' . $comment_query . '\'';
        }

        $where = $where != '' ? 'WHERE ' . $where : '';

        return Run_cosmo_datasets::instance()->queryAll(
            "SELECT d.id, d.comment, u.id as id_user, u.name as author, COUNT(c.id) as total_runs, 
                SUM(IF(c.state = 0 and c.status = 1, 1, 0)) as total_pending,
                SUM(IF(c.state > 0 and c.state < 5 and c.status = 1, 1, 0)) as total_running,
                SUM(IF(c.state >= 5 and c.status = 1, 1, 0)) as total_done,
                SUM(IF(c.status = 3, 1, 0)) as total_error,
                d.create_date
            FROM run_cosmo_datasets d
            LEFT JOIN run_cosmo c ON c.id_dataset = d.id
            LEFT JOIN users u ON u.id = d.id_user
            $where
            GROUP BY id
            ORDER BY id ASC
            LIMIT ?,?"
        , array(($pagination-1) * $per_page, $per_page));
    }

    /**
     * Returns count of all results
     */
    public function get_list_count($comment_query = NULL)
    {
        $where = '';

        if($comment_query)
        {
            $comment_query = "%$comment_query%";
            $where = 'd.comment LIKE \'' . $comment_query . '\'';
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
            LEFT JOIN run_cosmo c ON c.id_dataset = d.id
            LEFT JOIN users u ON u.id = d.id_user
            $where
            GROUP BY id ) as t"
        )->count;
    }
}