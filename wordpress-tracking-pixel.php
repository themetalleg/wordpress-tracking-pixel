<?php
/**
 * Plugin Name: WordPress Tracking Pixel
 * Description: Project-scoped tracking pixels for external emails, with a private reporting API.
 * Version: 0.2.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Author: themetalleg
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/themetalleg/wordpress-tracking-pixel
 * Text Domain: wordpress-tracking-pixel
 * Domain Path: /languages
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('WTP_VERSION', '0.2.0');
define('WTP_FILE', __FILE__);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Metalleg\\TrackingPixel\\';
        if (str_starts_with($class, $prefix)) {
            $path = __DIR__ . '/includes/' . substr($class, strlen($prefix)) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
    });
}

register_activation_hook(__FILE__, [Metalleg\TrackingPixel\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('wtp_cleanup');
});
add_action('plugins_loaded', [Metalleg\TrackingPixel\Plugin::class, 'boot']);