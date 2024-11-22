<?php
/**
 * Class TVLM_Activator
 * Handles plugin activation tasks like creating staging tables
 */
class TVLM_Activator {
    
    public static function activate() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        self::create_staging_tables();
        self::add_plugin_version_option();
    }

    private static function create_staging_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Staging table for posts
        $table_posts = $wpdb->prefix . 'tvlm_staging_posts';
        $sql_posts = "CREATE TABLE IF NOT EXISTS $table_posts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            original_id bigint(20) unsigned NOT NULL,
            source_language varchar(10) NOT NULL,
            target_language varchar(10) NOT NULL,
            post_title text NOT NULL,
            post_content longtext NOT NULL,
            post_type varchar(20) NOT NULL,
            translation_status varchar(20) NOT NULL DEFAULT 'pending',
            last_sync_date datetime DEFAULT NULL,
            sync_user_id bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY original_id (original_id),
            KEY source_language (source_language),
            KEY target_language (target_language),
            KEY translation_status (translation_status)
        ) $charset_collate;";
        dbDelta($sql_posts);

        // Staging table for post meta
        $table_postmeta = $wpdb->prefix . 'tvlm_staging_postmeta';
        $sql_postmeta = "CREATE TABLE IF NOT EXISTS $table_postmeta (
            meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            staging_post_id bigint(20) unsigned NOT NULL,
            meta_key varchar(255) DEFAULT NULL,
            meta_value longtext,
            PRIMARY KEY  (meta_id),
            KEY staging_post_id (staging_post_id),
            KEY meta_key (meta_key(191))
        ) $charset_collate;";
        dbDelta($sql_postmeta);

        // Create log table for tracking sync operations
        $table_log = $wpdb->prefix . 'tvlm_sync_log';
        $sql_log = "CREATE TABLE IF NOT EXISTS $table_log (
            log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sync_date datetime NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            content_type varchar(50) NOT NULL,
            content_id bigint(20) unsigned NOT NULL,
            source_language varchar(10) NOT NULL,
            target_language varchar(10) NOT NULL,
            sync_status varchar(20) NOT NULL,
            sync_message text,
            PRIMARY KEY  (log_id),
            KEY sync_date (sync_date),
            KEY content_type (content_type),
            KEY sync_status (sync_status)
        ) $charset_collate;";
        dbDelta($sql_log);
    }

    private static function add_plugin_version_option() {
        add_option('tvlm_version', TVLM_VERSION);
    }
}