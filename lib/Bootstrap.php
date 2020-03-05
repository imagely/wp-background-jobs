<?php

namespace ReactrIO\Background;

use RuntimeException;

class Bootstrap
{
    const VERSION='v1';

    /**
     * @property Bootstrap $_instance
     */
    static protected $_instance=NULL;


    /**
     * The number of workers used to process jobs
     * @property int $_number_of_workers
     */
    protected $_number_of_workers = 1;

    /**
     * The name of the endpoint used by the framework
     * @property string
     */
    protected $_endpoint_name = 'reactr-bg';

    /**
     * The version # of the endpoint used by the framework
     */
    protected $_endpoint_version = self::VERSION;


    protected $_jobs_enqueued = FALSE;

    /**
     * Initiates the framework. Must be called once
     * @return self
     */
    public static function init($number_of_workers=1)
    {
        if (!self::$_instance) {
            $klass = get_called_class();
            self::$_instance = new $klass($number_of_workers);
        }
        return self::$_instance;
    }

    /**
     * Gets an instance of the initialized bootstrap class
     * @return self
     */
    public static function get_instance()
    {
        $klass = get_called_class();
        if (!self::$_instance) throw new \RuntimeException("You must run {$klass}::init() first");
        return self::$_instance;
    }

    /**
     * Gets the URI to a method on the endpoint provided by the framework
     * @param string $method
     * @return string
     */
    function get_endpoint_uri($method)
    {
        return "/{$this->_endpoint_name}/{$this->_endpoint_version}/{$method}";
    }

    protected function _create_endpoint()
    {
        Endpoint::get_instance($this->_endpoint_name);
        Worker::$endpoint_uri = $this->get_endpoint_uri('startWorker');
    }

    function wakeup_workers()
    {
        return Worker::wakeup($this->_number_of_workers);
    }

    /**
     * Setup a cron job to detect if our workers died unexpectedly, and if so,
     * to restart them
     */
    protected function _setup_cron()
    {
        add_filter('cron_schedules', function($schedules){
            $schedules['10Min'] = [
                'interval' => 60 * 10, // 60 seconds * 10 minutes
                'display'  => __('Every 10 Minutes', 'wp-background-jobs')
            ];

            return $schedules;
        });

        register_activation_hook(__FILE__, function(){
            if (!wp_next_scheduled([$this, 'wakeup_workers'])) {
                wp_schedule_event(strtotime("+ 10 minutes"), '10Min', [$this, 'wakeup_workers']);
            }
        });

        register_deactivation_hook(__FILE__, function(){
            wp_clear_scheduled_hook([$this, 'wakeup_workers']);
        });
    }

    protected function _register_hooks()
    {
        // When a job is enqueued, we take note of that. We'll wakeup the servers at shutdown
        add_action('reactr_bg_job_added', function(){
            $this->_jobs_enqueued = TRUE;
        });

        // Wake up any workers needed to process jobs enqueued in this process
        add_action('shutdown', function(){
            if ((!defined('IN_REACTR_WORKER') || !constant('IN_REACTR_WORKER')) && $this->_jobs_enqueued) {
                $this->wakeup_workers();
            }
        });

        // When viewing the Background Jobs page, wake-up the workers
        add_action('admin_footer', function(){
            $screen = get_current_screen();
            if ($screen->id == 'edit-reactr-bg-job') {
                wp_localize_script('wp-auth-check', 'WP_Background_Jobs', [
                    'wakeup_url'    => get_rest_url(NULL, $this->get_endpoint_uri('wakeup')),
                    'nonce' => wp_create_nonce( 'wp_rest' )
                ]);

                wp_add_inline_script('wp-auth-check', "fetch(WP_Background_Jobs.wakeup_url, {method: 'POST', headers: {'X-WP-Nonce': WP_Background_Jobs.nonce}});");
            }
        });
    }

    protected function __construct($number_of_workers=1)
    {
        $this->_number_of_workers = $number_of_workers;

        $this->_create_endpoint();
        $this->_register_hooks();
        $this->_setup_cron();
    }
}