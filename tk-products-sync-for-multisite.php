<?php
/**
 * Plugin Name: TK Products Sync for Multisite
 * Plugin URI:  https://github.com/kiguta/tk-products-sync-for-multisite
 * Description: Automatically syncs WooCommerce products (Simple & Variable) from the master site to all subsites in a WordPress Multisite network. Includes bulk sync and bulk delete actions.
 * Version:     1.1.3
 * Author:      Tonie Kiguta
 * Author URI:  https://github.com/kiguta
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tk-products-sync-for-multisite
 * Domain Path: /languages
 * Network:     true
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 5.0
 * WC tested up to: 10.9.1
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MASTER_BLOG_ID', 1);
define('MASTER_PRODUCT_META_KEY', '_tk_master_product_id');

// ---------------------------------------------------------------------
// --- REQUIREMENTS CHECK ---
// ---------------------------------------------------------------------

register_activation_hook(__FILE__, 'tk_check_requirements_on_activation');
function tk_check_requirements_on_activation()
{
    if (!tk_is_woocommerce_active()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('This plugin requires WooCommerce to be installed and active. Please install and activate WooCommerce, then try again.', 'tk-products-sync-for-multisite'),
            esc_html__('Activation Failed', 'tk-products-sync-for-multisite'),
            array('back_link' => true)
        );
    }
}

add_action('admin_notices', 'tk_woocommerce_missing_notice');
function tk_woocommerce_missing_notice()
{
    if (!tk_is_woocommerce_active()) {
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong>: %s</p></div>',
            esc_html__('TK Products Sync for Multisite', 'tk-products-sync-for-multisite'),
            esc_html__('This plugin requires WooCommerce to be installed and active.', 'tk-products-sync-for-multisite')
        );
    }
}

function tk_is_woocommerce_active()
{
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    return is_plugin_active_for_network('woocommerce/woocommerce.php')
        || is_plugin_active('woocommerce/woocommerce.php');
}


add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// ---------------------------------------------------------------------
// --- DONATE LINK (Plugins screen row meta) ---
// ---------------------------------------------------------------------

add_filter('plugin_row_meta', 'tk_plugin_row_meta', 10, 2);
function tk_plugin_row_meta($links, $file)
{
    if (plugin_basename(__FILE__) !== $file) {
        return $links;
    }

    foreach ($links as $key => $link) {
        if (strpos($link, 'plugin-uri') !== false || strpos($link, 'Visit plugin site') !== false) {
            $links[$key] = str_replace('<a ', '<a target="_blank" ', $link);
        }
    }

    $links[] = '<a href="https://www.paypal.com/donate/?hosted_button_id=CSQFKDWQZVE4W" target="_blank"><img src="https://img.shields.io/badge/Donate-PayPal-green.svg" alt="' . esc_attr__('Donate with PayPal', 'tk-products-sync-for-multisite') . '" style="vertical-align:middle;"></a>';

    return $links;
}

// ---------------------------------------------------------------------
// --- DELETION CONFIRMATION PROMPT (Plugins screen) ---
// ---------------------------------------------------------------------

add_action('admin_enqueue_scripts', 'tk_enqueue_uninstall_confirmation_script');
function tk_enqueue_uninstall_confirmation_script($hook)
{
    if ($hook !== 'plugins.php') {
        return;
    }

    $plugin_file = plugin_basename(__FILE__);
    $message     = implode("\n", [
        'Are you sure you want to delete TK Products Sync for Multisite?',
        '',
        'The following will be permanently removed:',
        '  - The sync relationship data stored on every product across all subsites (the _tk_master_product_id meta key).',
        '',
        'The following will NOT be removed:',
        '  - The products themselves on each subsite.',
        '  - Product images that were copied to subsite upload folders.',
        '  - Categories and tags that were created on subsites.',
        '',
        'Click OK to confirm deletion, or Cancel to go back.',
    ]);

    $inline_js = sprintf(
        '(function(){var l=document.querySelector(%s);if(!l)return;l.addEventListener("click",function(e){if(!window.confirm(%s)){e.preventDefault();}});})()',
        wp_json_encode('tr[data-plugin="' . esc_attr($plugin_file) . '"] .delete a'),
        wp_json_encode($message)
    );

    wp_register_script('tk-uninstall-confirm', false, [], null, true);
    wp_enqueue_script('tk-uninstall-confirm');
    wp_add_inline_script('tk-uninstall-confirm', $inline_js);
}

// ---------------------------------------------------------------------
// --- ASYNC BACKGROUND SYNC HANDLER ---
// ---------------------------------------------------------------------

add_action('tk_async_sync_product', 'tk_handle_async_sync_product');
function tk_handle_async_sync_product($product_id)
{
    switch_to_blog(MASTER_BLOG_ID);
    $product = wc_get_product($product_id);
    restore_current_blog();

    if (!$product) {
        return;
    }

    wp_suspend_cache_invalidation(true);
    tk_crosspost_product_dynamic_full($product_id, $product);
    wp_suspend_cache_invalidation(false);
}

// ---------------------------------------------------------------------
// --- BULK ACTIONS REGISTRATION AND HANDLING ---
// ---------------------------------------------------------------------

add_filter('bulk_actions-edit-product', 'tk_register_bulk_actions', 99);
function tk_register_bulk_actions($bulk_actions)
{
    if (get_current_blog_id() == MASTER_BLOG_ID && current_user_can('edit_products')) {
        $bulk_actions['tk_sync_products'] = __('Sync to All Subsites', 'tk-products-sync-for-multisite');
        $bulk_actions['tk_delete_products'] = __('DELETE from All Subsites', 'tk-products-sync-for-multisite');
    }
    return $bulk_actions;
}

add_filter('handle_bulk_actions-edit-product', 'tk_handle_bulk_actions', 10, 3);
function tk_handle_bulk_actions($redirect_to, $action, $post_ids)
{
    if (!current_user_can('edit_products')) {
        return $redirect_to;
    }

    $count = count($post_ids);

    if ($action === 'tk_sync_products') {
        foreach ($post_ids as $product_id) {
            as_schedule_single_action(time(), 'tk_async_sync_product', array('product_id' => (int) $product_id), 'tk-sync');
        }
        $redirect_to = add_query_arg('tk_sync_products_queued', $count, $redirect_to);
        return $redirect_to;
    }

    if ($action === 'tk_delete_products') {
        wp_suspend_cache_invalidation(true);

        foreach ($post_ids as $product_id) {
            tk_delete_product_from_all_subsites($product_id);
        }

        wp_suspend_cache_invalidation(false);

        $redirect_to = add_query_arg('tk_products_deleted', $count, $redirect_to);
        return $redirect_to;
    }

    return $redirect_to;
}

add_action('admin_notices', 'tk_display_bulk_action_notices');
function tk_display_bulk_action_notices()
{
    if (!empty($_REQUEST['tk_sync_products_queued'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $count = absint($_REQUEST['tk_sync_products_queued']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $scheduler_url = esc_url(admin_url('admin.php?page=wc-status&tab=action-scheduler&s=tk-sync'));
        $message = sprintf(
            /* translators: 1: number of products, 2: URL to scheduled actions page */
            _n(
                '%1$s product queued for background sync. Monitor progress at <a href="%2$s">Scheduled Actions</a>.',
                '%1$s products queued for background sync. Monitor progress at <a href="%2$s">Scheduled Actions</a>.',
                $count,
                'tk-products-sync-for-multisite'
            ),
            $count,
            $scheduler_url
        );
        printf('<div id="message" class="updated fade"><p>%s</p></div>', wp_kses_post($message));
    }

    if (!empty($_REQUEST['tk_products_deleted'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $count = absint($_REQUEST['tk_products_deleted']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        printf(
            '<div id="message" class="error fade"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: %s: number of products deleted */
                _n(
                    '%s product successfully deleted from all subsites.',
                    '%s products successfully deleted from all subsites.',
                    $count,
                    'tk-products-sync-for-multisite'
                ),
                $count
            ))
        );
    }
}

