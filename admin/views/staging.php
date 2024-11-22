<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap tvlm-wrap">
    <h1><?php _e('Content Staging', 'tvlm'); ?></h1>

    <div class="tvlm-staging-controls">
        <select id="tvlm-target-language">
            <?php 
            $languages = pll_languages_list(['fields' => ['name', 'locale']]);
            foreach ($languages as $lang): 
                if ($lang->locale !== pll_default_language('locale')):
            ?>
                <option value="<?php echo esc_attr($lang->locale); ?>">
                    <?php echo esc_html($lang->name); ?> 
                </option>
            <?php 
                endif;
            endforeach; 
            ?>
        </select>

        <div class="tvlm-status-filter">
            <label>
                <input type="checkbox" value="pending" checked> <?php _e('Pending', 'tvlm'); ?>
            </label>
            <label>
                <input type="checkbox" value="in_progress" checked> <?php _e('In Progress', 'tvlm'); ?>
            </label>
            <label>
                <input type="checkbox" value="completed" checked> <?php _e('Completed', 'tvlm'); ?>
            </label>
            <label>
                <input type="checkbox" value="synced"> <?php _e('Synced', 'tvlm'); ?>
            </label>
        </div>

        <button class="button button-primary" id="tvlm-refresh-staging">
            <?php _e('Refresh List', 'tvlm'); ?>
        </button>
    </div>

    <div class="tvlm-staging-items">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Title', 'tvlm'); ?></th>
                    <th><?php _e('Type', 'tvlm'); ?></th>
                    <th><?php _e('Status', 'tvlm'); ?></th>
                    <th><?php _e('Last Updated', 'tvlm'); ?></th>
                    <th><?php _e('Actions', 'tvlm'); ?></th>
                </tr>
            </thead>
            <tbody id="tvlm-staging-items-list">
                <!-- Items will be loaded via AJAX -->
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Dialog Template -->
<div id="tvlm-edit-dialog" title="<?php _e('Edit Translation', 'tvlm'); ?>" style="display:none;">
    <div class="tvlm-edit-form">
        <p>
            <label for="tvlm-edit-title"><?php _e('Title:', 'tvlm'); ?></label>
            <input type="text" id="tvlm-edit-title" class="widefat">
        </p>
        <p>
            <label for="tvlm-edit-content"><?php _e('Content:', 'tvlm'); ?></label>
            <?php 
            wp_editor('', 'tvlm-edit-content', [
                'media_buttons' => true,
                'textarea_rows' => 15,
                'textarea_name' => 'tvlm-edit-content'
            ]); 
            ?>
        </p>
        <p>
            <label for="tvlm-edit-status"><?php _e('Status:', 'tvlm'); ?></label>
            <select id="tvlm-edit-status">
                <option value="pending"><?php _e('Pending', 'tvlm'); ?></option>
                <option value="in_progress"><?php _e('In Progress', 'tvlm'); ?></option>
                <option value="completed"><?php _e('Completed', 'tvlm'); ?></option>
            </select>
        </p>
    </div>
</div>