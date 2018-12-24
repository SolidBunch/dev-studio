<?php
namespace DevStudio;

use DevStudio\Helpers\Utils;
use DevStudio\Core\Data;
use DevStudio\Core\Template;
use DevStudio\Core\Checkpoint;
use DevStudio\Core\Settings;
use DevStudio\Core\Bar;
use DevStudio\Core\Info;
use DevStudio\Core\Utilities;

use DevStudio\Data\Wordpress;
use DevStudio\Data\WooCommerce;

/**
 * Dev Studio main class
 *
 * @category   Wordpress
 * @package    dev-studio
 * @author     Viktor Sedzialo <viktor.sedzialo@gmail.com>
 * @version    Release: 1.0.0
 * @since      Class available since Release 1.0.0
 */
class DevStudio {

    public $dirs = [];
    public $modules = [];
    public $utilities = [];
    public $map;
    public $mode;
    public $modes = ['public','ajax_public','admin','ajax_admin'];
    public $options;
    public $bar;
    public $access = false;
    public $bar_access = false;

    public $default_options = [
        'general' => [
            'enabled' => 'yes',
            'access' => [
                'only_admin' => 'no'
            ],
            'appearance' => [
                'unit_init' => 'Wordpress.Overview.Overview',
                'modules_order' => [ 'PHP', 'Wordpress', 'WooCommerce', 'MySQL' ]
            ],
            
        ],
        'modules' => [
            'mysql' => [
                'slow_query' => 0.05
            ]
        ],
        'checkpoints' => [
            'actions' => [
                'public' => [
                    'shutdown' => [],
                ],
                'ajax_public' => [
                    'shutdown' => [],
                ],
                'admin' => [
                    'shutdown' => [],
                ],
                'ajax_admin' => [
                    'shutdown' => [],
                ]
            ],
            'user_actions' => []
        ],
        'units'  => [
            'map' => [

                // PHP
                'PHP.Variables.Server',
                'PHP.Variables.Get',
                'PHP.Variables.Post',
                'PHP.Variables.Cookie',
                'PHP.Variables.Session',
                'PHP.Variables.Files',
                'PHP.Variables.Env',
                'PHP.Variables.Globals',

                'PHP.Constants.Constants',
                'PHP.Files.Included_Files',
                'PHP.Files.Components',
                'PHP.PHPInfo.PHPInfo',

                // Wordpress
                'Wordpress.Overview.Overview',
                'Wordpress.Overview.Conditionals',
                'Wordpress.Overview.Constants',
    
                'Wordpress.Variables.Browser',
                'Wordpress.Variables.Server',
    
                'Wordpress.Actions.Actions',
                'Wordpress.Filters.Filters',

                'Wordpress.Theme.Menu_Locations',
                'Wordpress.Theme.Menus',
                'Wordpress.Theme.Sidebars',
                'Wordpress.Theme.Widgets',

                'Wordpress.Shortcodes.Shortcodes',

                'Wordpress.Styles.Enqueued',
                'Wordpress.Styles.Registered',
                'Wordpress.Styles.WP_Styles',

                'Wordpress.Scripts.Enqueued',
                'Wordpress.Scripts.Registered',
                'Wordpress.Scripts.WP_Scripts',
    
                'Wordpress.Rewrite.Rules',
                'Wordpress.Rewrite.WP_Rewrite',
    
                'Wordpress.Locale.WP_Locale',
    
                'Wordpress.Roles.Roles',
                'Wordpress.Roles.WP_Roles',

                // WooCommerce
                'WooCommerce.Overview.Conditionals',
                'WooCommerce.Overview.Constants',
                'WooCommerce.Overview.Options',

                // MySQL
                'MySQL.Overview.Tables',
                'MySQL.Overview.Variables',

                'MySQL.Queries.Queries',
                'MySQL.Queries.Callers',
            ],
        ],
        'data' => [
            'general' => [
                'exclude_ds_data' => 'yes'
            ],
            'ajax' => [
                'exclude_wp_ajax' => 'yes'
            ]
        ],
        'bar'  => [
            'enabled' => 'yes',
            'items' => [
                'page_time' => 'yes',
                'db_queries_time' => 'yes',
                'db_queries_count' => 'yes',
                'conditionals' => 'yes',
                'wc_conditionals' => 'yes'
            ],
            'expand' => 'yes',
            'only_logged_in' => 'no',
            'show' => '',
            'html' => ''
        ],
        'data_info' => 'yes'
    ];

