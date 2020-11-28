<?php

/**
 * The admin-facing functionality of the plugin.
 *
 * @package    Payment Gateway for LANKAQR on WooCommerce
 * @subpackage Includes
 * @author     Maduka Jayalath
 */

add_action('admin_notices', 'lankaqrwc_rating_admin_notice');
add_action('admin_init', 'lankaqrwc_dismiss_rating_admin_notice');

function lankaqrwc_rating_admin_notice()
{
    // Show notice after 240 hours (10 days) from installed time.
    if (lankaqrwc_plugin_get_installed_time() > strtotime('-240 hours')
        || '1' === get_option('lankaqrwc_plugin_dismiss_rating_notice')
        || !current_user_can('manage_options')
        || apply_filters('lankaqrwc_plugin_hide_sticky_notice', false)) {
        return;
    }

    $dismiss = wp_nonce_url(add_query_arg('lankaqrwc_rating_notice_action', 'dismiss_rating_true'), 'lankaqrwc_dismiss_rating_true');
    $no_thanks = wp_nonce_url(add_query_arg('lankaqrwc_rating_notice_action', 'no_thanks_rating_true'), 'lankaqrwc_no_thanks_rating_true'); ?>

    <div class="notice notice-success">
        <p><?php _e('Hey, we noticed you\'ve been using Payment Gateway for LANKAQR on WooCommerce for more than 2 week – that’s awesome! Could you please do me a BIG favor and give it a <strong>5-star</strong> rating on WordPress? Just to help me spread the word and boost my motivation.', 'wc-lankaqr-payment-gateway'); ?></p>
        <p>
            <a href="https://wordpress.org/support/plugin/wc-lankaqr-payment-gateway/reviews/?filter=5#new-post"
               target="_blank"
               class="button button-secondary"><?php _e('Ok, you deserve it', 'wc-lankaqr-payment-gateway'); ?></a>&nbsp;
            <a href="<?php echo $dismiss; ?>"
               class="already-did"><strong><?php _e('I already did', 'wc-lankaqr-payment-gateway'); ?></strong></a>&nbsp;<strong>|</strong>
            <a href="<?php echo $no_thanks; ?>"
               class="later"><strong><?php _e('Nope&#44; maybe later', 'wc-lankaqr-payment-gateway'); ?></strong></a>
    </div>
    <?php
}

function lankaqrwc_dismiss_rating_admin_notice()
{
    if (get_option('lankaqrwc_plugin_no_thanks_rating_notice') === '1') {
        if (get_option('lankaqrwc_plugin_dismissed_time') > strtotime('-360 hours')) {
            return;
        }
        delete_option('lankaqrwc_plugin_dismiss_rating_notice');
        delete_option('lankaqrwc_plugin_no_thanks_rating_notice');
    }

    if (!isset($_GET['lankaqrwc_rating_notice_action'])) {
        return;
    }

    if ('dismiss_rating_true' === $_GET['lankaqrwc_rating_notice_action']) {
        check_admin_referer('lankaqrwc_dismiss_rating_true');
        update_option('lankaqrwc_plugin_dismiss_rating_notice', '1');
    }

    if ('no_thanks_rating_true' === $_GET['lankaqrwc_rating_notice_action']) {
        check_admin_referer('lankaqrwc_no_thanks_rating_true');
        update_option('lankaqrwc_plugin_no_thanks_rating_notice', '1');
        update_option('lankaqrwc_plugin_dismiss_rating_notice', '1');
        update_option('lankaqrwc_plugin_dismissed_time', time());
    }

    wp_redirect(remove_query_arg('lankaqrwc_rating_notice_action'));
    exit;
}

function lankaqrwc_plugin_get_installed_time()
{
    $installed_time = get_option('lankaqrwc_plugin_installed_time');
    if (!$installed_time) {
        $installed_time = time();
        update_option('lankaqrwc_plugin_installed_time', $installed_time);
    }
    return $installed_time;
}