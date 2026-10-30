<?php

declare(strict_types=1);

namespace Metalleg\TrackingPixel;

final class Plugin
{
    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) {
            wp_die(__('Network activation is not supported. Activate separately on each site.', 'wordpress-tracking-pixel'));
        }
        (new Store())->install();
        self::schedule();
    }

    private static function schedule(): void
    {
        if (!wp_next_scheduled('wtp_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'wtp_cleanup');
        }
    }

    public static function boot(): void
    {
        add_filter('load_textdomain_mofile', [self::class, 'translationFile'], 10, 2);
        add_action('init', static function (): void {
            load_plugin_textdomain('wordpress-tracking-pixel', false, dirname(plugin_basename(WTP_FILE)) . '/languages');
        });
        $store = new Store();
        if (get_option('wtp_schema') !== WTP_VERSION) {
            $store->install();
        }
        self::schedule();
        add_action('wtp_cleanup', [$store, 'cleanup']);
        add_action('rest_api_init', static function () use ($store): void {
            (new Api($store))->routes();
        });
        add_action('template_redirect', [self::class, 'pixel'], -100);
        if (is_admin()) {
            (new Admin($store))->hooks();
        }
        if (class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class)) {
            $updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                'https://github.com/themetalleg/wordpress-tracking-pixel/', WTP_FILE, 'wordpress-tracking-pixel'
            );
            $updater->getVcsApi()->enableReleaseAssets('/^wordpress-tracking-pixel\.zip$/');
        }
    }

    public static function translationFile(string $file, string $domain): string
    {
        if ($domain !== 'wordpress-tracking-pixel' || is_readable($file)
            || (str_ends_with($file, '.mo') && is_readable(substr($file, 0, -3) . '.l10n.php'))) {
            return $file;
        }
        $locale = determine_locale();
        if ($locale !== 'de' && !str_starts_with($locale, 'de_')) {
            return $file;
        }
        $fallback = dirname(WTP_FILE) . '/languages/wordpress-tracking-pixel-de_DE.mo';
        return is_readable($fallback) ? $fallback : $file;
    }

    public static function pixel(): void
    {
        if (!isset($_GET['wtp_pixel'])) {
            return;
        }
        $value = is_string($_GET['wtp_pixel']) ? wp_unslash($_GET['wtp_pixel']) : '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
            && preg_match('/\A([A-Za-z0-9]{10})\.([A-Za-z0-9]{64})\z/D', $value, $parts)) {
            (new Store())->record($parts[1], $parts[2]);
        }
        status_header(200);
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Content-Type: image/gif');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        $image = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        header('Content-Length: ' . strlen($image));
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
            echo $image;
        }
        exit;
    }

    public static function tracking(object $message): array
    {
        $url = add_query_arg('wtp_pixel', $message->project_id . '.' . $message->token, home_url('/'));
        return [
            'project_id' => $message->project_id,
            'external_id' => $message->external_id,
            'tracking_url' => $url,
            'html' => '<img src="' . esc_url($url) . '" width="1" height="1" alt="" style="border:0" />',
        ];
    }
}