// ---------------------------------------------------------------------
// --- TERM DELETION PROPAGATION ---
// ---------------------------------------------------------------------

add_action('delete_term', 'tk_delete_term_from_subsites', 10, 5);
function tk_delete_term_from_subsites($term_id, $tt_id, $taxonomy, $deleted_term, $object_ids)
{
    if (get_current_blog_id() !== MASTER_BLOG_ID) {
        return;
    }

    $product_taxonomies = get_object_taxonomies('product');
    if (!in_array($taxonomy, $product_taxonomies) || substr($taxonomy, 0, 3) === 'pa_') {
        return;
    }

    $target_site_ids = get_sites(array('fields' => 'ids', 'site__not_in' => array(MASTER_BLOG_ID)));

    foreach ($target_site_ids as $target_blog_id) {
        switch_to_blog($target_blog_id);

        $target_term = get_term_by('name', $deleted_term->name, $taxonomy);
        if (!$target_term) {
            $target_term = get_term_by('slug', $deleted_term->slug, $taxonomy);
        }

        if ($target_term && !is_wp_error($target_term)) {
            wp_delete_term($target_term->term_id, $taxonomy);
        }

        restore_current_blog();
    }
}

// ---------------------------------------------------------------------
// --- DELETION CORE FUNCTION ---
// ---------------------------------------------------------------------

