<?php
/**
 * Class TVLM_Staging
 * Handles all staging functionality for the plugin
 */
class TVLM_Staging {
    private $loader;
    private $plugin_name;
    private $version;
    private $is_initialized = false;

    /**
     * Initialize the class and set its properties.
     *
     * @param TVLM_Loader $loader The loader that's responsible for maintaining and registering all hooks.
     * @throws Exception If loader is not provided or invalid.
     */
    public function __construct($loader) {
        // Check if we're in admin context
        if (!is_admin()) {
            return;
        }

        try {
            // Validate loader
            if (!$loader instanceof TVLM_Loader) {
                error_log('TVLM Error: Invalid loader provided to TVLM_Staging');
                throw new Exception('Invalid loader provided to TVLM_Staging');
            }

            // Set basic properties
            $this->loader = $loader;
            $this->plugin_name = 'tvlm';
            $this->version = defined('TVLM_VERSION') ? TVLM_VERSION : '1.0.0';

            // Verify user permissions and setup hooks
            if ($this->verify_admin_permissions()) {
                $this->is_initialized = true;
                $this->setup_hooks();
            } else {
                error_log('TVLM Notice: User does not have sufficient permissions for staging functionality');
            }

        } catch (Exception $e) {
            error_log('TVLM Error in constructor: ' . $e->getMessage());
            // Don't throw the exception, just log it
            return;
        }
    }

    /**
     * Verify if current user has required admin permissions
     *
     * @return bool
     */
    private function verify_admin_permissions() {
        // Basic checks first
        if (!function_exists('is_user_logged_in') || !function_exists('current_user_can')) {
            error_log('TVLM Notice: WordPress functions not available');
            return false;
        }

        if (!is_user_logged_in()) {
            error_log('TVLM Notice: User not logged in');
            return false;
        }

        if (!current_user_can('manage_options')) {
            error_log('TVLM Notice: User lacks manage_options capability');
            return false;
        }

        return true;
    }

    /**
     * Setup all hooks for the staging functionality
     */
    private function setup_hooks() {
        if (!$this->is_initialized || !$this->loader) {
            error_log('TVLM Error: Cannot setup hooks - not properly initialized');
            return;
        }

        try {
            // Admin menu registration
            $this->loader->add_action('admin_menu', $this, 'init_staging');

            // Post actions
            $this->loader->add_action('post_row_actions', $this, 'add_staging_action', 10, 2);
            $this->loader->add_action('page_row_actions', $this, 'add_staging_action', 10, 2);

            // AJAX handlers
            $this->loader->add_action('wp_ajax_tvlm_get_available_languages', $this, 'ajax_get_available_languages');
            $this->loader->add_action('wp_ajax_tvlm_copy_to_staging', $this, 'ajax_copy_to_staging');
            $this->loader->add_action('wp_ajax_tvlm_get_staging_items', $this, 'ajax_get_staging_items');
            $this->loader->add_action('wp_ajax_tvlm_get_staging_item', $this, 'ajax_get_staging_item');
            $this->loader->add_action('wp_ajax_tvlm_update_staging_item', $this, 'ajax_update_staging_item');
            $this->loader->add_action('wp_ajax_tvlm_sync_translation', $this, 'ajax_sync_translation');

            // Admin notices
            $this->loader->add_action('admin_notices', $this, 'show_admin_notices');

        } catch (Exception $e) {
            error_log('TVLM Error in setup_hooks: ' . $e->getMessage());
        }
    }
    
    public function init_staging() {
        // Controleer of de gebruiker administrator is
        if (!current_user_can('manage_options')) {
            return;
        }
    
        // Registreer de staging pagina
        add_submenu_page(
            'tvlm-dashboard',
            __('Staging', 'tvlm'),
            __('Staging', 'tvlm'),
            'manage_options',
            'tvlm-staging',
            [$this, 'render_staging_page']
        );
    }

    /**
     * Add staging menu item
     */
    public function add_staging_menu() {
        add_submenu_page(
            'tvlm-dashboard', // Parent slug
            __('Staging', 'tvlm'),
            __('Staging', 'tvlm'),
            'manage_options',
            'admin.php?page=tvlm-staging', // Aangepaste menu slug
            [$this, 'render_staging_page']
        );
    }

    /**
     * Add staging to language menu
     */
    public function add_to_language_menu() {
        add_submenu_page(
            'mlang', // Polylang menu slug
            __('Staging', 'tvlm'),
            __('Staging', 'tvlm'),
            'manage_options',
            'admin.php?page=tvlm-staging', // Aangepaste menu slug
            [$this, 'render_staging_page']
        );
    }

    /**
     * Render the staging page
     */
    public function render_staging_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Voeg deze debug regel toe
        error_log('Rendering staging page');
        
