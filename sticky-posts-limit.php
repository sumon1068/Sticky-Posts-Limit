<?php
/**
 * Plugin Name: Sticky Posts Limit
 * Plugin URI: https://wppassion.com/plugins/sticky-posts-limit/
 * Description: Limit the number of sticky posts in WordPress. Automatically keeps only the latest N sticky posts based on your settings.
 * Version: 1.0.0
 * Author: WP Passion
 * Author URI: https://wppassion.com/
 * License: GPL2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sticky-posts-limit
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core enforcement function.
 * Trims the sticky_posts option down to the latest N entries.
 */
function wppspl_enforce_sticky_limit() {
    $limit = (int) get_option('wppspl_sticky_limit', 1);
    $sticky_posts = get_option('sticky_posts', []);

    if (!is_array($sticky_posts) || empty($sticky_posts)) {
        return;
    }

    if ($limit < 1) {
        $limit = 1;
    }

    if (count($sticky_posts) <= $limit) {
        return;
    }

    // Keep the most recently stickied posts (tail of the array).
    $latest = array_slice($sticky_posts, -$limit);
    update_option('sticky_posts', array_values($latest));
}

/**
 * Enforce when the limit setting is updated (value changed).
 */
add_action('updated_option', function ($option, $old_value, $value) {
    if ($option === 'wppspl_sticky_limit') {
        wppspl_enforce_sticky_limit();
    }
}, 10, 3);

/**
 * Enforce on the very first save of the setting (option didn't exist yet).
 * updated_option does NOT fire for brand-new options, so we need this too.
 */
add_action('added_option', function ($option, $value) {
    if ($option === 'wppspl_sticky_limit') {
        wppspl_enforce_sticky_limit();
    }
}, 10, 2);

/**
 * Enforce whenever any post is stickied.
 * post_stuck was introduced in WordPress 5.7.
 * Without this hook, adding a new sticky post never triggers enforcement.
 */
add_action('post_stuck', function ($post_id) {
    wppspl_enforce_sticky_limit();
});

/**
 * Admin menu — adds a sub-page under Settings.
 */
add_action('admin_menu', function () {
    add_options_page(
        'Sticky Posts Settings',
        'Sticky Posts',
        'manage_options',
        'wppspl-sticky-settings',
        'wppspl_render_settings_page'
    );
});

/**
 * Settings page UI.
 */
function wppspl_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Sticky Posts Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('wppspl_sticky_group');
            do_settings_sections('wppspl-sticky-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

/**
 * Register setting, section, and field.
 */
add_action('admin_init', function () {
    register_setting('wppspl_sticky_group', 'wppspl_sticky_limit', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 1,
    ]);

    add_settings_section(
        'wppspl_sticky_section',
        'General Settings',
        function () {
            echo '<p>Set how many sticky posts you want to keep at most.</p>';
        },
        'wppspl-sticky-settings'
    );

    add_settings_field(
        'wppspl_sticky_limit',
        'Number of Sticky Posts',
        function () {
            $value = get_option('wppspl_sticky_limit', 1);
            echo '<input type="number" name="wppspl_sticky_limit" value="' . esc_attr($value) . '" min="1" />';
            echo '<p class="description">Only the latest N sticky posts will remain. Any extras are automatically un-stickied.</p>';
        },
        'wppspl-sticky-settings',
        'wppspl_sticky_section'
    );
});

/**
 * Activation hook — set a transient so we can redirect after activation.
 * Enforcement is intentionally NOT run here; the option may not exist yet.
 */
register_activation_hook(__FILE__, function () {
    set_transient('wppspl_do_activation_redirect', true, 30);
});

/**
 * Redirect to settings page once after activation.
 */
add_action('admin_init', function () {
    if (get_transient('wppspl_do_activation_redirect')) {
        delete_transient('wppspl_do_activation_redirect');
        if (is_admin() && current_user_can('manage_options')) {
            wp_safe_redirect(admin_url('options-general.php?page=wppspl-sticky-settings'));
            exit;
        }
    }
});