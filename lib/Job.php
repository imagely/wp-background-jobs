<?php

namespace ReactrIO\Background;

use RuntimeException;
use stdClass;
use WP_Post;

class E_UnregisteredJobType extends RuntimeException {};
class E_DequeueJob extends RuntimeException {};
class E_SaveJob extends RuntimeException {};

abstract class Job
{
    const POST_TYPE = 'reactr-bg-job';
    const STATUS_UNQUEUED='unqueued';
    const STATUS_QUEUED = 'draft';
    const STATUS_DONE = 'publish';
    const STATUS_IN_PROGRESS='pending';
    const STATUS_FAILED='private';
    const STATUS_ABANDONED='trash';

    static protected $_registered_types = [];

    /**
     * Registers a type of Job with a class implementation
     * @param string $type_name
     * @param string $klass
     * @return string
     */
    static function register_type($type_name, $klass)
    {
        self::$_registered_types[$type_name] = $klass;
        return $type_name;
    }

    /**
     * Deregisters a job type
     * @param string $type_name
     * @return string
     */
    static function deregister_type($type_name)
    {
        unset(self::$_registered_types[$type_name]);
        return $type_name;
    }

    /**
     * Registers the post type
     * @return \WP_Post_Type
     */
    static function register_post_type()
    {
        $labels = array(
            'name'                  => _x( 'Jobs', 'Post Type General Name', 'reactr-bg' ),
            'singular_name'         => _x( 'Job', 'Post Type Singular Name', 'reactr-bg' ),
            'menu_name'             => __( 'Background Jobs', 'reactr-bg' ),
            'name_admin_bar'        => __( 'Background Jobs', 'reactr-bg' ),
            'archives'              => __( 'Job Archives', 'reactr-bg' ),
            'attributes'            => __( 'Job Attributes', 'reactr-bg' ),
            'parent_item_colon'     => __( 'Parent Job:', 'reactr-bg' ),
            'all_items'             => __( 'All Jobs', 'reactr-bg' ),
            'add_new_item'          => __( 'Add New Job', 'reactr-bg' ),
            'add_new'               => __( 'Add New', 'reactr-bg' ),
            'new_item'              => __( 'New Job', 'reactr-bg' ),
            'edit_item'             => __( 'Edit Job', 'reactr-bg' ),
            'update_item'           => __( 'Update Job', 'reactr-bg' ),
            'view_item'             => __( 'View Job', 'reactr-bg' ),
            'view_items'            => __( 'View Jobs', 'reactr-bg' ),
            'search_items'          => __( 'Search Job', 'reactr-bg' ),
            'not_found'             => __( 'Not found', 'reactr-bg' ),
            'not_found_in_trash'    => __( 'Not found in Trash', 'reactr-bg' ),
            'featured_image'        => __( 'Featured Image', 'reactr-bg' ),
            'set_featured_image'    => __( 'Set featured image', 'reactr-bg' ),
            'remove_featured_image' => __( 'Remove featured image', 'reactr-bg' ),
            'use_featured_image'    => __( 'Use as featured image', 'reactr-bg' ),
            'insert_into_item'      => __( 'Insert into Job', 'reactr-bg' ),
            'uploaded_to_this_item' => __( 'Uploaded to this job', 'reactr-bg' ),
            'items_list'            => __( 'Jobs list', 'reactr-bg' ),
            'items_list_navigation' => __( 'Jobs list navigation', 'reactr-bg' ),
            'filter_items_list'     => __( 'Filter job list', 'reactr-bg' ),
        );
        $args = array(
            'label'                 => __( 'Job', 'reactr-bg' ),
            'description'           => __( 'A job that will be processed in the background', 'reactr-bg' ),
            'labels'                => $labels,
            'supports'              => array( 'title' ),
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 5,
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => true,
            'can_export'            => true,
            'has_archive'           => true,
            'exclude_from_search'   => false,
            'publicly_queryable'    => false,
            'capability_type'       => 'page',
            'show_in_rest'          => true,
        );
        return register_post_type( 'reactr-bg-job', $args );
    }    

    /**
     * Gets a job from the queue
     * 
     * By default, only jobs which are in the 'queued' or 'failed' state are retrieved
     * 
     * @param string $queue
     * @param int $limit number of jobs to return
     * @param string[]|string $statuses
     * @return Job[]
     */
    static function get_all_from_queue($queue=NULL, $limit=0, $statuses=[self::STATUS_FAILED, self::STATUS_QUEUED])
    {
        $query_params = [
            'post_type'         => self::POST_TYPE,
            'post_status'       => $statuses
        ];

        // Was a queue specified?
        if ($queue) $query_params['post_mime_type'] = "queue/{$queue}";

        // Was a limit given?
        if ($limit) $limit = intval($limit);
        if ($limit > 0) add_filter('post_limits_request', function() use ($limit) {return "LIMIT {$limit}";});

        $query = new \WP_Query($query_params);
        $retval = array_map([self::class, 'from_post'], $query->get_posts());
        if ($limit > 0) remove_all_filters('post_limits_request');

        return $retval;
    }