        require_once TVLM_PLUGIN_DIR . 'admin/views/staging.php';
    }

    /**
     * Add staging action to post/page lists
     */
    public function add_staging_action($actions, $post) {
        if (current_user_can('manage_options')) {
            $post_lang = pll_get_post_language($post->ID, 'locale');
            $default_lang = pll_default_language('locale');
            
            if ($post_lang === $default_lang) {
                $nonce = wp_create_nonce('tvlm_copy_to_staging_' . $post->ID);
                $actions['tvlm_staging'] = sprintf(
                    '<a href="#" class="tvlm-copy-to-staging" data-id="%d" data-nonce="%s">%s</a>',
                    $post->ID,
                    $nonce,
                    __('Copy to Staging', 'tvlm')
                );
            }
        }
        return $actions;
    }

    /**
     * AJAX: Get available languages
     */
    public function ajax_get_available_languages() {
        check_ajax_referer('tvlm_ajax_nonce', 'nonce');
        
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(['message' => __('Invalid post ID', 'tvlm')]);
        }

        $default_lang = pll_get_post_language($post_id, 'locale');
        $languages = pll_languages_list(['fields' => ['locale', 'name']]);
        
        $available_languages = array_filter($languages, function($lang) use ($default_lang) {
            return $lang->locale !== $default_lang;
        });

        wp_send_json_success(['languages' => array_values($available_languages)]);
    }

    /**
     * AJAX: Copy content to staging
     */
    public function ajax_copy_to_staging() {
        check_ajax_referer('tvlm_copy_to_staging_' . $_POST['post_id']);
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $target_lang = isset($_POST['target_lang']) ? sanitize_text_field($_POST['target_lang']) : '';
        
        if (!$post_id || !$target_lang) {
            wp_send_json_error(['message' => __('Invalid parameters', 'tvlm')]);
        }

        $result = $this->copy_post_to_staging($post_id, $target_lang);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Content copied to staging successfully', 'tvlm'),
            'staging_id' => $result
        ]);
    }
    // Voeg deze methode toe aan class-tvlm-staging.php

