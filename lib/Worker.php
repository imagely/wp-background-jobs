<?php

declare(ticks=5);

namespace ReactrIO\Background;

use Spatie\Url\Url;

class Worker
{
    const DEAD=-1;
    const ALIVE=1;


    public static function get_id(int $num)
    {
        return get_called_class().$num;
    }

    public static function ping(string $id)
    {
        set_transient(self::_get_ping_transient_name($id), microtime(TRUE));
        sleep(1);
        $retval = get_transient(self::_get_pong_transient_name($id));
        delete_transient(self::_get_pong_transient_name($id));
        return $retval;
    }

    public static function get_status(string $id)
    {
        $request_time   = microtime(TRUE);
        $pong_time      = self::ping($id);
        $elapsed        = $request_time - $pong_time;

        // 5 seconds
        return $elapsed > (5000000) ? self::DEAD : self::ALIVE;
    }


    public static function register_endpoint()
    {

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
    protected $_timelimit = 20000000.0;

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
     */
    function get_elapsed()
    {
        return microtime(TRUE) - $this->_started_at;
    }


    function has_exceeded_timelimit()
    {
        return $this->get_elapsed() >= $this->_timelimit;
    }

    function has_not_exceeded_timelimit()
    {
        return !$this->has_exceeded_timelimit();
    }

    function has_time_remaining($seconds=NULL)
    {
        $remaining = $this->get_elapsed() - $this->_time_limit;

        if ($seconds && $remaining >= $seconds) return TRUE;
        else if (!$seconds && $remaining) return TRUE;

        return FALSE;
    }

    function start($endpoint_uri)
    {
        $this->_loopback_request($endpoint_uri, [
            'secret'        => Endpoint::get_worker_secret(),
            'num'           => $this->_num,
            'endpoint_uri'  => $endpoint_uri
        ]);
    }

    protected function _get_loopback_url($endpoint_uri)
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
            
            // List of tests to perform
            $tests = [
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

            // Request all variations concurrently
            $requests = array_map(function($test) use ($endpoint_uri){
                $url    = Url::fromString(get_rest_url(NULL, $endpoint_uri));
                $port   = $url->getPort();
                $scheme = $url->getScheme();
                extract($test);

                $url = (string) $url
                    ->withHost($ip)
                    ->withPort($port)
                    ->withScheme($scheme)
                    ->withPath(
                        untrailingslashit($url->getPath()).'/noop'
                    );

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
            }, $tests);
            
            $hooks = new \Requests_Hooks();
            $hook = function($handle){
                curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 0);
				curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, 0);
            };
            $hooks->register('curl.before_multi_add', $hook);
            // $hooks->register('curl.before_multi_exec', $hook);
            $responses = \Requests::request_multiple($requests, [
                'timeout'   => 5,
                'hooks'     => $hooks
            ]);

            // Get the url which worked
            try {
                $loopback_url = array_reduce($responses, function($retval, $response){
                    return $response instanceof \Requests_Response && $response->body == '"noop"'
                        ? str_replace('/noop', '', $response->url)
                        : $retval;
                });
            }
            catch (\Exception $ex) {
                error_log($ex);
            }

            // Ensure that one worked
            if ($loopback_url) {
                $retval = $loopback_url;
                return $retval;
            }
        }
        
        throw new \RuntimeException("Could not determine loopback url");
    }

    protected function _loopback_request($endpoint_uri, $params)
    {
        $uri = str_replace('/startWorker', '/', $endpoint_uri);
        $url = $this->_get_loopback_url($uri).'/startWorker';

        wp_remote_post($url, [
            'method'    => 'POST',
            'timeout'   => 20,
            'blocking'  => FALSE,
            'body'      => json_encode($params),
            'headers'   => [
                'Content-Type'  => 'application/json',
                'Hostname'      => $_SERVER['SERVER_NAME']
            ]
        ]);
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
     * Gets the name of the transient used for pongs
     * @return string
     */
    protected static function _get_pong_transient_name(string $id)
    {
        return 'reactr_pong_worker_'.$id;
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