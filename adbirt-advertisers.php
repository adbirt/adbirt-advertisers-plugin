<?php
/*
 * Adbirt Advertisers
 * @package           adbirt-advertisers
 * @author            Adbirt.com
 * @copyright         2017 - 2021 Adbirt, Inc. All Rights Reserved
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Adbirt Advertisers
 * Plugin URI: https://adbirt.com/wordpress-plugins
 * description: Adbirt conversion tracking: keeps the Adbirt click id and reports sign-ups, orders and other conversions to Adbirt. See https://adbirt.com/privacy & https://adbirt.com/terms.
 * Version: 2.0.0
 * Tags: Adbirt, Advertisment, CPC, CPA, CPM
 * Requires at least: 5.0.0
 * Tested up to: 6.6
 * Requires PHP: 7.0
 * Plugin Slug: adbirt-advertisers
 * Stable tag: 2.0.0
 * Text Domain: adbirt-advertisers
 * Author: Adbirt.com
 * Author URI: https://adbirt.com/contact
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */


/**
 * @package adbirt-advertisers
 */


if (!defined('ABSPATH')) {
    exit;
}

$main_class_path = trailingslashit(plugin_dir_path(__FILE__)) . 'class-AdbirtAdvertisers.php';

require($main_class_path);

new AdbirtAdvertisers();

// completed
