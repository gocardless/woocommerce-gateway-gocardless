<?php
/**
 * The bootstrap file for PHPUnit tests for the WooCommerce Gateway GoCardless plugin.
 * Starts up WP_Mock and requires the files needed for testing.
 */

define( 'TEST_PLUGIN_DIR', dirname( dirname(__DIR__) ) . '/' );

// First we need to load the composer autoloader so we can use WP Mock
require_once TEST_PLUGIN_DIR . '/vendor/autoload.php';

// Now call the bootstrap method of WP Mock.
WP_Mock::bootstrap();

require_once __DIR__ . '/../../includes/class-wc-gocardless-compat.php';
require_once __DIR__ . '/../../includes/class-wc-gocardless-order-admin.php';
