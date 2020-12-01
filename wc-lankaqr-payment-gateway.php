<?php
/**
 * Plugin Name: Payment Gateway for LANKAQR on WooCommerce
 * Plugin URI: https://wordpress.org/plugins/wc-lankaqr-payment-gateway/
 * Description: Accept payments through LANKAQR system which enables Quick Response (QR) code-based payments.
 * Version: 1.2.4
 * Author: Maduka Jayalath
 * Text Domain: wc-lankaqr-payment-gateway
 * Domain Path: /languages
 * WC requires at least: 3.1
 * WC tested up to: 4.4
 *
 *
 * @category WooCommerce
 * @package  Payment Gateway for LANKAQR on WooCommerce
 * @author   Maduka Jayalath <madu.rapa@gmail.com>
 * @license  http://www.gnu.org/licenses/gpl-3.0.html
 * @link     https://wordpress.org/plugins/wc-lankaqr-payment-gateway/
 * GitHub Plugin URI: https://github.com/madurapa/wc-lankaqr-payment-gateway/
 *
 **/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

$consts = array(
    'LANKAQR_VERSION' => '1.2.4', // plugin version
    'LANKAQR_BASENAME' => plugin_basename(__FILE__),
    'LANKAQR_DIR' => plugin_dir_url(__FILE__)
);

foreach ($consts as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

// Internationalization
add_action('plugins_loaded', 'lankaqrwc_plugin_load_textdomain');

/**
 * Load plugin textdomain.
 *
 * @since 1.0.0
 */
function lankaqrwc_plugin_load_textdomain()
{
    load_plugin_textdomain('wc-lankaqr-payment-gateway', false, dirname(LANKAQR_BASENAME) . '/languages/');
}

// register activation hook
register_activation_hook(__FILE__, 'lankaqrwc_plugin_activation');

function lankaqrwc_plugin_activation()
{
    if (!current_user_can('activate_plugins')) {
        return;
    }
    set_transient('lankaqrwc-admin-notice-on-activation', true, 5);
}

// register deactivation hook
register_deactivation_hook(__FILE__, 'lankaqrwc_plugin_deactivation');

function lankaqrwc_plugin_deactivation()
{
    if (!current_user_can('activate_plugins')) {
        return;
    }
    delete_option('lankaqrwc_plugin_dismiss_rating_notice');
    delete_option('lankaqrwc_plugin_no_thanks_rating_notice');
    delete_option('lankaqrwc_plugin_installed_time');
}

// plugin action links
add_filter('plugin_action_links_' . LANKAQR_BASENAME, 'lankaqrwc_add_action_links', 10, 2);

function lankaqrwc_add_action_links($links)
{
    $lankaqrwclinks = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=wc-mj-lankaqr') . '">' . __('Settings', 'wc-lankaqr-payment-gateway') . '</a>',
    );
    return array_merge($lankaqrwclinks, $links);
}

// plugin row elements
add_filter('plugin_row_meta', 'lankaqrwc_plugin_meta_links', 10, 2);

function lankaqrwc_plugin_meta_links($links, $file)
{
    $plugin = LANKAQR_BASENAME;
    if ($file == $plugin) // only for this plugin
        return array_merge($links,
            array('<a href="https://wordpress.org/support/plugin/wc-lankaqr-payment-gateway/" target="_blank">' . __('Support', 'wc-lankaqr-payment-gateway') . '</a>'),
            array('<a href="https://wordpress.org/plugins/wc-lankaqr-payment-gateway/#faq" target="_blank">' . __('FAQ', 'wc-lankaqr-payment-gateway') . '</a>')
        );
    return $links;
}

// add admin notices
add_action('admin_notices', 'lankaqrwc_new_plugin_install_notice');

function lankaqrwc_new_plugin_install_notice()
{
    // Show a warning to sites running PHP < 5.6
    if (version_compare(PHP_VERSION, '5.6', '<')) {
        echo '<div class="error"><p>' . __('Your version of PHP is below the minimum version of PHP required by Payment Gateway for LANKAQR on WooCommerce plugin. Please contact your host and request that your version be upgraded to 5.6 or later.', 'wc-lankaqr-payment-gateway') . '</p></div>';
    }

    // Check transient, if available display notice
    if (get_transient('lankaqrwc-admin-notice-on-activation')) { ?>
        <div class="notice notice-success">
            <p>
                <strong><?php printf(__('Thanks for installing %1$s v%2$s plugin. Click <a href="%3$s">here</a> to configure plugin settings.', 'wc-lankaqr-payment-gateway'), 'Payment Gateway for LANKAQR on WooCommerce', LANKAQR_VERSION, admin_url('admin.php?page=wc-settings&tab=checkout&section=wc-mj-lankaqr')); ?></strong>
            </p>
        </div> <?php
        delete_transient('lankaqrwc-admin-notice-on-activation');
    }
}

require_once plugin_dir_path(__FILE__) . 'includes/payment.php';
require_once plugin_dir_path(__FILE__) . 'includes/notice.php';
