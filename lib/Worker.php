<?php

namespace ReactrIO\Background;

use RuntimeException;
use ReactrIO\Url\Url;

class E_MisconfiguredWorkerURI extends RuntimeException {}

class Worker
{
    const DEAD=1;
    const ALIVE=2;
    const UNKNOWN=3;

    /**
     * @property string
     */
    static $endpoint_uri = '';

    /**
     * Starts any workers that aren't running
     * @param int $number_of_workers
     * @return Worker[]
     */
    static function wakeup($number_of_workers=1)
    {
        return array_map(
            function($num){
                $worker = new Worker($num);
                $worker->start();
                return $worker;
            },
            array_fill(0, $number_of_workers, 1)
        );
    }
    
    /**
     * Gets the name of the transient used for pings
     * @param string $id
     * @return string
     */
    public static function get_pid_transient_name(string $id)
    {
        return str_replace("\\", '_', strtolower($id)).'_pid';
    }

    /**
     * Gets the name of the transient used to stop a worker
     * @param string $id
     * @return string
     */
    public static function get_stop_transient_name(string $id)
    {
        return str_replace("\\", '_', strtolower($id)).'stop';
    }

    /**
     * Gets the status of a worker
     * @param string $id
     * @param int $pings
     * @return ALIVE|DEAD
     */
    public static function get_status(string $id, int $pings=1, $ttl=1)
    {
        return array_reduce(
            array_fill(0, $pings, $ttl),
            function($retval, $val) use ($id){
                sleep($val);
                if ($retval == self::ALIVE) return $retval;
                $status = self::_check_pid($id);
                return $status == self::UNKNOWN
                    ? self::_check_pid_transient($id)
                    : $status;
            },
            FALSE
        );
    }

    /**
     * Checks whether the PID is alive
     * @param string id
     * @return UNKNOWN|ALIVE|DEAD
     */
    protected static function _check_pid(string $id)
    {
        if (!function_exists('shell_exec')) return self::UNKNOWN;

        if (($pid = self::_get_option(self::get_pid_transient_name($id)))) {
            error_log("Got pid!!! {$pid}");
            if (stripos(PHP_OS, 'windows') !== FALSE) {
                $cmd = "wmic process get processid | find \"{$this->pid}\"";
                $res = array_filter(explode(" ", shell_exec($cmd)));
                return count($res) > 0 && $this->pid == reset($res)
                    ? self::ALIVE
                    : self::DEAD;
            }
            return file_exists("/proc/{$pid}")
                ? self::ALIVE
                : self::DEAD;
            
        }

        return self::DEAD;
    }

    /**
     * Checks whether a pid transient exists
     * @param string $id
     * @return ALIVE|DEAD
     */
    protected static function _check_pid_transient(string $id)
    {
        return get_option(self::get_pid_transient_name($id)) ? self::ALIVE : self::DEAD;
    }

    /**
     * Determines whether the worker is DEAD
     * @param string $id
     * @return bool
     */
    public static function is_dead(string $id, $pings=1)
    {
        return self::get_status($id, $pings) === self::DEAD;
    }

    /**
     * Determines whether the worker is ALIVE
     * @param string $id
     * @return bool
     */
    public static function is_alive(string $id, $pings=1)
    {
        return self::get_status($id, $pings) === self::ALIVE;
    }

    /**
     * The ID of the worker
     * @property string
     */
    protected $_id = '';


    /**
     * The human-friendly label for the worker
     * @property string
     */
    protected $_label = '';


    /**
     * The time when the worker started to run
     * @property float
     */
    protected $_started_at = 0.0;


    /**
     * The timelimit for a worker to run
     */
    protected $_time_limit = 25000000.0;


    /**
     * Computes the id of the worker, given by its number
     */
    public static function get_id(int $num)
    {
        return get_called_class().$num;
    }
    
    /**
     * Get an option from the DB. Because this is an option
     * that didn't exist in the same request, we have to fetch
     * directly from the DB: See: https://dhanendranblog.wordpress.com/2017/10/12/wordpress-alloptions-and-notoptions/
     */
    protected static function _get_option($name)
    {
        /**
         * @var \WPDB $wpdb
         */
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    }

    /**
     * Returns the human-friendly label of the worker
     * @returns string
     */
    function get_label()
    {
        return $this->_label;
    }

    /**
     * Returns the ID of the worker
     * @returns string
     */
    function id()
    {
        return $this->_id;
    }

    /**
     * Gets the running status of the worker
     * @returns bool
     */
    function is_running($pings=1)
    {
        return self::is_alive($this->id(), $pings);
    }

    /**
     * Gets the running status of the worker
     * @returns bool
     */
    function is_not_running($pings=1)
    {
        return self::is_dead($this->id(), $pings);
    }