public function ajax_get_available_pages() {
    // Verify nonce
    if (!check_ajax_referer('tvlm_ajax_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'Invalid security token'));
        return;
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }

    // Get target language
    $target_lang = isset($_POST['target_lang']) ? sanitize_text_field($_POST['target_lang']) : '';
    if (empty($target_lang)) {
        wp_send_json_error(array('message' => 'Target language not specified'));
        return;
    }

    global $wpdb;

    // Get available pages
    $available_pages_query = $wpdb->prepare(
        "SELECT p.ID, 
                p.post_title,
                p.post_status,
                p.post_modified,
                tr.term_taxonomy_id
         FROM {$wpdb->posts} p
         JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
         JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
         JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
         WHERE p.post_type = 'page'
         AND p.post_status = 'publish'
         AND tt.taxonomy = 'language'
         AND t.slug = 'en'
         ORDER BY p.post_title ASC"
    );
    
    $available_pages = $wpdb->get_results($available_pages_query);

    if ($available_pages === false) {
        wp_send_json_error(array('message' => 'Database error: ' . $wpdb->last_error));
        return;
    }

    // Get translation statuses for these pages
    foreach ($available_pages as &$page) {
        $translation_status = $wpdb->get_row($wpdb->prepare(
            "SELECT translation_status 
             FROM {$wpdb->prefix}tvlm_staging_posts 
             WHERE original_id = %d 
             AND target_language = %s",
            $page->ID,
            $target_lang
        ));

        $page->translation_status = $translation_status;
    }

    wp_send_json_success(array('pages' => $available_pages));
}

    /**
     * Copy a post to staging
     */
    private function copy_post_to_staging($post_id, $target_lang) {
        global $wpdb;
        
        // Check if already in staging
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tvlm_staging_posts 
            WHERE original_id = %d AND target_language = %s",
            $post_id,
            $target_lang
        ));

        if ($existing) {
            return new WP_Error(
                'already_exists', 
                __('This content is already in staging for the selected language', 'tvlm')
            );
        }

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', __('Post not found', 'tvlm'));
        }

        // Get source language
        $source_lang = pll_get_post_language($post_id, 'locale');
        
        // Prepare staging data
        $staging_data = [
            'original_id' => $post_id,
            'source_language' => $source_lang,
            'target_language' => $target_lang,
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_type' => $post->post_type,
            'translation_status' => 'pending',
            'last_sync_date' => current_time('mysql'),
            'sync_user_id' => get_current_user_id()
        ];

        // Insert into staging table
        $wpdb->insert(
            $wpdb->prefix . 'tvlm_staging_posts',
            $staging_data,
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );

        if ($wpdb->last_error) {
            return new WP_Error('db_error', $wpdb->last_error);
        }

        $staging_id = $wpdb->insert_id;

        // Copy relevant post meta
        $this->copy_post_meta_to_staging($post_id, $staging_id);

        return $staging_id;
    }

    /**
     * Copy post meta to staging
     */
    private function copy_post_meta_to_staging($post_id, $staging_id) {
        global $wpdb;
        
        $meta = get_post_meta($post_id);
        $exclude_meta = ['_edit_lock', '_edit_last', '_wp_old_slug'];
        
        foreach ($meta as $meta_key => $meta_values) {
            if (in_array($meta_key, $exclude_meta)) {
                continue;
            }
            
            foreach ($meta_values as $meta_value) {
                $wpdb->insert(
                    $wpdb->prefix . 'tvlm_staging_postmeta',
                    [
                        'staging_post_id' => $staging_id,
                        'meta_key' => $meta_key,
                        'meta_value' => $meta_value
                    ],
                    ['%d', '%s', '%s']
                );
            }
        }
    }

    /**
     * AJAX: Get staging items
     */
    public function ajax_get_staging_items() {
        check_ajax_referer('tvlm_ajax_nonce', 'nonce');
        
        global $wpdb;
        
        $target_lang = isset($_GET['target_lang']) ? sanitize_text_field($_GET['target_lang']) : '';
        
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tvlm_staging_posts 
            WHERE target_language = %s 
            ORDER BY last_sync_date DESC",
            $target_lang
        ));
        
        wp_send_json_success(['items' => $items]);
    }

    /**
     * AJAX: Get single staging item
     */
    public function ajax_get_staging_item() {
        check_ajax_referer('tvlm_ajax_nonce', 'nonce');
        
        global $wpdb;
        
        $item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;
        
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tvlm_staging_posts WHERE id = %d",
            $item_id
        ));
        
        if (!$item) {
            wp_send_json_error(['message' => __('Item not found', 'tvlm')]);
        }
        
        wp_send_json_success(['item' => $item]);
    }

    /**
     * AJAX: Update staging item
     */
    public function ajax_update_staging_item() {
        check_ajax_referer('tvlm_ajax_nonce', 'nonce');
        
        global $wpdb;
        
        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $content = isset($_POST['content']) ? wp_kses_post($_POST['content']) : '';
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        
        if (!$item_id || !$title || !$content || !$status) {
            wp_send_json_error(['message' => __('Invalid parameters', 'tvlm')]);
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'tvlm_staging_posts',
            [
                'post_title' => $title,
                'post_content' => $content,
                'translation_status' => $status,
                'last_sync_date' => current_time('mysql')
            ],
            ['id' => $item_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error(['message' => __('Update failed', 'tvlm')]);
        }

        wp_send_json_success(['message' => __('Item updated successfully', 'tvlm')]);
    }

    /**
     * AJAX: Sync translation to live content
     */
    public function ajax_sync_translation() {
        check_ajax_referer('tvlm_ajax_nonce', 'nonce');
        
        global $wpdb;
        
        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        
        if (!$item_id) {
            wp_send_json_error(['message' => __('Invalid item ID', 'tvlm')]);
        }

        $result = $this->sync_translation_to_live($item_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Translation synced successfully', 'tvlm'),
            'post_id' => $result
        ]);
    }

    /**
     * Sync translation to live content
     */
    private function sync_translation_to_live($staging_id) {
        global $wpdb;
        
        // Get staging item
        $staging_item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tvlm_staging_posts WHERE id = %d",
            $staging_id
        ));

        if (!$staging_item) {
            return new WP_Error('not_found', __('Staging item not found', 'tvlm'));
        }

        if ($staging_item->translation_status !== 'completed') {
            return new WP_Error('not_completed', __('Translation must be marked as completed before syncing', 'tvlm'));
        }

        // Check if translation exists
        $translation_id = pll_get_post($staging_item->original_id, $staging_item->target_language);
        
        // Prepare post data
        $post_data = [
            'post_title' => $staging_item->post_title,
            'post_content' => $staging_item->post_content,
            'post_type' => $staging_item->post_type,
            'post_status' => 'publish'
        ];

        if ($translation_id) {
            // Update existing translation
            $post_data['ID'] = $translation_id;
            $result = wp_update_post($post_data);
        } else {
            // Create new translation
            $result = wp_insert_post($post_data);
            if (!is_wp_error($result)) {
                // Connect translation
                pll_set_post_language($result, $staging_item->target_language);
                pll_save_post_translations([
                    $staging_item->source_language => $staging_item->original_id,
                    $staging_item->target_language => $result
                ]);
            }
        }

        if (is_wp_error($result)) {
            return $result;
        }// Copy meta data
        $staging_meta = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tvlm_staging_postmeta WHERE staging_post_id = %d",
            $staging_id
        ));

        foreach ($staging_meta as $meta) {
            update_post_meta($result, $meta->meta_key, $meta->meta_value);
        }

        // Update staging status
        $wpdb->update(
            $wpdb->prefix . 'tvlm_staging_posts',
            [
                'translation_status' => 'synced',
                'last_sync_date' => current_time('mysql')
            ],
            ['id' => $staging_id],
            ['%s', '%s'],
            ['%d']
        );

        // Log the sync
        $this->log_sync_operation($staging_id, $result);

        return $result;
    }

    /**
     * Log sync operation
     */
    private function log_sync_operation($staging_id, $post_id) {
        global $wpdb;
        
        $staging_item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tvlm_staging_posts WHERE id = %d",
            $staging_id
        ));

        if ($staging_item) {
            $wpdb->insert(
                $wpdb->prefix . 'tvlm_sync_log',
                [
                    'sync_date' => current_time('mysql'),
                    'user_id' => get_current_user_id(),
                    'content_type' => $staging_item->post_type,
                    'content_id' => $post_id,
                    'source_language' => $staging_item->source_language,
                    'target_language' => $staging_item->target_language,
                    'sync_status' => 'success',
                    'sync_message' => sprintf(
                        __('Successfully synced translation for post ID %d', 'tvlm'),
                        $staging_item->original_id
                    )
                ],
                [
                    '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s'
                ]
            );
        }
    }

    /**
     * Show admin notices
     */
    public function show_admin_notices() {
        if (isset($_GET['tvlm_message'])) {
            $message = sanitize_text_field($_GET['tvlm_message']);
            $type = isset($_GET['tvlm_type']) ? sanitize_text_field($_GET['tvlm_type']) : 'success';
            
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($type),
                esc_html($message)
            );
        }
    }

    /**
     * Get translation status label
     */
    private function get_status_label($status) {
        $labels = [
            'pending' => __('Pending', 'tvlm'),
            'in_progress' => __('In Progress', 'tvlm'),
            'completed' => __('Completed', 'tvlm'),
            'synced' => __('Synced', 'tvlm')
        ];

        return isset($labels[$status]) ? $labels[$status] : $status;
    }