function tk_delete_product_from_all_subsites($source_product_id)
{
    $source_blog_id = get_current_blog_id();

    $target_site_ids = get_sites(array(
        'fields' => 'ids',
        'site__not_in' => array($source_blog_id),
    ));

    if (empty($target_site_ids)) {
        return;
    }

    foreach ($target_site_ids as $target_blog_id) {
        switch_to_blog($target_blog_id);

        $existing_target_products = get_posts(array(
            'post_type' => 'product',
            'meta_key' => MASTER_PRODUCT_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $source_product_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'fields' => 'ids',
            'posts_per_page' => 1,
            'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
        ));

        if (!empty($existing_target_products)) {
            $target_product_id = $existing_target_products[0];

            $variations = get_posts(array(
                'post_type' => 'product_variation',
                'post_parent' => $target_product_id,
                'fields' => 'ids',
                'posts_per_page' => -1,
            ));

            foreach ($variations as $var_id) {
                wp_delete_post($var_id, true);
            }

            wp_delete_post($target_product_id, true);
        }

        restore_current_blog();
    }
}

// ---------------------------------------------------------------------
// --- MAIN DYNAMIC SYNC HOOK ---
// ---------------------------------------------------------------------

add_action('woocommerce_update_product', 'tk_crosspost_product_dynamic_full', 20, 2);

function tk_crosspost_product_dynamic_full($product_id, $product)
{
    $source_blog_id = get_current_blog_id();

    if ($source_blog_id !== MASTER_BLOG_ID || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }

    wp_suspend_cache_invalidation(true);

    if (!defined('TK_SYNC_SILENT')) {
        define('TK_SYNC_SILENT', true);
    }

    $target_site_ids = get_sites(array(
        'fields' => 'ids',
        'site__not_in' => array($source_blog_id),
    ));

    if (empty($target_site_ids)) {
        wp_suspend_cache_invalidation(false);
        return;
    }

    remove_action('woocommerce_update_product', 'tk_crosspost_product_dynamic_full', 20, 2);

    foreach ($target_site_ids as $target_blog_id) {
        switch_to_blog($target_blog_id);
        tk_sync_product_to_target($product_id, $product);
        restore_current_blog();
    }

    add_action('woocommerce_update_product', 'tk_crosspost_product_dynamic_full', 20, 2);

    wp_suspend_cache_invalidation(false);
}

// ---------------------------------------------------------------------
// --- CORE SYNC FUNCTIONS ---
// ---------------------------------------------------------------------

function tk_sync_product_to_target($source_product_id, $source_product)
{
    $target_product = tk_get_or_create_target_product($source_product_id, $source_product);
    if (!$target_product) {
        return;
    }

    $target_product->set_name($source_product->get_name());
    $target_product->set_description($source_product->get_description());
    $target_product->set_short_description($source_product->get_short_description());
    $target_product->set_regular_price(floatval($source_product->get_regular_price()));
    $target_product->set_sale_price(floatval($source_product->get_sale_price()));
    $target_product->set_status($source_product->get_status());
    $target_product->set_catalog_visibility($source_product->get_catalog_visibility());

    $target_product->update_meta_data(MASTER_PRODUCT_META_KEY, $source_product_id);

    // New products have no post ID yet; save now so subsequent DB calls have a valid ID.
    if (!$target_product->get_id()) {
        $target_product->save();
    }

    $target_product_id = $target_product->get_id();
    if (!$target_product_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("TK Sync Error: Could not create product on subsite for master ID $source_product_id."); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        return;
    }

    tk_sync_product_taxonomies($source_product_id, $target_product_id);
    tk_sync_product_images($source_product_id, $target_product_id);

    if ($source_product->is_type('variable')) {
        $target_product->set_attributes($source_product->get_attributes());
        $target_product->set_manage_stock('no');
        tk_sync_product_variations($source_product_id, $target_product_id);
    } else {
        $target_product->set_stock_quantity($source_product->get_stock_quantity());
        $target_product->set_manage_stock($source_product->get_manage_stock());
        $target_product->set_stock_status($source_product->get_stock_status());
    }

    tk_sync_custom_meta($source_product, $target_product);

    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($target_product_id);
    }

    $target_product->save();
}

