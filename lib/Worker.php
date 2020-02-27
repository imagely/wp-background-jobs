<?php

declare(ticks=5);

namespace ReactrIO\Background;

use Spatie\Url\Url;

class Worker
{
    const DEAD=-1;
    const ALIVE=1;

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
    protected $_timelimit = 20000000.0;


/**
     * Computes the id of the worker, given by its number
     */
    public static function get_id(int $num)
    {
        return get_called_class().$num;
    }

    /**
     * Pings the worker.
     * 
     * We do this by setting a transient, and waiting for the worker
     * to delete it.
     * 
     * @param string $id
     * @param int $num_of_pings optional
     * @param int $ttl time to wait for pong
     * @returns boolean
     */
    public static function ping(string $id, int $num_of_pings=1, $ttl=1)
    {
        for ($i=0; $i<$num_of_pings; $i++) {
            set_transient(self::_get_ping_transient_name($id), microtime(TRUE));
            sleep($ttl);
            $retval = get_transient(self::_get_ping_transient_name($id) === FALSE);
            if ($retval) {
                delete_transient(self::_get_ping_transient_name($id));
                break;
            }
        }
        
        return $retval;
    }

    /**
     * Returns the running status of the worker
     * @param string id id of the worker
     * @param int $num_of_pings optional
     * @param int $ttl time to wait for pong
     * @returns DEAD|ALIVE
     */
    public static function get_status(string $id, $num_of_pings=1, $ttl=1)
    {
        return self::ping($id, $num_of_pings, $ttl)
            ? self::ALIVE
            : self::DEAD;
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
    function is_running()
    {
        return self::get_status($this->id()) === self::ALIVE;
    }

    /**
     * Gets the running status of the worker
     * @returns bool
     */
    function is_not_running()
    {
        return !$this->is_running();
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
    function run($endpoint_uri)
    {
        // Register a tick function which will answer ping requests by another thread
        register_tick_function(function(){
            if (get_transient(self::_get_ping_transient_name($this->id()))) {
                delete_transient(self::_get_ping_transient_name($this->id()));
                set_transient(self::_get_pong_transient_name($this->id()), microtime(TRUE));
            }
        });

        $this->_started_at = microtime(TRUE);

        error_log("In worker!");
        sleep(5);

        try {

        }
        catch (\Exception $ex) {

        }
        $this->start($endpoint_uri);
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
        return $this->get_elapsed() >= $this->_timelimit;
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
        $remaining = $this->get_elapsed() - $this->_time_limit;

        if ($seconds && $remaining >= $seconds) return TRUE;
        else if (!$seconds && $remaining) return TRUE;

        return FALSE;
    }

    /**
     * Starts the work
     * 
     * @param $endpoint_uri the REST URI used to start the worker
     * @returns NULL
     */
    function start($endpoint_uri)
    {
        // We know that the noop resource is available on the same endpoint
        $noop_uri = str_replace('/startworker', '/noop', $endpoint_uri);

        // JSON
        $data = [
            'secret'        => Endpoint::get_worker_secret(),
            'num'           => $this->_num,
            'endpoint_uri'  => $endpoint_uri
        ];

        return $this->_loopback_request(
            $noop_uri,
            $endpoint_uri,
            $data,
            ['timeout' => 5]
        );
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
    protected function _request_concurrently(array $requests, array $options=[])
    {
        $hooks = new \Requests_Hooks();
        $hook = function($handle){
            curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, 0);
        };
        $hooks->register('curl.before_multi_add', $hook);

        // Submit requests
        return \Requests::request_multiple(array_merge($options, $requests, [
            'hooks'     => $hooks
        ]));
    }

    /**
     * Returns the list of tests to perform for finding the lookpback url
     * @returns array
     */
    protected function _get_loopback_url_tests()
    {
        return [
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
    protected function _get_loopback_url($noop_uri)
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
                $this->_get_loopback_url_tests()
            );
            
            // Currently, the Requests::multisite
            $responses = $this->_request_concurrently($requests, ['timeout' => 5]);

            // Get the url which worked
            $loopback_url = array_reduce($responses, function($retval, $response){
                return $response instanceof \Requests_Response && $response->body == '"noop"'
                    ? str_replace('/noop', '', $response->url)
                    : $retval;
            });

            // Ensure that one worked
            if ($loopback_url) {
                $retval = $loopback_url;
                set_transient($transient_name, $retval);
                return $retval;
            }
        }
        
        throw new \RuntimeException("Could not determine loopback url");
    }

    /**
     * Given the loopback url, return a valid WP resource url
     * @param string $request_uri the URI of the WP resource to request
     * @param string $loopback_url the url returned from _get_loopback_url()
     * @see _get_loopback_url()
     * @returns string
     */
    protected function _from_loopback_url_to_wp_url($wp_request_uri, $loopback_url)
    {
        $site_url   = Url::fromString(site_url($wp_request_uri));
        
        return Url::fromString($loopback_url)->withPath($site_url->getPath());
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
    protected function _loopback_request(string $noop_uri, string $request_uri, $data=[], $options=[])
    {
        $url = $this->_from_loopback_url_to_wp_url($request_uri, $this->_get_loopback_url($noop_uri));

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
     * Gets the name of the transient used for pings
     * @return string
     */
    protected static function _get_ping_transient_name(string $id)
    {
        return 'reactr_ping_worker_'.$id;
    }

    /**
     * @param int $num the numeric id of the worker
     * @param int $timelimit the number of seconds a worker is allowed to run for before needing to respawn
     */
    function __construct(int $num, int $timelimit=20)
    {
        $this->_num = $num;
        $this->_id = self::get_id($num);
        $this->_label = "Worker #{$num}";
        $this->_timelimit = $timelimit*1000000;
    }
}