    /**
     * Gets the next available job for processing from the provided queue.
     * 
     * If no queue is provided, then a job from any queue will be returned.
     * 
     */
    static function get_next_from_queue($queue=NULL)
    {
        return self::get_all_from_queue($queue, 1);
    }

    /**
     * Dequeues a job from the queue
     */
    static function dequeue($job_id)
    {
        $job = NULL;
        if (($post = get_post($job_id))) {
            $job = self::from_post($post);
            if (wp_delete_post($job_id)) {
                $job->_id = NULL;
                $job->_queue = NULL;
                $job->_worker_id = NULL;
                $job->_claim_id = NULL;
            }
        }
        if (!$job) throw new E_DequeueJob("Job #{$job_id} could not be dequeued");
        return $job;
    }

    /**
     * Gets the name of all queues.
     * 
     * By default, we show all queues that have ever been used, but by specifying TRUE
     * as the first argument, only queues that have pending jobs will be returned
     * 
     * @param bool $hide_non_active
     * 
     * @return string[]
     */
    static function get_all_queue_names($hide_non_active=FALSE)
    {
        /**
         * @var \WPDB $wpdb
         */
        global $wpdb;
    
        $statuses = [self::STATUS_ABANDONED, self::STATUS_DONE];
        $status_placeholders = array_fill(0, count($statuses), '%s');

        $query = $hide_non_active
            ? $wpdb->prepare(
                "SELECT post_mime_type FROM {$wpdb->posts} WHERE post_status NOT IN ({$status_placeholders})",
                $statuses
            )
            : $wpdb->prepare("SELECT post_mime_type FROM {$wpdb->posts}");

        return array_map([self::class, 'from_post'], $wpdb->get_results($query));
    }

    /**
     * The post ID for the Job, if saved
     * @property int $_id
     */
    protected $_id = 0;


    /**
     * The claim id of the Job
     */
    protected $_claim_id = '';

    /**
     * A human-friendly label to describe the job
     * @property string $_label;
     */
    protected $_label = '';

    /**
     * A dataset for the job to work with
     * @property [] $_dataset
     */
    protected $_dataset = [];


    /**
     * A history log of what has happened with the job
     * @property string[] $_history;
     */
    protected $_history = [];


    /**
     * A log of the Job's output
     * @property string[] $_output
     */
    protected $_output = [];


    /**
     * The name of the queue
     * @property string $queue
     */
    protected $_queue = '';


    /**
     * Returns the ID of the worker assigned this Job
     */
    protected $_worker_id = '';


    /**
     * The estimated time for this job to complete
     * @property int $time_estimate
     */
    protected $_time_estimate = 20;


    /**
     * Current retry iteration for failed jobs
     * @property $_retry_i
     */
    protected $_retry_i = 0;


    /**
     * Number of retry attempts to make before abandoning
     */
    protected $_max_retries = 0;

    /**
     * The status of the job
     * @return STATUS_UNQUEUED|STATUS_QUEUED|STATUS_DONE|STATUS_FAILED|STATUS_IN_PROGRESS
     */
    protected $_status = self::STATUS_UNQUEUED;

    protected function __construct(array $props)
    {
        foreach ($props as $k=>$v) $this->$k = $v;
    }

    function get_label()
    {
        return $this->_label;
    }

    function get_dataset()
    {
        return $this->_dataset;
    }

    function get_status()
    {
        return $this->_status;
    }

    function get_output($join="\n")
    {
        return implode($join, $this->_output);
    }

    function get_history($join="\n")
    {
        return implode($join, $this->_history);
    }

    function get_queue_name()
    {
        return $this->_queue;
    }

    function get_queue()
    {
        return Queue::get_instance($this->_queue);
    }

    function get_worker_id()
    {
        return $this->_worker_id;
    }

    function get_time_estimate()
    {
        return $this->_time_estimate;
    }

    function get_claim_id()
    {
        return $this->_claim_id;
    }

    function get_type()
    {
        return $this->_type;
    }

    function get_id()
    {
        return $this->_id;
    }

    function get_number_of_retry_attempts()
    {
        return $this->_retry_i;
    }

    function can_be_retried()
    {
        return self::get_status() != self::STATUS_ABANDONED &&
            ($this->_retry_i <= $this->_max_retries) &&
             $this->_max_retries !== 0;
    }

    function is_claimed()
    {
        return isset($this->_claim_id) && isset($this->_worker_id);
    }

    /**
     * Marks a job as failed
     */
    function mark_as_failed(\Exception $ex=NULL)
    {
        if ($ex) {
            $this->logHistory("A problem occured processing the job: {$ex->getMessage()}");
            $this->_exception = $ex;
        }
        $this->_retry_i += 1;

        if ($this->can_be_retried()) {
            $this->_status = self::STATUS_FAILED;
            $this->logHistory("Job failed in attempt #{$this->_retry_i}");
        }
        else {
            $this->_status = self::STATUS_ABANDONED;
            $this->logHistory("Job abandoned after attempt #{$this->_retry_i}");
        }
        $this->save($this->get_queue_name());

        return $this;
    }