    /**
     * Runs the worker
     * 
     * Once started, the worker will continuously keep itself alive until
     * there are no more items in the queue to process
     * 
     * @param string $endpoint_uri the REST URI used to start the worker
     * @returns NULL
     */
    function run()
    {
        $exit = FALSE;

        $this->_started_at = microtime(TRUE);
        if (update_option(self::get_pid_transient_name($this->id()), getmypid())) {
            error_log('PID transient set');
        }
        else error_log("PID transient could not be set");

        // TODO: Implement working logging
        error_log("In worker!");

        while ($this->has_time_remaining()) {
            // Stop request?
            if (self::_get_option(self::get_stop_transient_name($this->id()))) {
                delete_option(self::get_stop_transient_name($this->id()));
                $exit = TRUE;
                break;
            }

            // Get a job to process
            $job = Job::get_next_from_queue();
            if (!$job) {
                $exit = TRUE;
                break;
            }
            
            try {
                if ($this->has_time_remaining($job->get_time_estimate())) {
                    error_log("Starting job: {$job->get_label()}");
                    $job->run();
                    $job->mark_as_done();
                    error_log("Finished job");    
                }
                else {
                    $exit = TRUE;
                    break;
                }
            }
            catch (\Exception $ex) {
                $job->mark_as_failed($ex);
                $job->unclaim();
            }
        }

        if (!$exit) $this->start();
        else {
            delete_option(self::get_pid_transient_name($this->id()));
        }
    }

    /**
     * Gets the number of microseconds elapsed since the worker started to run
     * @returns float
     */
    function get_elapsed()
    {
        return microtime(TRUE) - $this->_started_at;
    }

    /**
     * Has the execution time exceeded the desired timelimit?
     * @returns bool
     */
    function has_exceeded_timelimit()
    {
        return $this->get_elapsed() >= $this->_time_limit && $this->_started_at > 0;
    }

    /**
     * Opposite of has_exceeded_timelimit()
     * @see has_exceeded_timelimit()
     * @returns bool
     */
    function has_not_exceeded_timelimit()
    {
        return !$this->has_exceeded_timelimit();
    }

    /**
     * Determines if there is execution time remaining.
     * May optionally specify $seconds to determine if that
     * amount of seconds in available in execution time
     * 
     * @param $seconds optional
     * @returns bool
     */
    function has_time_remaining($seconds=NULL)
    {
        $remaining = $this->_time_limit - $this->get_elapsed();

        if ($seconds && $remaining >= $seconds) return TRUE;
        else if (!$seconds && $remaining) return TRUE;

        return FALSE;
    }

    function stop($pings=1)
    {
        if ($this->is_running($pings))
            update_option(self::get_stop_transient_name($this->id()), microtime(TRUE));
        
        return $this->is_running($pings);
    }

    /**
     * Starts the work
     * 
     * @param $endpoint_uri the REST URI used to start the worker
     * @returns NULL
     */
    function start()
    {
        if ($this->is_running()) return $this;

        // We know that the noop resource is available on the same endpoint
        $noop_uri = str_replace('/startWorker', '/noop', self::$endpoint_uri);

        // JSON
        $data = [
            'secret'        => Endpoint::get_worker_secret(),
            'num'           => $this->_num,
            'endpoint_uri'  => self::$endpoint_uri
        ];

        self::_loopback_request(
            $noop_uri,
            self::$endpoint_uri,
            $data,
            ['timeout' => 5]
        );

        return $this;
    }

    /**
     * Send multiple requests concurrently
     * 
     * This is a wrapper around Requests::request_multiple(),
     * but will not verify SSL certificates
     * 
     * @param array $requests
     * @param array $options
     * @returns array $responses
     */
    static protected function _request_concurrently(array $requests, array $options=[])
    {   
        $hooks = new \Requests_Hooks();
        $hook = function($handle){
            curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, 0);
        };
        $hooks->register('curl.before_multi_add', $hook);

