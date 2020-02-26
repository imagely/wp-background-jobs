<?php

namespace ReactrIO\Background;

class Bootstrap
{
    /**
     * @property Bootstrap $_instance
     */
    static protected $_instance=NULL;


    /**
     * @property int $_number_of_workers
     */
    protected $_number_of_workers = 1;


    /**
     * @returns Bootstrap
     */
    public static function get_instance()
    {
        if (!self::$_instance) {
            $klass = get_called_class();
            self::$_instance = new $klass();
        }
        return self::$_instance;
    }

    /**
     * Sets the number of desired workers. If there are fewer than the desired number created,
     * then additional workers will be spawned
     */
    function set_desired_worker_count($number_of_workers)
    {
        $this->_number_of_workers = $number_of_workers;
    }

    /**
     * 
     */
    function ensure_worker_count()
    {
        for ($i=1; $i == $this->_number_of_workers; $i++) {
            $worker = new Worker($i);
            if ($worker->is_not_running()) {
                $worker->start("/{$this->_endpoint_name}/{$this->_endpoint_version}/startWorker");
            }
        }
    }

    protected function __construct()
    {
        $this->_endpoint_name        = 'reactr-bg';
        $this->_endpoint_version     = 'v1';

        Endpoint::get_instance($this->_endpoint_name);
        
        if ((!defined('DOING_CRON') || !constant('DOING_CRON'))) {
            if (!wp_is_json_request()) register_shutdown_function([$this, 'ensure_worker_count']);
        }
    }
}