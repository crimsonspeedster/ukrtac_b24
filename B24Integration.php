<?php
/*
Plugin Name: B24Integration
Plugin URI: #
Description: Integration B24 with Woocommerce
Version: 1.0
Requires at least: 5.4
Requires PHP: 7.4
Author: Crimson Speedster
Author URI: https://t.me/crimson_speedster
License: GPLv2 or later
Text Domain: b24integration
*/

define('B24__PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once(B24__PLUGIN_DIR.'class.integration.php');

use App\Bitrix\B24Integration;
new B24Integration();