<?php
if (!defined('ABSPATH')) {
    exit;
}

// Haal statistieken op
global $wpdb;

// Basis statistieken
$total_posts = wp_count_posts()->publish;
$total_pages = wp_count_posts('page')->publish;

// Haal alle talen op uit de database
$languages_query = $wpdb->prepare("
    SELECT tt.term_id,
           tt.term_taxonomy_id,
           tt.taxonomy,
           t.name,
           t.slug,
           tt.description
    FROM {$wpdb->prefix}term_taxonomy tt
    JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
    WHERE tt.taxonomy = %s
    AND t.slug != 'en'  /* Exclude English */
    ORDER BY t.term_id ASC",
    'language'
);
$languages = $wpdb->get_results($languages_query);
$available_languages = [];

// Verwerk de taalinformatie
foreach ($languages as $lang) {
    $lang_settings = unserialize($lang->description);
    if (!empty($lang_settings) && isset($lang_settings['locale'])) {
        $available_languages[] = [
            'term_id' => $lang->term_id,
            'taxonomy_id' => $lang->term_taxonomy_id,
            'name' => $lang->name,
            'slug' => $lang->slug,
            'locale' => $lang_settings['locale']
        ];
    }
}

// Haal default taal op
$default_lang_query = $wpdb->prepare("
    SELECT option_value 
    FROM {$wpdb->options} 
    WHERE option_name = %s",
    'polylang'
);
$available_pages_query = $wpdb->prepare("
    SELECT p.ID, 
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
$polylang_options = unserialize($wpdb->get_var($default_lang_query));
$default_lang = !empty($polylang_options['default_lang']) ? $polylang_options['default_lang'] : '';

// Filter beschikbare talen (exclusief default)
$translation_languages = array_filter($available_languages, function($lang) use ($default_lang) {
    return $lang['slug'] !== $default_lang;
});

// Vertaalstatistieken
$staging_stats = $wpdb->get_results("
    SELECT translation_status, COUNT(*) as count 
    FROM {$wpdb->prefix}tvlm_staging_posts 
    GROUP BY translation_status
");

// Recent gewijzigde vertalingen
$recent_translations = $wpdb->get_results("
    SELECT p.*, 
           COALESCE(u.display_name, 'System') as translator_name
    FROM {$wpdb->prefix}tvlm_staging_posts p
    LEFT JOIN {$wpdb->users} u ON p.sync_user_id = u.ID
    ORDER BY last_sync_date DESC 
    LIMIT 5
");

// Onvertaalde content per taal
$untranslated_stats = [];
foreach ($translation_languages as $lang) {
    $untranslated_query = $wpdb->prepare("
        SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->term_relationships} tr 
            ON p.ID = tr.object_id
        LEFT JOIN {$wpdb->term_taxonomy} tt 
            ON tr.term_taxonomy_id = tt.term_taxonomy_id
            AND tt.taxonomy = 'language'
            AND tt.term_id = %d
        WHERE p.post_type = 'page'
        AND p.post_status = 'publish'
        AND tr.object_id IS NULL",
        $lang['term_id']
    );

    $untranslated_count = $wpdb->get_var($untranslated_query);
    
    $untranslated_stats[$lang['name']] = [
        'count' => $untranslated_count,
        'locale' => $lang['locale'],
        'slug' => $lang['slug'],
        'term_id' => $lang['term_id']
    ];
}

// Translation workload
$workload_stats = $wpdb->get_results("
    SELECT target_language, 
           COUNT(*) as total_items,
           SUM(CASE WHEN translation_status = 'pending' THEN 1 ELSE 0 END) as pending,
           SUM(CASE WHEN translation_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
           SUM(CASE WHEN translation_status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM {$wpdb->prefix}tvlm_staging_posts
    GROUP BY target_language
");

// Debug output voor development
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('Languages found: ' . print_r($available_languages, true));
    error_log('Default language: ' . $default_lang);
    error_log('Translation languages: ' . print_r($translation_languages, true));
    error_log('Untranslated stats: ' . print_r($untranslated_stats, true));
}
$available_pages = $wpdb->get_results($available_pages_query);
?>

<div class="wrap tvlm-wrap">
    <h1 class="wp-heading-inline"><?php _e('Language Manager Dashboard', 'tvlm'); ?></h1>
    <hr class="wp-header-end">

    <div class="tvlm-dashboard-grid">
        <!-- Language Overview -->
        <div class="tvlm-card">
            <div class="tvlm-card-header">
                <h2><?php _e('Language Overview', 'tvlm'); ?></h2>
            </div>
            <div class="tvlm-card-content">
                <div class="tvlm-stats-grid">
                    <div class="tvlm-stat-item">
                        <span class="tvlm-stat-label"><?php _e('Total Languages', 'tvlm'); ?></span>
                        <span class="tvlm-stat-value"><?php echo count($languages); ?></span>
                    </div>
                    <div class="tvlm-stat-item">
                        <span class="tvlm-stat-label"><?php _e('Default Language', 'tvlm'); ?></span>
                        <span class="tvlm-stat-value">
                            <?php 
                            $default_name = pll_default_language('name');
                            echo esc_html($default_name); 
                            ?>
                        </span>
                    </div>
                    <div class="tvlm-stat-item">
                        <span class="tvlm-stat-label"><?php _e('Total Pages', 'tvlm'); ?></span>
                        <span class="tvlm-stat-value"><?php echo $total_pages; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Translation Status -->
        <div class="tvlm-card">
            <div class="tvlm-card-header">
                <h2><?php _e('Translation Status', 'tvlm'); ?></h2>
            </div>
            <div class="tvlm-card-content">
                <div class="tvlm-status-grid">
                    <?php 
                    $status_counts = [
                        'pending' => 0,
                        'in_progress' => 0,
                        'completed' => 0,
                        'synced' => 0
                    ];
                    foreach ($staging_stats as $stat) {
                        $status_counts[$stat->translation_status] = $stat->count;
                    }
                    ?>
                    <?php foreach ($status_counts as $status => $count): ?>
                        <div class="tvlm-stat-item">
                            <span class="tvlm-status tvlm-status-<?php echo $status; ?>">
                                <?php echo esc_html(ucfirst($status)); ?>
                            </span>
                            <span class="tvlm-stat-value"><?php echo $count; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="tvlm-actions">
                    <button id="tvlm-bulk-translate" class="button button-primary">
                        <?php _e('Translate Pages', 'tvlm'); ?>
                    </button>
                    <a href="<?php echo admin_url('admin.php?page=tvlm-staging'); ?>" class="button">
                        <?php _e('Manage Translations', 'tvlm'); ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Untranslated Content -->
        <div class="tvlm-card">
            <div class="tvlm-card-header">
                <h2><?php _e('Untranslated Content', 'tvlm'); ?></h2>
            </div>
            <div class="tvlm-card-content">
                <?php if (!empty($untranslated_stats)): ?>
                    <div class="tvlm-untranslated-grid">
                        <?php foreach ($untranslated_stats as $lang_name => $stats): ?>
                            <div class="tvlm-untranslated-item">
                                <div class="tvlm-lang-info">
                                    <?php if (!empty($stats['flag'])): ?>
                                        <img src="<?php echo esc_url($stats['flag']); ?>" 
                                             alt="<?php echo esc_attr($lang_name); ?>"
                                             class="tvlm-lang-flag">
                                    <?php endif; ?>
                                    <span class="tvlm-lang-name"><?php echo esc_html($lang_name); ?></span>
                                </div>
                                <div class="tvlm-untranslated-stats">
                                    <span class="tvlm-count"><?php echo $stats['count']; ?></span>
                                    <span class="tvlm-label"><?php _e('pages need translation', 'tvlm'); ?></span>
                                </div>
                                <button class="button tvlm-translate-now" 
                                        data-locale="<?php echo esc_attr($stats['locale']); ?>">
                                    <?php _e('Translate Now', 'tvlm'); ?>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="tvlm-no-items"><?php _e('All content is translated', 'tvlm'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="tvlm-card">
            <div class="tvlm-card-header">
                <h2><?php _e('Recent Activity', 'tvlm'); ?></h2>
            </div>
            <div class="tvlm-card-content">
                <?php if ($recent_translations): ?>
                    <div class="tvlm-activity-list">
                        <?php foreach ($recent_translations as $item): ?>
                            <div class="tvlm-activity-item">
                                <div class="tvlm-activity-header">
                                    <span class="tvlm-activity-title"><?php echo esc_html($item->post_title); ?></span>
                                    <span class="tvlm-status tvlm-status-<?php echo $item->translation_status; ?>">
                                        <?php echo esc_html(ucfirst($item->translation_status)); ?>
                                    </span>
                                </div>
                                <div class="tvlm-activity-meta">
                                    <span class="tvlm-meta-item">
                                        <span class="dashicons dashicons-translation"></span>
                                        <?php echo esc_html($item->source_language); ?> → 
                                        <?php echo esc_html($item->target_language); ?>
                                    </span>
                                    <span class="tvlm-meta-item">
                                        <span class="dashicons dashicons-businessman"></span>
                                        <?php echo esc_html($item->translator_name); ?>
                                    </span>
                                    <span class="tvlm-meta-item">
                                        <span class="dashicons dashicons-calendar-alt"></span>
                                        <?php echo human_time_diff(strtotime($item->last_sync_date), current_time('timestamp')); ?> ago
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="tvlm-no-items"><?php _e('No recent translation activity', 'tvlm'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bulk Translation Dialog -->
    <div id="tvlm-bulk-translate-dialog" style="display:none;" title="<?php _e('Copy Pages to Translation', 'tvlm'); ?>">
        <form class="tvlm-translation-form">
            <div class="tvlm-form-row">
                <label for="tvlm-bulk-target-language" class="tvlm-form-label">
                    <?php _e('Select target language for translation:', 'tvlm'); ?>
                </label>
                <select id="tvlm-bulk-target-language" class="tvlm-select">
                    <option value=""><?php _e('-- Select Language --', 'tvlm'); ?></option>
                    <?php foreach ($available_languages as $lang): ?>
                        <option value="<?php echo esc_attr($lang['locale']); ?>" 
                                data-flag="<?php echo esc_url($lang['flag']); ?>">
                            <?php echo esc_html($lang['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tvlm-progress" style="display:none;">
                <div class="tvlm-progress-bar"></div>
                <div class="tvlm-progress-status"></div>
            </div>
        </form>
    </div>
</div>
<!-- Pages Available for Translation -->
<div class="tvlm-card tvlm-full-width">
    <div class="tvlm-card-header">
        <h2><?php _e('Pages Available for Translation', 'tvlm'); ?></h2>
    </div>
    <div class="tvlm-card-content">
        <div class="tvlm-pages-list">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="column-title"><?php _e('Page Title', 'tvlm'); ?></th>
                        <th class="column-status"><?php _e('Status', 'tvlm'); ?></th>
                        <th class="column-languages"><?php _e('Translation Status', 'tvlm'); ?></th>
                        <th class="column-modified"><?php _e('Last Modified', 'tvlm'); ?></th>
                        <th class="column-actions"><?php _e('Actions', 'tvlm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($available_pages as $page): 
                        // Haal vertalingsstatus op voor elke beschikbare taal
                        $translation_statuses = $wpdb->get_results($wpdb->prepare("
                            SELECT target_language, translation_status 
                            FROM {$wpdb->prefix}tvlm_staging_posts 
                            WHERE original_id = %d",
                            $page->ID
                        ));
                        
                        $status_by_lang = [];
                        foreach ($translation_statuses as $status) {
                            $status_by_lang[$status->target_language] = $status->translation_status;
                        }
                    ?>
                    <tr>
                        <td class="column-title">
                            <strong><?php echo esc_html($page->post_title); ?></strong>
                        </td>
                        <td class="column-status">
                            <span class="tvlm-status tvlm-status-<?php echo $page->post_status; ?>">
                                <?php echo esc_html(ucfirst($page->post_status)); ?>
                            </span>
                        </td>
                        <td class="column-languages">
                            <div class="tvlm-translation-statuses">
                                <?php foreach ($translation_languages as $lang): 
                                    $status = isset($status_by_lang[$lang['locale']]) 
                                        ? $status_by_lang[$lang['locale']] 
                                        : 'not-started';
                                ?>
                                    <span class="tvlm-lang-status tvlm-status-<?php echo $status; ?>"
                                          title="<?php echo esc_attr($lang['name'] . ': ' . ucfirst($status)); ?>">
                                        <?php echo esc_html($lang['slug']); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td class="column-modified">
                            <?php echo get_the_modified_date('Y-m-d H:i:s', $page->ID); ?>
                        </td>
                        <td class="column-actions">
                            <button class="button tvlm-translate-page" 
                                    data-page-id="<?php echo esc_attr($page->ID); ?>"
                                    data-page-title="<?php echo esc_attr($page->post_title); ?>">
                                <?php _e('Translate', 'tvlm'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>