    private static $instance;

    /**
     * Constructor
     */
    private function __construct() {

        // Init options
        $this->options();
        
        // Register activation hook
        if ( function_exists( 'register_activation_hook' ) ) {
            register_activation_hook( __FILE__, [ $this, 'plugin_activated' ] );
        }

        // Register deactivation hook
        if ( function_exists( 'register_deactivation_hook' ) ) {
            register_deactivation_hook( __FILE__, [ $this, 'plugin_deactivated' ] );
        }

        // Add AJAX handler
        if ( function_exists( 'add_action' ) ) {
            // AJAX
            add_action('wp_ajax_dev_studio', [$this, 'ajax']);
            add_action('wp_ajax_nopriv_dev_studio', [$this, 'ajax']);
            add_action('wp_ajax_dev_studio_test', [$this, 'ajax']);
            add_action('wp_ajax_nopriv_dev_studio_test', [$this, 'ajax']);
        }
    
        add_action('plugins_loaded', [ $this, 'plugins_loaded' ]);
        
        add_action('registered_taxonomy', [ $this, 'access' ]);
    }

    public function init() {

        // Define current mode
        if (!empty($_REQUEST['mode']) && in_array($_REQUEST['mode'], $this->modes)) {
            $this->mode = $_REQUEST['mode'];
        } else {
            $this->mode = (!Utils::is_ajax() ? '' : 'ajax_') . (!Utils::is_admin() ? 'public' : 'admin');
        }

        // Define first available action
        if (empty($this->options()['modes'][$this->mode]['start'])) {
            foreach($GLOBALS['wp_actions'] as $action=>$_) {
                $start = $action;
                $this->options()['modes'][$this->mode]['start'] = $start;
            }
        }

        // Include AVA-Fields library
        require_once( $this->dir('vendor') . 'ava-fields/ava-fields.php' );

        // Load all modules
        $this->modules = $this->load_modules($this->options()['units']['map']);

        $this->map        = Utils::map();
        $this->data       = new Data();
        $this->template   = new Template();
        $this->checkpoint = new Checkpoint($this->mode, $this->options());
        $this->settings   = new Settings();
        $this->bar        = new Bar();
        $this->utilities  = new Utilities();

        //
        if (!(isset($_REQUEST['action']) && $_REQUEST['action'] === 'dev_studio_test')) {

            if (function_exists('add_action')) {

                add_action('wp_enqueue_scripts', '\DevStudio\load_assets');
                add_action('admin_enqueue_scripts', '\DevStudio\load_assets');

                // Add item to admin bar menu
                add_action('admin_bar_menu', [$this, 'admin_bar_menu'], 100);

                // DevStudio disabled
                if (!defined('DOING_AJAX') && DevStudio()->enabled()) {
                    add_action('shutdown', [$this, 'page_data'], PHP_INT_MAX - 2);
                    add_action('shutdown', [$this, 'php_data'], PHP_INT_MAX - 2);
                    add_action('shutdown', [$this, 'shutdown'], PHP_INT_MAX - 1);
                    add_action('shutdown', [$this, 'app_data'], PHP_INT_MAX);
                }
            }

            // Clear data directory
            $this->remove_data();

            // Add checkpoint 'shutdown' if not exists any
            $this->shutdown();

            // Init hook
            do_action('dev-studio/init');
        }
    }

    /**
     * Get AVA Fields object
     *
     * @since 1.0.0
     * @return \DS_AVAFields\AVA_Fields
     */
    public function avaFields() {
        if (function_exists('DS_AVA_Fields')) {
            return DS_AVA_Fields();
        }
    }

    /**
     * Doing test AJAX request
     *
     * @since 1.0.0
     */
    public function ajax_test() {
        if ($this->mode==='ajax_public' || $this->mode==='ajax_admin') {
            wp_send_json([
                'DevStudio()' => $this
            ]);
            wp_die();
        }
    }