function tk_get_or_create_target_product($source_product_id, $source_product)
{
    $existing_target_products = get_posts(array(
        'post_type' => 'product',
        'meta_key' => MASTER_PRODUCT_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        'meta_value' => $source_product_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        'fields' => 'ids',
        'posts_per_page' => 1,
        'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
    ));

    if (!empty($existing_target_products)) {
        return wc_get_product($existing_target_products[0]);
    }

    return $source_product->get_type() === 'variable'
        ? new WC_Product_Variable()
        : new WC_Product_Simple();
}

// ---------------------------------------------------------------------
// --- IMAGE SYNC FUNCTIONS ---
// ---------------------------------------------------------------------

function tk_sync_product_images($source_product_id, $target_product_id)
{
    switch_to_blog(MASTER_BLOG_ID);
    $source_thumbnail_id = get_post_thumbnail_id($source_product_id);
    restore_current_blog();

    if ($source_thumbnail_id) {
        $target_thumbnail_id = tk_sync_attachment($source_thumbnail_id, $target_product_id);
        if ($target_thumbnail_id) {
            update_post_meta($target_product_id, '_thumbnail_id', $target_thumbnail_id);
        }
    } else {
        delete_post_thumbnail($target_product_id);
        update_post_meta($target_product_id, '_thumbnail_id', '');
    }

    switch_to_blog(MASTER_BLOG_ID);
    $source_gallery_ids = get_post_meta($source_product_id, '_product_image_gallery', true);
    $source_gallery_ids = explode(',', $source_gallery_ids);
    restore_current_blog();

    $target_gallery_ids = array();

    foreach ($source_gallery_ids as $source_attachment_id) {
        if (!$source_attachment_id) {
            continue;
        }
        $target_attachment_id = tk_sync_attachment((int) $source_attachment_id, $target_product_id);
        if ($target_attachment_id) {
            $target_gallery_ids[] = $target_attachment_id;
        }
    }

    update_post_meta($target_product_id, '_product_image_gallery', implode(',', $target_gallery_ids));
}

function tk_sync_attachment($source_attachment_id, $parent_id)
{
    switch_to_blog(MASTER_BLOG_ID);
    $master_absolute_path = get_attached_file($source_attachment_id, true);
    $source_attachment_post = get_post($source_attachment_id);
    $source_upload_dir_info = wp_upload_dir();
    restore_current_blog();

    if (!$master_absolute_path || !$source_attachment_post) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("TK Sync Error: Source attachment ID $source_attachment_id not found on master site."); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        return false;
    }

    $relative_path = str_replace(trailingslashit($source_upload_dir_info['basedir']), '', $master_absolute_path);
    $target_upload_dir = wp_upload_dir();
    $target_file_path = $target_upload_dir['basedir'] . '/' . $relative_path;
    $target_file_url = $target_upload_dir['baseurl'] . '/' . $relative_path;
    $target_directory = dirname($target_file_path);

    if (!is_dir($target_directory)) {
        wp_mkdir_p($target_directory);
    }

    if (!file_exists($target_file_path)) {
        if (!is_readable($master_absolute_path)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("TK Sync Error: Cannot read source file $master_absolute_path. Check server file permissions."); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            return false;
        }

        if (!copy($master_absolute_path, $target_file_path)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("TK Sync Error: Failed to copy file from $master_absolute_path to $target_file_path. Check server file permissions."); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            return false;
        }
    }

    $attachment = array(
        'guid' => $target_file_url,
        'post_mime_type' => $source_attachment_post->post_mime_type,
        'post_title' => sanitize_title_for_query($source_attachment_post->post_title),
        'post_content' => $source_attachment_post->post_content,
        'post_status' => 'inherit',
        'post_parent' => $parent_id,
    );

    $existing_attachment_id = attachment_url_to_postid($target_file_url);

    if ($existing_attachment_id) {
        $target_attachment_id = $existing_attachment_id;
        wp_update_post(array(
            'ID' => $target_attachment_id,
            'post_parent' => $parent_id,
            'post_title' => $attachment['post_title'],
        ));
    } else {
        $target_attachment_id = wp_insert_attachment($attachment, $target_file_path, $parent_id);
    }

    if (!is_wp_error($target_attachment_id) && $target_attachment_id > 0) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attach_data = wp_generate_attachment_metadata($target_attachment_id, $target_file_path);

        if (is_wp_error($attach_data)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("TK Sync Metadata Error: Failed to generate metadata for $target_file_path. PHP Image Libraries missing/failing."); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            update_post_meta($target_attachment_id, '_wp_attached_file', $relative_path);
            return $target_attachment_id;
        }

        wp_update_attachment_metadata($target_attachment_id, $attach_data);
        update_post_meta($target_attachment_id, '_wp_attached_file', $relative_path);
        clean_post_cache($target_attachment_id);

        return $target_attachment_id;
    }

    return false;
}