public function bulk_copy_to_staging($target_lang) {
    global $wpdb;
    $results = array(
        'success' => 0,
        'failed' => 0,
        'errors' => array()
    );
    
    // Haal alle pagina's op in de default taal
    $default_lang = pll_default_language('locale');
    $pages = get_posts(array(
        'post_type' => 'page',
        'posts_per_page' => -1,
        'lang' => $default_lang
    ));

    foreach ($pages as $page) {
        // Check of de pagina al in staging staat voor deze taal
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tvlm_staging_posts 
            WHERE original_id = %d AND target_language = %s",
            $page->ID,
            $target_lang
        ));

        if (!$existing) {
            $result = $this->copy_post_to_staging($page->ID, $target_lang);
            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    __('Failed to copy page %s: %s', 'tvlm'),
                    $page->post_title,
                    $result->get_error_message()
                );
            } else {
                $results['success']++;
            }
        }
    }

    return $results;
}

public function get_available_target_languages() {
    $default_lang = pll_default_language('locale');
    $languages = pll_languages_list(['fields' => ['locale', 'name']]);
    
    return array_filter($languages, function($lang) use ($default_lang) {
        return $lang->locale !== $default_lang;
    });
}

// AJAX handler voor bulk copy
public function ajax_bulk_copy_to_staging() {
    check_ajax_referer('tvlm_ajax_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'tvlm')]);
    }
    
    $target_lang = isset($_POST['target_lang']) ? sanitize_text_field($_POST['target_lang']) : '';
    if (!$target_lang) {
        wp_send_json_error(['message' => __('No target language specified', 'tvlm')]);
    }
    
    $results = $this->bulk_copy_to_staging($target_lang);
    wp_send_json_success($results);
}
    /**
     * Validate translation status
     */
    private function validate_status($status) {
        $valid_statuses = ['pending', 'in_progress', 'completed', 'synced'];
        return in_array($status, $valid_statuses) ? $status : 'pending';
    }

    /**
     * Clean up staging items
     */
    public function cleanup_staging_items($days = 30) {
        global $wpdb;
        
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $wpdb->query($wpdb->prepare(
            "DELETE p, m FROM {$wpdb->prefix}tvlm_staging_posts p 
            LEFT JOIN {$wpdb->prefix}tvlm_staging_postmeta m ON p.id = m.staging_post_id 
            WHERE p.translation_status = 'synced' 
            AND p.last_sync_date < %s",
            $date
        ));
    }
}