        // Submit requests
        return \Requests::request_multiple($requests, array_merge($options, [
            'hooks'     => $hooks
        ]));
    }

    /**
     * Returns the list of tests to perform for finding the lookpback url
     * @returns array
     */
    static protected function _get_loopback_url_tests()
    {
        $url = Url::fromString(site_url());

        return [
            ['ip' => $url->getHost(), 'scheme' => $url->getScheme()],
            ['ip' => $url->getHost(), 'scheme' => 'https'],
            ['ip' => $url->getHost(), 'scheme' => 'http'],
            ['ip' => $_SERVER['REMOTE_ADDR']],
            ['ip' => $_SERVER['REMOTE_ADDR'], 'scheme' => 'http'],
            ['ip' => $_SERVER['REMOTE_ADDR'], 'scheme' => 'https'],
            ['ip' => $_SERVER['REMOTE_ADDR'], 'port' => $_SERVER['SERVER_PORT']],
            ['ip' => $_SERVER['REMOTE_ADDR'], 'port' => $_SERVER['SERVER_PORT'], 'scheme' => 'http'],
            ['ip' => $_SERVER['REMOTE_ADDR'], 'port' => $_SERVER['SERVER_PORT'], 'scheme' => 'https'],
            ['ip' => 'localhost'],
            ['ip' => 'localhost', 'scheme' => 'http'],
            ['ip' => 'localhost', 'scheme' => 'https'],
            ['ip' => 'localhost', 'port'   => $_SERVER['SERVER_PORT']],
            ['ip' => 'localhost', 'port'   => $_SERVER['SERVER_PORT'], 'scheme' => 'http'],
            ['ip' => 'localhost', 'port'   => $_SERVER['SERVER_PORT'], 'scheme' => 'https']
        ];
    }

    /**
     * Gets the url used for making loopback requests. 
     * 
     * $noop_uri is the URI to the noop resource, which when requested and resolves, identifies
     * the correct loopback url
     * 
     * Note, this method emits several HTTP requests concurrently and can block
     * execution by 5 seconds
     * 
     * Returns a loopback url for the noop resource
     * 
     * @param $endpoint_uri the URI to the root of the REST API endpoint
     * @throws RuntimeException if the loopback url cannot be determined
     * @returns string
     */
    static function get_loopback_url($noop_uri)
    {
        static $retval = NULL;

        if (!$retval) {
            $transient_name = 'reactr-bg-loopback-url-' . md5(site_url());
            $loopback_url = get_transient($transient_name);

            // If we've already done the work, then return early
            if ($loopback_url) {
                $retval = $loopback_url;
                return $loopback_url;
            }

            // Request all variations concurrently
            $requests = array_map(
                function($test) use ($noop_uri){
                    $url    = Url::fromString(get_rest_url(NULL, $noop_uri));
                    $port   = $url->getPort();
                    $scheme = $url->getScheme();
                    extract($test);

                    $url = (string) $url
                        ->withHost($ip)
                        ->withPort($port)
                        ->withScheme($scheme);

                    return [
                        'url'       => $url,
                        'headers'   => [
                            'Content-Type'  => 'application/json',
                            'Host'          => $_SERVER['SERVER_NAME']
                        ],
                        'data'              => json_encode(['secret' => Endpoint::get_worker_secret()]),
                        'type'              => \Requests::POST,
                        'timeout'           => 5,
                    ];
                },
                self::_get_loopback_url_tests()
            );
            
            // Currently, the Requests::multisite
            $responses = self::_request_concurrently($requests, ['timeout' => 5]);

            // Get the url which worked
            $loopback_url = array_reduce($responses, function($retval, $response){
                return $response instanceof \Requests_Response && $response->body == '"noop"'
                    ? str_replace('/noop', '', $response->url)
                    : $retval;
            });

            // Ensure that one worked
            if ($loopback_url) {
                $retval = $loopback_url;
                set_transient($transient_name, $retval, 60*60*24);
                return $retval;
            }
            throw new \RuntimeException("Could not determine loopback url");
        }
        return $retval;
    }

    /**
     * Given the loopback url, return a valid WP resource url
     * @param string $request_uri the URI of the WP resource to request
     * @param string $loopback_url the url returned from _get_loopback_url()
     * @see _get_loopback_url()
     * @returns string
     */
    static protected function _from_loopback_url_to_wp_url($wp_request_uri, $loopback_url)
    {
        $site_url   = Url::fromString(get_rest_url(NULL, $wp_request_uri));
        
        return (string) Url::fromString($loopback_url)->withPath($site_url->getPath());
    }

    /**
     * Sends a loopback request
     * 
     * A loopback request is a request from the server to the server. Basically,
     * the server is fulfilling the role of the both client/server in this request.
     * 
     * You need to first specify a REST URI which provides a 'noop' response. This is
     * used to properly determine the loopback url if that hasn't already been determined
     * 
     * The remaining parameters relate to the request you're trying to make
     * 
     * @param string $noop_uri this is the root REST API endpoint, provided in Endpoint::get_instance().
     * @param string $request_uri the URI of the WP resource you're trying to request
     * @param any $data data which is json-encodable
     * @param array $options
     * @returns WP_HTTP_Response|WP_Error
     */
    static protected function _loopback_request(string $noop_uri, string $request_uri, $data=[], $options=[])
    {
        $url = self::_from_loopback_url_to_wp_url($request_uri, self::get_loopback_url($noop_uri));

        return wp_remote_request(
            $url,
            array_merge([
                'method'    => 'POST',
                'timeout'   => 20,
                'blocking'  => FALSE,
                'body'      => json_encode($data),
                'headers'   => [
                    'Content-Type'  => 'application/json',
                    'Hostname'      => $_SERVER['SERVER_NAME']
                ]
            ], $options)
        );
    }

    /**
     * @param int $num the numeric id of the worker
     * @param int $timelimit the number of seconds a worker is allowed to run for before needing to respawn
     */
    function __construct(int $num, int $time_limit=25)
    {
        if (!self::$endpoint_uri) throw new E_MisconfiguredWorkerURI("No endpoint uri configured for the workers");
        $this->_num = $num;
        $this->_id = self::get_id($num);
        $this->_label = "Worker #{$num}";
        $this->_time_limit = $time_limit*1000000;
    }
}

Job::register_type('sleep', SleepJob::class);