// ---------------------------------------------------------------------
// --- TAXONOMY AND VARIATION SYNC FUNCTIONS ---
// ---------------------------------------------------------------------

function tk_sync_product_taxonomies($source_product_id, $target_product_id)
{
    $taxonomies = get_object_taxonomies('product');

    static $synced_term_map = array();

    foreach ($taxonomies as $taxonomy) {
        if (substr($taxonomy, 0, 3) === 'pa_') {
            continue;
        }

        $synced_term_map = array();

        switch_to_blog(MASTER_BLOG_ID);
        $source_terms = wp_get_object_terms($source_product_id, $taxonomy, array('fields' => 'all'));
        restore_current_blog();

        if (is_wp_error($source_terms) || empty($source_terms)) {
            wp_set_object_terms($target_product_id, array(), $taxonomy, false);
            continue;
        }

        $target_term_ids = array();

        usort($source_terms, function ($a, $b) {
            return $a->parent - $b->parent;
        });

        foreach ($source_terms as $source_term) {
            $target_term_id = tk_sync_single_term($source_term, $taxonomy, $synced_term_map);
            if ($target_term_id) {
                $target_term_ids[] = $target_term_id;
            }
        }

        if (!empty($target_term_ids)) {
            wp_set_object_terms($target_product_id, array_map('intval', $target_term_ids), $taxonomy, false);
        } else {
            wp_set_object_terms($target_product_id, array(), $taxonomy, false);
        }
    }
}

function tk_sync_single_term($source_term, $taxonomy, &$synced_term_map)
{
    if (isset($synced_term_map[$source_term->term_id])) {
        return $synced_term_map[$source_term->term_id];
    }

    $target_parent_id = 0;
    if ($source_term->parent > 0) {
        if (!isset($synced_term_map[$source_term->parent])) {
            switch_to_blog(MASTER_BLOG_ID);
            $source_parent_term = get_term($source_term->parent, $taxonomy);
            restore_current_blog();

            if (!is_wp_error($source_parent_term) && $source_parent_term) {
                $target_parent_id = tk_sync_single_term($source_parent_term, $taxonomy, $synced_term_map);
            }
        } else {
            $target_parent_id = $synced_term_map[$source_term->parent];
        }
    }

    $target_term_id = 0;

    $name_match = get_term_by('name', $source_term->name, $taxonomy);
    if ($name_match && $name_match->parent == $target_parent_id) {
        $target_term_id = (int) $name_match->term_id;
    } else {
        $term_data = term_exists($source_term->slug, $taxonomy, $target_parent_id);
        if (!empty($term_data) && is_array($term_data)) {
            $target_term_id = (int) $term_data['term_id'];
        }
    }

    if ($target_term_id > 0) {
        wp_update_term($target_term_id, $taxonomy, array(
            'name' => $source_term->name,
            'description' => $source_term->description,
            'parent' => $target_parent_id,
        ));
    } elseif ($target_term_id === 0) {
        $new_term = wp_insert_term(
            $source_term->name,
            $taxonomy,
            array(
                'slug' => $source_term->slug,
                'parent' => $target_parent_id,
                'description' => $source_term->description,
            )
        );

        if (!is_wp_error($new_term) && !empty($new_term['term_id'])) {
            $target_term_id = (int) $new_term['term_id'];
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('TK Sync Taxonomy Error: Failed to insert term ' . $source_term->name . ' (' . $source_term->slug . '): ' . (is_wp_error($new_term) ? $new_term->get_error_message() : 'Unknown Error')); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            return false;
        }
    }

    $synced_term_map[$source_term->term_id] = $target_term_id;

    switch_to_blog(MASTER_BLOG_ID);
    $source_thumbnail_id = get_term_meta($source_term->term_id, 'thumbnail_id', true);
    restore_current_blog();

    if ($source_thumbnail_id) {
        $target_thumbnail_id = tk_sync_attachment((int) $source_thumbnail_id, 0);
        if ($target_thumbnail_id) {
            update_term_meta($target_term_id, 'thumbnail_id', $target_thumbnail_id);
        }
    }

    return $target_term_id;
}