    /**
     * Get the instance
     *
     * @since 1.0.0
     * @return self
     */
    public static function instance() {
        if ( ! ( self::$instance instanceof self ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }
    
    public function plugins_loaded() {
        do_action('dev-studio/utilities');
    }

    /**
     * Utility registration
     *
     * @since 1.0.0
     * @param $params
     */
    public function registerUtility($params) {
        $this->utilities()->register($params);
    }

    /**
     * Return utilities array
     *
     * @since 1.0.0
     * @return array
     */
    public function utilities() {
        return $this->utilities;
    }

    /**
     * AJAX requests
     *
     * @since 1.0.0
     */
    public function ajax() {
        $response = [
            'result' => 'ok',
            '_REQUEST' => $_REQUEST
        ];
        switch ( $_REQUEST['request'] ) {
            case 'test':
                break;
            case 'UI':
                $response['html'] = $this->UI();
                break;
            case 'enabled':
                $options = $this->options();
                $options['general']['enabled'] = $_REQUEST['enabled'];
                $this->save_options($options);
                break;
            case 'bar':
                $response['bar'] = $this->bar;
                $response['html'] = $this->bar->bar_data();
                break;
            case 'settings':
                $response['html'] = $this->app_load('settings');
                break;
            case 'data':
                $response['html'] = $this->checkpoint->load_data();
                break;
            case 'info':
                $response['html'] = Info::html($_REQUEST['type']);
                break;
            case 'actions':
                $response['html'] = $this->template->load( 'actions', [
                    'mode' => $_REQUEST['mode']
                ]);
                break;
            case 'checkpoints':
                if (!empty($_REQUEST['cp'])) {
                    $options = $this->options();
                    $opts = [];
                    foreach(explode('::', $_REQUEST['cp']) as $action) {
                        $opts[$action] = [];
                    }
                    $options['checkpoints']['actions'][$_REQUEST['mode']] = $opts;
                    $this->save_options($options);
                }
                $response['opts'] = $opts;
                $response['options'] = $options;
                break;
            case 'features':
                switch ( $_REQUEST['feature'] ) {
                    case 'data_info':
                        $options = $this->options();
                        $options['data_info'] = $_REQUEST['value'];
                        $this->save_options($options);
                        $response['options'] = $options;
                    break;
                }
                break;
            case 'utility':
                $response = array_merge_recursive($response, $this->utilities->load($_REQUEST['utility'], isset($_REQUEST['page']) ? $_REQUEST['page']:null));
                break;
            case 'utility_enable':
                $options = DevStudio()->options();
                if (!isset($options['utilities'][$_REQUEST['utility']]) || !is_array($options['utilities'][$_REQUEST['utility']])) {
                    $options['utilities'] = [];
                }
                $options['utilities'][$_REQUEST['utility']]['enabled'] = 'yes';
    
                if (isset($_REQUEST['args'])) {
                    $options['utilities'][$_REQUEST['utility']]['args'] = array_merge(
                        !empty($options['utilities'][$_REQUEST['utility']]['args']) ? $options['utilities'][$_REQUEST['utility']]['args']:[],
                        $_REQUEST['args']
                    );
                }
                //$options['utilities'][$_REQUEST['utility']]['args'] = [];
                $response['options'] = $options;
                
                DevStudio()->save_options($options);
                break;
        }

        wp_send_json( $response );
        wp_die();
    }

    /**
     * Load components and units
     *
     * @since 1.0.0
     * @param $units
     * @return array
     */
    public function load_modules($units) {
    
        $modules = [];

        foreach($units as $_unit) {
            $e = explode('.', $_unit);
            $module = $e[0];
            $component = $e[1];
            $unit = $e[2];

            // Load Module classes
            if (!isset($modules[$module])) {
                $file = DevStudio()->dir('modules') . $module . '/' . $module . '.php';
                if (file_exists($file)) {
                    require_once $file;
                    $class_name = str_replace('/', '\\', '\DevStudio\Modules\\' . $module );
                    if (class_exists($class_name)) {
                        $modules[$module] = new $class_name();
                    }
                }
            }

            // Load Component classes
            if (isset($modules[$module])) {
                if (!isset($modules[$module]->components[$component])) {
                    $file = DevStudio()->dir('modules') . $module . '/Components/' . $component . '/' . $component . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                        $class_name = str_replace('/', '\\', '\DevStudio\Modules\\' . $module . '/Components/' . $component . '/' . $component);
                        if (class_exists($class_name)) {
                            $modules[$module]->add_component( new $class_name() );
                        }
                    }
                }
            }

            // Load Units classes
            if (isset($modules[$module]->components[$component])) {
                if (!isset($modules[$module]->components[$component]->units[$unit])) {
                    $class_name = str_replace('/', '\\', '\DevStudio\Modules\\' . $module . '/Components/' . $component . '/Unit_' . $unit);
                    if (class_exists($class_name)) {
                        $modules[$module]->components[$component]->add_unit( new $class_name($modules[$module]->components[$component]) );
                    }
                }
            }
        }

        return $modules;
    }

    /**
     * Get registered modules
     *
     * @since 1.0.0
     * @return array
     */
    public function modules() {
        return $this->modules;
    }

    /**
     * Get registered checkpoints
     *
     * @since 1.0.0
     * @return mixed
     */
    public function checkpoints() {
        return $this->checkpoint->checkpoints;
    }

    public function data($key) {
        return $this->data->get($key);
    }

    /**
     * Get plugin options
     *
     * @since 1.0.0
     * @return array
     */
    public function options() {

        // Temp
/*
        $this->options = $this->default_options;
        update_option('dev_studio_options', $this->options);
        return $this->options;
*/


        if (!empty($this->options)) return $this->options;

        $this->options = $this->default_options;

        $opts = get_option('dev_studio_options');

        if (!empty($opts)) {
            $this->options = $opts;
            update_option('dev_studio_options', $this->options);
        } else {
            $this->options = $this->default_options;
            add_option('dev_studio_options', $this->options);
        }

        return $this->options;
    }

    /**
     * Save options
     *
     * @since 1.0.0
     * @param $options
     */
    public function save_options($options) {
        $this->options = $options;
        update_option('dev_studio_options', $options);
    }

    /**
     * Get units map
     *
     * @since 1.0.0
     * @return mixed
     */
    public function map() {
        return $this->options()['units']['map'];
    }

    /**
     * Add item to admin bar menu
     *
     * @since 1.0.0
     */
    public function admin_bar_menu() {
        global $wp_admin_bar;

        if (!$this->access) return;

        /* Add to admin bar */
        $wp_admin_bar->add_menu( [
            'id'    => 'dev-studio',
            'title' => __( '<img src="'.$this->url('assets').'images/logo-sm.png">Dev Studio', 'dev-studio' ),
            'href'  => admin_url( 'admin.php?page=dev_studio_options' ),
            'meta'   => [
                //'html'     => '<img src="'.$this->url('assets').'images/logo-sm.png">',
                'class'    => 'ds-admin-bar',
            ]
        ] );
    }

    /**
     * Get UI template
     *
     * @since 1.0.0
     */
    public function UI() {
        return $this->template( 'dev-studio' );
    }

    /**
     * Load template
     *
     * @since 1.0.0
     * @param $tmpl_name
     * @param array $data
     * @return mixed
     */
    public function template( $tmpl_name, $data = [] ) {
        return $this->template->load( $tmpl_name, $data );
    }

    /**
     * Plugin activation
     *
     * @since 1.0.0
     */
    public function plugin_activated() {
    }

    /**
     * Plugin deactivation
     *
     * @since 1.0.0
     */
    public function plugin_deactivated() {
    }

    /**
     * Get directory
     *
     * @since 1.0.0
     * @param null $type
     * @return string
     */
    public function dir( $type = null ) {
        switch ( $type ) {
            case 'modules':
                return $this->dir() . 'app/Modules/';
                break;
            case 'templates':
                return $this->dir() . 'templates/';
                break;
            case 'vendor':
                return $this->dir() . 'vendor/';
                break;
            case 'core':
                return $this->dir() . 'app/Core/';
                break;
            case 'utils':
                return $this->dir() . 'app/Utils/';
                break;
            case 'utilities':
                return $this->dir() . 'app/Utilities/';
                break;
            case 'storage':
                if ( function_exists( 'wp_get_upload_dir' ) ) {
                    $upload_dir = wp_get_upload_dir();

                    return $upload_dir['basedir'] . '/dev-studio/';
                } else {
                    return ABSPATH . 'wp-content/uploads/dev-studio/';
                }
                break;
            default:
                return DEV_STUDIO_PLUGIN_DIR;
        }
    }

    /**
     * Get URI
     *
     * @since 1.0.0
     * @param null $type
     * @return string
     */
    public function url( $type = null ) {
        switch ( $type ) {
            case 'assets':
                return $this->url() . 'assets/';
                break;
            case 'utilities':
                return $this->url() . 'app/Utilities/';
                break;
            default:
                return DEV_STUDIO_PLUGIN_URL;
                break;
        }
    }

    /**
     * Add "shutdown" checkpoint if not exists any
     *
     * @since 1.0.0
     */
    public function shutdown() {

        // Add shutdown breakpoint if not exists any
        if ( DevStudio()->enabled() && empty( $this->checkpoints() ) ) {
            $this->checkpoint->add( 'shutdown' );
        }
    }

    /**
     * Save app information
     *
     * @since 1.0.0
     */
    public function app_data() {

        $this->remove_data('app/'.$this->mode);

        $storage_dir = $this->dir('storage');
        Utils::mkdir($storage_dir . 'data/app');
        Utils::mkdir($storage_dir . 'data/app/' . $this->mode);

        // Get actions array
        $actions = $GLOBALS['wp_actions'];
        $start = false;
        foreach($GLOBALS['wp_actions'] as $action=>$_) {
            $actions[$action] = $start;
            if ($action === 'dev-studio/init') $start = true;
        }

        $this->app_save($this->mode.'/actions', $actions);
    }

    /**
     * Save Page data
     *
     * @since 1.0.0
     */
    public function page_data() {
        $data = [];

        // Page generation time
        $data['page_time'] = timer_stop(0, 2);

        // Database Queries
        $q = \DevStudio\Data\MySQL::queries();

        if (!empty($q)) {
            if (isset($q['time'])) {
                $data['db_queries_time'] = $q['time'];
            }
            if (isset($q['queries'])) {
                $data['db_queries_count'] = count($q['queries']);
            }
        }

        // Wordpress Conditionals
        $conditionals = Wordpress::conditionals();
        $true = [];
        foreach ($conditionals as $func => $condition) {
            if ($condition==='true' && !in_array($func, Wordpress::$disabled)) $true[] = $func;
        }
        $data['conditionals'] = $true;

        // WooCommerce Conditionals
        $conditionals = WooCommerce::conditionals();
        $true = [];
        foreach ($conditionals as $func => $condition) {
            if ($condition==='true' && !in_array($func, WooCommerce::$disabled)) $true[] = $func;
        }
        $data['wc_conditionals'] = $true;

        //$data['REQUEST'] = $_REQUEST;
        //$data['DOING_AJAX'] = defined('DOING_AJAX') ? DOING_AJAX : null;

        $this->app_save('page', $data);
    }
    
    /**
     * Save PHP data
     *
     * @since 1.0.0
     */
    public function php_data() {
        $data = [];
        
        // Database Queries
        $q = \DevStudio\Data\PHP::data();
        
        $data['memory'] = $q['memory'];
        
        $this->app_save('php', $data);
    }

    /**
     * Save application data to file
     *
     * @since 1.0.0
     * @param $fname
     * @param $data
     * @param bool $json
     */
    public function app_save($fname, $data, $json=true) {
        $this->mkdirs();
        $fname = $this->dir('storage').'data/app/'.$fname.'.dat';
        file_put_contents($fname, $json ? json_encode($data):$data);
    }

    /**
     * Load application data from file
     *
     * @since 1.0.0
     * @param $fname
     * @param bool $json
     * @return array|bool|mixed|object|string
     */
    public function app_load($fname, $json=true) {
        $fname = $this->dir('storage').'data/app/'.$fname.'.dat';
        if (file_exists($fname)) {
            $data = file_get_contents($fname);
            return $json ? json_decode($data, true):$data;
        }
    }

    /**
     * Create directories
     *
     * @since 1.0.0
     */
    public function mkdirs() {
        Utils::mkdir($this->dir('storage'));
        Utils::mkdir($this->dir('storage') . 'data');
        Utils::mkdir($this->dir('storage') . 'data/app');
    }

    /**
     * Remove previous data
     *
     * @since 1.0.0
     * @param string $mode
     */
    public function remove_data($mode='') {

        // Exclude app system request
        if ( $this->me() ) return;

        // Exclude WP system AJAX query
        if (Utils::exclude_wp_ajax()) return;

        if (empty($mode)) $mode = $this->mode;

        $storage_dir = $this->dir('storage');
        Utils::mkdir($storage_dir);
        Utils::rmdir( $storage_dir . 'data/' . $mode );
        
        if ($mode==='admin' || $mode==='public') {
            Utils::rmdir( $storage_dir . 'data/ajax_' . $mode );
        }

        Utils::mkdir($storage_dir . 'data');
        Utils::mkdir($storage_dir . 'data/' . $mode);
    }

    /**
     * Define me
     *
     * @since 1.0.0
     * @param $type
     * @param $value
     * @return bool
     */
    public function me( $type = 'request', $value = '' ) {
        switch($type) {
            case 'component':
                return preg_match('#dev\-studio#', $value);
                break;
            case 'constant':
                return preg_match('#DEV_STUDIO#', $value);
                break;
            case 'action':
                return preg_match('#^dev\-studio#', $value);
                break;
            case 'filter':
                return preg_match('#_dev[_\-]studio#', $value);
                break;
            case 'asset':
                return preg_match('#plugins/dev\-studio[/\-]#', $value);
                break;
            case 'func':
                return preg_match('#^DevStudio\\\#', $value);
                break;
            default:
                return isset($_POST['action']) && ($_POST['action'] === 'dev_studio' || $_POST['action'] === 'avaf-save');
        }
    }

    /**
     * Exclude me
     *
     * @since 1.0.0
     * @return bool
     */
    public function exclude_me() {
        return isset($this->options()['data']['general']['exclude_ds_data']) && $this->options()['data']['general']['exclude_ds_data'] === 'yes';
    }

    /**
     * Check if DevStudio enabled
     *
     * @since 1.0.0
     * @return boolean
     */
    public function enabled() {
        return isset($this->options()['general']['enabled']) && $this->options()['general']['enabled']==='yes';
    }
    
    /**
     * Check if Bar enabled
     *
     * @since 1.0.0
     * @return boolean
     */
    public function bar_enabled() {
        return isset($this->options()['bar']['enabled']) && $this->options()['bar']['enabled']==='yes';
    }

    /**
     * Check on access
     *
     * @since 1.0.0
     * @param null $type
     * @return bool|void
     */
    public function access($type=null) {
        
        // Only for administrators
        if (isset($this->options()['general']['access']['only_admin']) && $this->options()['general']['access']['only_admin'] === 'yes') {
            if ( !is_user_logged_in() ) return;
            $user = wp_get_current_user();
            if ( !in_array('administrator', (array)$user->roles) ) {
                $this->access = false;
            }
        }
        
        if ($type === 'bar') {
            if (isset($this->options()['bar']['only_logged_in']) && $this->options()['bar']['only_logged_in'] === 'yes') {
                if ( !is_user_logged_in() ) return;
            }
            $this->bar_access = true;
            return true;
        } else {
            $this->access = true;
            return true;
        }
    }

    /**
     * Cloning disabled
     */
    private function __clone() {
    }

    /**
     * Serialization disabled
     */
    public function __sleep() {
        return [];
    }

    /**
     * De-serialization disabled
     */
    public function __wakeup() {
    }

}

/**
 * Load assets
 *
 * @since 1.0.0
 */
function load_assets() {
    wp_enqueue_style( 'fontawesome', DevStudio()->url() . 'assets/css/fontawesome.css' );
    wp_enqueue_style( 'dev-studio', DevStudio()->url() . 'assets/css/styles.css' );
    wp_enqueue_script( 'dev-studio', DevStudio()->url() . 'assets/js/scripts.js', [ 'jquery' ] );
    $data = [
        'ajax_url' => '/wp-admin/admin-ajax.php',
        'mode'     => DevStudio()->mode
    ];
    
    $data['map'] = DevStudio()->map;
    $data['bar_html'] = DevStudio()->template('bar');
    $data['options'] = DevStudio()->options();

    wp_localize_script( 'dev-studio', 'DSData', $data );
}