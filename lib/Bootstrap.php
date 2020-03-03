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
    public static function init()
    {
        if (!self::$_instance) {
            $klass = get_called_class();
            self::$_instance = new $klass();
        }
        return self::$_instance;
    }

    protected function _create_endpoint()
    {
        $this->_endpoint_name        = 'reactr-bg';
        $this->_endpoint_version     = 'v1';

        Endpoint::get_instance($this->_endpoint_name);

        Worker::$endpoint_uri = '/reactr-bg/v1/startWorker';
    }

    protected function _register_hooks()
    {
        add_action('reactr_bg_job_added', function(){
            Worker::wakeup($this->_number_of_workers);
        });
    }

    protected function __construct($number_of_workers=1)
    {
        $this->_number_of_workers = $number_of_workers;

        $this->_create_endpoint();
        $this->_register_hooks();
    }
}