function tk_sync_product_variations($source_product_id, $target_product_id)
{
    switch_to_blog(MASTER_BLOG_ID);
    $source_variations = get_posts(array(
        'post_type' => 'product_variation',
        'fields' => 'ids',
        'post_parent' => $source_product_id,
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ));
    restore_current_blog();

    $existing_target_ids = array();

    foreach ($source_variations as $source_var_id) {
        switch_to_blog(MASTER_BLOG_ID);
        $source_variation = new WC_Product_Variation($source_var_id);
        restore_current_blog();

        $target_var_id = tk_get_target_variation_id($source_var_id, $target_product_id);

        if (!$target_var_id) {
            $target_var_id = wp_insert_post(array(
                'post_title' => 'Product #' . $target_product_id . ' Variation',
                'post_status' => 'publish',
                'post_parent' => $target_product_id,
                'post_type' => 'product_variation',
            ));
        }

        if (!$target_var_id) {
            continue;
        }

        $existing_target_ids[] = $target_var_id;

        $target_variation = new WC_Product_Variation($target_var_id);
        $target_variation->set_regular_price($source_variation->get_regular_price());
        $target_variation->set_sale_price($source_variation->get_sale_price());
        $target_variation->set_sku($source_variation->get_sku());
        $target_variation->set_stock_quantity($source_variation->get_stock_quantity());
        $target_variation->set_manage_stock($source_variation->get_manage_stock());
        $target_variation->set_stock_status($source_variation->get_stock_status());
        $target_variation->set_attributes($source_variation->get_variation_attributes());
        $target_variation->update_meta_data(MASTER_PRODUCT_META_KEY, $source_var_id);
        $target_variation->save();
    }

    tk_cleanup_old_variations($target_product_id, $existing_target_ids);
}

function tk_get_target_variation_id($source_var_id, $target_parent_id)
{
    $existing = get_posts(array(
        'post_type' => 'product_variation',
        'post_parent' => $target_parent_id,
        'meta_key' => MASTER_PRODUCT_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        'meta_value' => $source_var_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        'fields' => 'ids',
        'posts_per_page' => 1,
        'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
    ));
    return !empty($existing) ? $existing[0] : 0;
}

function tk_cleanup_old_variations($target_product_id, $keep_ids)
{
    $all_target_vars = get_posts(array(
        'post_type' => 'product_variation',
        'fields' => 'ids',
        'post_parent' => $target_product_id,
        'posts_per_page' => -1,
        'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
    ));

    foreach ($all_target_vars as $var_id) {
        if (!in_array($var_id, $keep_ids)) {
            wp_delete_post($var_id, true);
        }
    }
}

function tk_sync_custom_meta($source_product, $target_product)
{
    $source_product_id = $source_product->get_id();
    $target_product_id = $target_product->get_id();

    switch_to_blog(MASTER_BLOG_ID);
    $all_meta = get_post_meta($source_product_id);
    restore_current_blog();

    $meta_blacklist = array(
        '_edit_last',
        '_edit_lock',
        '_wc_average_rating',
        '_wc_review_count',
        '_wc_rating_count',
        MASTER_PRODUCT_META_KEY,
        '_product_image_gallery',
        '_thumbnail_id',
        '_stock',
        '_regular_price',
        '_sale_price',
        '_price',
        '_transient_wc_product_children',
        '_transient_timeout_wc_product_children',
    );

    foreach ($all_meta as $key => $values) {
        if (in_array($key, $meta_blacklist)) {
            continue;
        }

        delete_post_meta($target_product_id, $key);

        foreach ($values as $value) {
            add_post_meta($target_product_id, $key, maybe_unserialize($value));
        }
    }
}
