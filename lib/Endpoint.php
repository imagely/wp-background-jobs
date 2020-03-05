<?php

namespace ReactrIO\Background;

class Endpoint
{
    const VERSION='v1';

    protected static $_instances = [];

    /**
     * Gets an instance of the endpoint.
     * @param string $endpoint
     * @return self
     */
    public static function get_instance($endpoint_name){
        if (!isset(self::$_instances[$endpoint_name])) {
            $klass = get_called_class();
            self::$_instances[$endpoint_name] = new $klass($endpoint_name);
        }
        return self::$_instances[$endpoint_name];
    }

    public static function get_worker_secret()
    {
        $secret = get_option('reactr-bg-secret', NULL);
        if (!$secret) {
            $secret = md5(site_url().microtime());
            update_option('reactr-bg-secret', $secret);
        }
        return $secret;
    }

    /**
     * @param string $uri
     * @param array $params
     */
    protected function _add_route($uri, array $params=[])
    {
        if (!isset($params['methods']))             $params['methods'] = ['POST'];
        if (!isset($params['callback']))            $params['callback'] = [$this, str_replace('/', '_', $uri)];
        if (!isset($params['permission_callback'])) $params['permission_callback'] = (
            function(\WP_REST_Request $request){
                $params = $request->get_json_params();
                return isset($params['secret']) && $params['secret'] = self::get_worker_secret();
            }
        );

        return register_rest_route($this->_endpoint_uri . '/' . self::VERSION,  $uri, $params);
    }

    protected function __construct($endpoint_uri)
    {
        $this->_endpoint_uri = $endpoint_uri;

        add_action('rest_api_init', function(){
            $this->_add_route('/startWorker');
            $this->_add_route('/noop');
            $this->_add_route('/wakeup', ['permission_callback' => function(){
                return current_user_can('edit_posts');
            }]);
        });
    }

    /**
     * Answers requests by replying 'noop'. Used by our mechanism
     * that tries to determine a valid loopback url
     */
    public function _noop(\WP_REST_Request $request)
    {
        return 'noop';
    }

    public function _wakeup()
    {
        return array_map(
            function(Worker $worker){
                return $worker->id();
            },
            Bootstrap::get_instance()->wakeup_workers()
        );
    }
    
    public function _startWorker(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (isset($params['num'])) {
            $worker = new Worker($params['num']);
            $worker->run();
        }
    }
}