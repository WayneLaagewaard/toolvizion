<?php
/**
 * Plugin Name: Toolvizion Language Manager
 * Plugin URI: https://www.toolvizion.com
 * Description: Advanced language management and translation staging system
 * Version: 1.0.0
 * Author: Toolvizion
 * Text Domain: tvlm
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('TVLM_VERSION', '1.0.0');
define('TVLM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TVLM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoload classes
spl_autoload_register(function ($class) {
    // List of classes and their file paths
    $classes = [
        'TVLM_Loader' => TVLM_PLUGIN_DIR . 'includes/class-tvlm-loader.php',
        'TVLM_Staging' => TVLM_PLUGIN_DIR . 'includes/class-tvlm-staging.php',
        'TVLM_Activator' => TVLM_PLUGIN_DIR . 'includes/class-tvlm-activator.php'
    ];

    // Check if the class exists in our list
    if (isset($classes[$class])) {
        require_once $classes[$class];
    }
});

// Main plugin class
class ToolVizionLanguageManager {
    private static $instance = null;
    private $loader;
    private $staging;
    private $version;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->version = TVLM_VERSION;
        $this->init();
    }

    private function init() {
        // Initialize loader
        $this->loader = new TVLM_Loader();
        
        // Initialize staging
        $this->staging = new TVLM_Staging($this->loader);
        
        // Register hooks
        $this->registerHooks();
    
        // Run the loader
        $this->loader->run();
    }

    private function registerHooks() {
        // Admin menu and pages
        $this->loader->add_action('admin_menu', $this, 'addAdminMenu');
        $this->loader->add_action('admin_enqueue_scripts', $this, 'enqueueAdminAssets');
        
        // Plugin initialization and checks
        $this->loader->add_action('plugins_loaded', $this, 'checkDependencies');
        $this->loader->add_action('init', $this, 'loadTextDomain');
        
        // AJAX handlers voor het dashboard
        $this->loader->add_action('wp_ajax_tvlm_get_available_pages', $this, 'ajaxGetAvailablePages');
        $this->loader->add_action('wp_ajax_tvlm_get_translation_status', $this, 'ajaxGetTranslationStatus');
    
        // Activation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function checkDependencies() {
        if (!class_exists('Polylang')) {
            add_action('admin_notices', function() {
                ?>
                <div class="notice notice-error">
                    <p><?php _e('Toolvizion Language Manager requires Polylang to be installed and activated.', 'tvlm'); ?></p>
                </div>
                <?php
            });
        }
    }

    public function addAdminMenu() {
        if (!current_user_can('manage_options')) {
            return;
        }
    
        add_menu_page(
            __('Language Manager', 'tvlm'),
            __('Language Manager', 'tvlm'),
            'manage_options',
            'tvlm-dashboard',
            [$this, 'renderDashboard'],
            'dashicons-translation',
            100
        );
    
        add_submenu_page(
            'tvlm-dashboard',
            __('Staging', 'tvlm'),
            __('Staging', 'tvlm'),
            'manage_options',
            'tvlm-staging',
            [$this->staging, 'render_staging_page']
        );
    }
    public function renderDashboard() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        require_once TVLM_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public function enqueueAdminAssets($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'tvlm') === false) {
            return;
        }
    
        // Debug logging
        error_log('Enqueuing admin assets for hook: ' . $hook);
    
        // Enqueue jQuery UI
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');
    
        // Enqueue our admin styles
        wp_enqueue_style(
            'tvlm-admin',
            TVLM_PLUGIN_URL . 'admin/css/tvlm-admin.css',
            [],
            $this->version
        );
    
        // Enqueue our admin scripts
        wp_enqueue_script(
            'tvlm-admin',
            TVLM_PLUGIN_URL . 'admin/js/tvlm-admin.js',
            ['jquery', 'jquery-ui-dialog'],
            $this->version,
            true
        );
    
        // Add AJAX nonce and localized data
        wp_localize_script('tvlm-admin', 'tvlm_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tvlm_ajax_nonce'),
            'strings' => [
                'confirm_translate' => __('Are you sure you want to translate this page?', 'tvlm'),
                'confirm_sync' => __('Are you sure you want to sync this translation?', 'tvlm'),
                'loading' => __('Loading...', 'tvlm'),
                'error' => __('An error occurred', 'tvlm'),
                'success' => __('Operation completed successfully', 'tvlm')
            ]
        ]);
    }

    public function ajaxGetAvailablePages() {
        check_ajax_referer('tvlm_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'tvlm')]);
        }
        
        $target_lang = isset($_POST['target_lang']) ? sanitize_text_field($_POST['target_lang']) : '';
        if (empty($target_lang)) {
            wp_send_json_error(['message' => __('Target language not specified', 'tvlm')]);
        }

        // Get available pages
        $pages = $this->getAvailablePages($target_lang);
        
        if (is_wp_error($pages)) {
            wp_send_json_error(['message' => $pages->get_error_message()]);
        }

        wp_send_json_success(['pages' => $pages]);
    }

    private function getAvailablePages($target_lang) {
        global $wpdb;

        try {
            $default_lang = pll_default_language('locale');
            
            $query = $wpdb->prepare(
                "SELECT DISTINCT p.ID, p.post_title, p.post_status, p.post_modified
                FROM {$wpdb->posts} p
                JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                WHERE p.post_type = 'page'
                AND p.post_status = 'publish'
                AND tt.taxonomy = 'language'
                AND t.slug = %s
                ORDER BY p.post_title ASC",
                $default_lang
            );

            $pages = $wpdb->get_results($query);

            if ($wpdb->last_error) {
                throw new Exception($wpdb->last_error);
            }

            // Add translation status for each page
            foreach ($pages as &$page) {
                $page->translation_status = $this->getPageTranslationStatus($page->ID, $target_lang);
            }

            return $pages;

        } catch (Exception $e) {
            error_log('TVLM Error in getAvailablePages: ' . $e->getMessage());
            return new WP_Error('db_error', $e->getMessage());
        }
    }

    private function getPageTranslationStatus($page_id, $target_lang) {
        global $wpdb;
        
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT translation_status 
            FROM {$wpdb->prefix}tvlm_staging_posts 
            WHERE original_id = %d 
            AND target_language = %s",
            $page_id,
            $target_lang
        ));

        return $status ? $status : 'not-started';
    }

    public function ajaxGetTranslationStatus() {
        check_ajax_referer('tvlm_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'tvlm')]);
        }

        $page_id = isset($_POST['page_id']) ? intval($_POST['page_id']) : 0;
        $target_lang = isset($_POST['target_lang']) ? sanitize_text_field($_POST['target_lang']) : '';

        if (!$page_id || empty($target_lang)) {
            wp_send_json_error(['message' => __('Invalid parameters', 'tvlm')]);
        }

        $status = $this->getPageTranslationStatus($page_id, $target_lang);
        wp_send_json_success(['status' => $status]);
    }

    public function loadTextDomain() {
        load_plugin_textdomain('tvlm', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function activate() {
        // Run activation tasks
        TVLM_Activator::activate();

        // Additional activation tasks if needed
        flush_rewrite_rules();
    }

    public function deactivate() {
        // Cleanup tasks if needed
        flush_rewrite_rules();
    }
}

// Initialize the plugin
function tvlm_init() {
    return ToolVizionLanguageManager::getInstance();
}

// Start the plugin
add_action('plugins_loaded', 'tvlm_init');