    function mark_as_done()
    {
        $this->_status = self::STATUS_DONE;
        $this->logHistory("Job is complete");
        $this->save($this->get_queue_name());
    }

    /**
     * Creates a new Job from a post
     * @param \WP_Post $post
     * @return Job
     */
    static function from_post(WP_Post $post)
    {
        $props = json_decode($post->post_content, TRUE);
        $klass = self::$_registered_types[$props['_type']];
        
        $props['_label']        = $post->post_title;
        $props['_id']           = $post->ID;
        $props['_worker_id']    = $post->post_password;
        $props['_queue']        = str_replace("queue/", "", $post->post_mime_type);
        $props['_claim_id']     = $post->ping_status == 'closed' ? '' : $post->ping_status;
        $props['_status']       = $post->post_status;

        return new $klass($props);
    }

    /**
     * Creates a Job from a post, specified by its ID
     * @param int $post_id
     * @return Job
     */
    static function from_post_id(int $post_id)
    {
        if (($post = get_post($post_id))) {
            return self::from_post($post);
        }
        return self::from_post(WP_Post::get_instance($post_id));
    }

    /**
     * Returns the name of the class used to handle jobs of the particular type
     * @param string type
     * @return string
     * @throws E_UnregisteredJobType
     */
    protected static function _get_type_class($type)
    {
        if (!isset(self::$_registered_types[$type])) {
            throw new E_UnregisteredJobType("A type has not been registered for '{$type}'");
        }
        return self::$_registered_types[$type];
    }

    /**
     * @param string $label
     * @param string $type
     * @param any $dataset
     * @return Job
     */
    static function create($label, $type, $dataset=[])
    {
        $klass = self::_get_type_class($type);
        return new $klass(['_label' => $label, '_type' => $type, '_dataset' => $dataset]);
    }

    /**
     * Returns a WP_Post representation of the Job
     * @param string $queue the name of the queue to be associated with this Job
     * @return WP_Post
     */
    function to_post(string $queue, string $claim_id=NULL, $worker_id=NULL)
    {
        // Update some props passed in
        $this->_queue = $queue;
        $this->_claim_id = $claim_id;
        $this->_worker_id = $worker_id;

        // Construct array with custom fields
        $other_data = array_reduce(
            ['_undesirable_prop'],
            function($retval, $prop) {
                unset($retval[$prop]);
                return $retval;
            },
            get_object_vars($this)
        );

        // WP props
        $data = new \stdClass;
        $data->ID = $this->_id;
        $data->ping_status = $this->_claim_id ? $this->_claim_id : ''; /* ping_status is claim_id */
        $data->post_password = $this->_worker_id ? $this->_worker_id: ''; /* post_password is worker_id */
        $data->post_type = self::POST_TYPE;
        $data->post_status = $this->_status;
        $data->post_title = $this->_label;
        $data->post_content = json_encode($other_data);
        $data->post_mime_type = "queue/{$queue}"; /** post_mime_type is the queue */

        // Apply overrides
        if ($claim_id) $data->ping_status = $claim_id;
        if ($worker_id) $data->post_password = $worker_id;

        return new WP_Post($data);
    }
    /**
     * Runs the job
     * @return Job
     */
    abstract function run();

    /**
     * Saves the job in the DB
     */
    function save(string $queue, string $claim_id=NULL, string $worker_id=NULL)
    {
        $previously_unqueued = $this->get_status() === self::STATUS_UNQUEUED;

        // If the Job was previously enqueued, then we're now enqueuing it
        if ($previously_unqueued) $this->_status = self::STATUS_QUEUED;

        $this->logHistory("Job was persisted to the DB");
        $this->_id = $this->_id
            ? wp_update_post($this->to_post($queue, $claim_id, $worker_id), TRUE)
            : wp_insert_post($this->to_post($queue, $claim_id, $worker_id), TRUE);

        if (!is_wp_error($this->_id)) {
            $this->_queue = $queue;
            $this->_claim_id = $claim_id;
            $this->_worker_id = $worker_id;
        }
        else {
            /**
             * @var \WP_Error $err
             */
            $err = $this->_id;
            $this->_id = 0;
            if ($previously_unqueued) $this->_status = self::STATUS_UNQUEUED;
            throw new E_SaveJob($err->get_error_message());        
        }

        return $this;
    }

    function delete()
    {
        if (wp_delete_post($this->get_id())) {
            $this->_id = 0;
            $this->_worker_id = '';
            $this->_claim_id = '';
            $this->_queue = '';
            return $this;
        }
        throw new E_DequeueJob("Could not dequeue {$this->get_id()}");
    }


    function logHistory($msg, $timestamp=NULL)
    {
        $date = date("%r", $timestamp);
        $this->_history[] = "{$date}\t{$msg}";
        return $this;
    }

    function logOutput($msg, $timestamp=NULL)
    {
        $date = date("%r", $timestamp);
        $this->_output[] = "{$date}\t{$msg}";
        return $this;
    }

    function unclaim()
    {
        $this->logHistory("Job was unclaimed from {$this->get_worker_id()}");
        $this->_worker_id = '';
        $this->_claim_id = '';
        $this->save($this->get_queue_name());
        return $this;
    }
}