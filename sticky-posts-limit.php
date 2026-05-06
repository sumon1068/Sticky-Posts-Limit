<?php
/**
 * Plugin Name: Sticky Posts Limit
 * Plugin URI: https://wppassion.com/plugins/sticky-posts-limit/
 * Description: Limit the number of sticky posts in WordPress. Automatically keeps only the latest N sticky posts based on your settings.
 * Version: 1.0.1
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
 *
 * - Blank or unset limit → do nothing (unlimited).
 * - Limit < 1            → do nothing (unlimited).
 * - Scheduled/draft sticky posts are never counted against the limit
 *   and their original position in the array is preserved.
 * - get_post_status() is called only once per post (single-pass cache).
 */
function wppspl_enforce_sticky_limit() {
    $raw_limit = get_option('wppspl_sticky_limit', '');

    // Do nothing if the setting hasn't been configured yet.
    if ($raw_limit === '' || $raw_limit === false) {
        return;
    }

    $limit = (int) $raw_limit;

    // Treat 0 or negative as unlimited.
    if ($limit < 1) {
        return;
    }

    $sticky_posts = get_option('sticky_posts', []);

    if (!is_array($sticky_posts) || empty($sticky_posts)) {
        return;
    }

    $published = [];
    $statuses  = [];

    // Single pass: cache every post's status and collect published IDs.
    foreach ($sticky_posts as $post_id) {
        $status = get_post_status($post_id);
        $statuses[$post_id] = $status;
        if ($status === 'publish') {
            $published[] = $post_id;
        }
    }

    // Only enforce against published posts; scheduled/drafts don't count.
    if (count($published) <= $limit) {
        return;
    }

    // Keep the most recently stickied published posts (tail of the array).
    $keep_published = array_slice($published, -$limit);

    // Use a lookup set for O(1) membership checks.
    $keep_lookup = array_flip($keep_published);

    // Rebuild the list in original insertion order:
    // - Published posts: only those in $keep_lookup survive.
    // - Non-published posts (scheduled, draft, etc.): always kept as-is.
    $updated = [];
    foreach ($sticky_posts as $post_id) {
        if ($statuses[$post_id] === 'publish') {
            if (isset($keep_lookup[$post_id])) {
                $updated[] = $post_id;
            }
        } else {
            $updated[] = $post_id;
        }
    }

    update_option('sticky_posts', array_values($updated));
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
 * Enforce on the very first save of the setting.
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
 * Re-enforce when a scheduled sticky post transitions to published.
 * This is the moment a previously-scheduled sticky becomes "real" and
 * should be counted against the limit, potentially bumping an older one.
 */
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($new_status === 'publish' && $old_status !== 'publish') {
        if (is_sticky($post->ID)) {
            wppspl_enforce_sticky_limit();
        }
    }
}, 10, 3);

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
        'type'              => 'string', // Keep as string so empty value is preserved.
        'sanitize_callback' => function ($value) {
            if ($value === '' || $value === null) {
                return '';
            }
            return (string) absint($value);
        },
        'default'           => '',
    ]);

    add_settings_section(
        'wppspl_sticky_section',
        'General Settings',
        function () {
            echo '<p>Set how many sticky posts you want to keep at most. Leave blank for unlimited.</p>';
        },
        'wppspl-sticky-settings'
    );

    add_settings_field(
        'wppspl_sticky_limit',
        'Number of Sticky Posts',
        function () {
            $value = get_option('wppspl_sticky_limit', '');
            echo '<input type="number" name="wppspl_sticky_limit" value="' . esc_attr($value) . '" min="1" placeholder="Unlimited" />';
            echo '<p class="description">Only the latest N published sticky posts will remain. Leave blank for no limit. Scheduled sticky posts are never removed early.</p>';
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
