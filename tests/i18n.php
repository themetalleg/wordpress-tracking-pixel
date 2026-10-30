<?php

use Metalleg\TrackingPixel\Admin;
use Metalleg\TrackingPixel\Plugin;
use Metalleg\TrackingPixel\Store;

if (!defined('ABSPATH') || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Run this test only inside a disposable local WordPress installation.');
}

require_once ABSPATH . WPINC . '/pomo/po.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$directory = dirname(__DIR__) . '/languages/';
$template = new PO();
$german = new PO();
$compiled = new MO();
$check($template->import_from_file($directory . 'wordpress-tracking-pixel.pot'), 'Import translator template.');
$check($german->import_from_file($directory . 'wordpress-tracking-pixel-de_DE.po'), 'Import German source catalog.');
$check($compiled->import_from_file($directory . 'wordpress-tracking-pixel-de_DE.mo'), 'Import compiled German catalog.');
foreach ($template->entries as $key => $entry) {
    $translation = $german->entries[$key]->translations[0] ?? '';
    $check($translation !== '', 'Missing German translation: ' . $entry->singular);
    $check($compiled->translate($entry->singular) === $translation, 'Compiled catalog differs from PO: ' . $entry->singular);
    preg_match_all('/%(?:[0-9]+\$)?[sdf]/', $entry->singular, $sourcePlaceholders);
    preg_match_all('/%(?:[0-9]+\$)?[sdf]/', $translation, $translatedPlaceholders);
    sort($sourcePlaceholders[0]);
    sort($translatedPlaceholders[0]);
    $check($sourcePlaceholders[0] === $translatedPlaceholders[0], 'Preserve format placeholders: ' . $entry->singular);
    preg_match_all('/<code>.*?<\/code>/', $entry->singular, $sourceCode);
    preg_match_all('/<code>.*?<\/code>/', $translation, $translatedCode);
    $check($sourceCode[0] === $translatedCode[0], 'Preserve machine-readable examples: ' . $entry->singular);
}

$switched = switch_to_locale('de_DE');
try {
    $check($switched, 'Install the German WordPress language pack before running this test.');
    $check(__('Projects', 'wordpress-tracking-pixel') === 'Projekte', 'WordPress loads bundled German catalog.');
    $check(__('API Keys', 'wordpress-tracking-pixel') === 'API-Keys', 'German uses API-Keys terminology.');
    $check(__('API key', 'wordpress-tracking-pixel') === 'API-Key', 'German singular uses API-Key terminology.');
    $check(__('Create Project', 'wordpress-tracking-pixel') === 'Projekt erstellen', 'German command label.');
    $check(__('3. Register an Email', 'wordpress-tracking-pixel') === '3. E-Mail registrieren', 'German tutorial heading.');
    $previousUser = get_current_user_id();
    $administrators = get_users(['role' => 'administrator', 'number' => 1]);
    $check(count($administrators) === 1, 'Test site has an administrator.');
    wp_set_current_user($administrators[0]->ID);
    ob_start();
    try {
        (new Admin(new Store()))->tutorial();
        $tutorial = ob_get_contents();
    } finally {
        ob_end_clean();
        wp_set_current_user($previousUser);
    }
    $check(str_contains($tutorial, 'Einrichtung / Anleitung'), 'German tutorial renders.');
    $check(str_contains($tutorial, 'Datenschutz und Updates'), 'German privacy section renders.');
    $check(str_contains($tutorial, 'external_id') && str_contains($tutorial, 'Authorization: Bearer YOUR_API_KEY'), 'API examples stay unchanged.');
} finally {
    if ($switched) {
        restore_previous_locale();
    }
}

$english = switch_to_locale('en_US');
try {
    $check(__('Projects', 'wordpress-tracking-pixel') === 'Projects', 'English fallback after locale switching.');
} finally {
    if ($english) {
        restore_previous_locale();
    }
}

foreach (['de_DE_formal', 'de_AT', 'de_CH', 'de_CH_informal', 'de_LU', 'de', 'fr_FR', 'en_US'] as $locale) {
    $forceLocale = static fn (): string => $locale;
    add_filter('pre_determine_locale', $forceLocale);
    unload_textdomain('wordpress-tracking-pixel', true);
    try {
        $expected = $locale === 'de' || str_starts_with($locale, 'de_') ? 'Projekte' : 'Projects';
        $check(__('Projects', 'wordpress-tracking-pixel') === $expected, 'Runtime German fallback or English source for ' . $locale);
        $check(determine_locale() === $locale, 'Plugin fallback does not change the WordPress locale.');
        $check(Plugin::translationFile('/missing/other-domain.mo', 'other-domain') === '/missing/other-domain.mo', 'Other plugins are not affected.');
        $check(Plugin::translationFile($directory . 'wordpress-tracking-pixel-de_DE.mo', 'wordpress-tracking-pixel') === $directory . 'wordpress-tracking-pixel-de_DE.mo', 'Existing exact translation files take priority.');
    } finally {
        remove_filter('pre_determine_locale', $forceLocale);
        unload_textdomain('wordpress-tracking-pixel', true);
    }
}
echo "Passed {$checks} translation checks.\n";