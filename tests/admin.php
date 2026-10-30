<?php

use Metalleg\TrackingPixel\Admin;
use Metalleg\TrackingPixel\Identifiers;
use Metalleg\TrackingPixel\Store;

if (!defined('ABSPATH') || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Run this test only inside a disposable local WordPress installation.');
}

$store = new Store();
$fixtures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$previousUser = get_current_user_id();
$previousQuery = $_GET;
$previousSettings = [];
foreach (['date_format', 'time_format', 'timezone_string', 'gmt_offset'] as $setting) {
    $previousSettings[$setting] = get_option($setting);
}
$prefix = 'Admin Test ' . Identifiers::random(16);
$render = static function (array $query) use ($store): string {
    $_GET = $query;
    ob_start();
    try {
        (new Admin($store))->page();
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
};

try {
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    $check(count($admins) === 1, 'An administrator exists.');
    wp_set_current_user($admins[0]->ID);
    for ($index = 0; $index < 23; $index++) {
        $fixtures[] = $store->createProject($prefix . ($index === 0 ? ' %_ literal' : ' ' . $index));
    }
    $first = $store->listProjects($prefix);
    $second = $store->listProjects($prefix, 2);
    $check($first['total'] === 23 && count($first['items']) === 20, 'Overview limits a page to 20 projects.');
    $check($second['total'] === 23 && count($second['items']) === 3, 'Second overview page contains remaining projects.');
    $check(array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')) === [], 'Project pages do not overlap.');
    $check($store->listProjects($prefix, 999)['page'] === 2, 'Clamp out-of-range project page.');
    $check($store->listProjects($prefix . ' %_')['total'] === 1, 'Treat search wildcards literally.');
    $check($store->listProjects($fixtures[0])['total'] === 1, 'Search by exact project ID.');
    $check($store->listProjects($prefix . ' missing')['total'] === 0, 'Unknown search has no results.');
    $store->createKey($fixtures[0], 'Admin test', 'read');
    $store->register($fixtures[0], null);
    $counted = $store->listProjects($fixtures[0])['items'][0];
    $check((int) $counted->key_count === 1 && (int) $counted->message_count === 1, 'Overview shows project-specific key/message counts.');

    $overview = $render(['page' => 'wtp', 's' => $prefix]);
    $check(str_contains($overview, 'wtp-project-list'), 'Overview renders the project table.');
    $check(!str_contains($overview, 'data-wtp-action="retention"'), 'Overview does not render settings for every project.');
    $check(!str_contains($overview, 'data-wtp-action="key"'), 'Overview does not render key forms for every project.');
    $check(substr_count($overview, '<tr>') === 21, 'Overview renders 20 data rows and one header.');
    $check(str_contains($overview, 'project_page=2'), 'Overview includes a next-page link.');
    $empty = $render(['page' => 'wtp', 's' => $prefix . ' missing']);
    $check(str_contains($empty, __('No projects match your search.', 'wordpress-tracking-pixel')), 'Search empty state is explicit.');
    $settings = $render(['page' => 'wtp', 'project' => $fixtures[0]]);
    $check(substr_count($settings, 'data-wtp-action="retention"') === 1, 'Settings renders only the selected project.');
    $check(str_contains($settings, 'project=' . $fixtures[0]), 'Settings actions preserve the selected project.');
    $check(!str_contains($settings, 'wtp-report'), 'Settings does not render the event report.');
    $events = $render(['page' => 'wtp', 'project' => $fixtures[0], 'tab' => 'events']);
    $check(str_contains($events, 'wtp-report') && !str_contains($events, 'data-wtp-action="key"'), 'Events is a separate project tab.');
    $check(str_contains($events, 'name="project" value="' . $fixtures[0] . '"') && str_contains($events, 'name="tab" value="events"'), 'Event filters retain project and tab.');
    $legacy = $render(['page' => 'wtp', 'report_project' => $fixtures[0]]);
    $check(str_contains($legacy, 'wtp-report'), 'Existing report links remain supported.');
    $invalid = $render(['page' => 'wtp', 'project' => 'invalid']);
    $check(str_contains($invalid, __('Project not found.', 'wordpress-tracking-pixel')) && str_contains($invalid, 'wtp-project-list'), 'Unknown project falls back to overview with a notice.');
    update_option('date_format', 'd.m.Y');
    update_option('time_format', 'H:i');
    update_option('timezone_string', 'Europe/Zurich');
    $emailId = Identifiers::random(128);
    $message = $store->register($fixtures[0], $emailId);
    $store->record($fixtures[0], $message->token);
    global $wpdb;
    $wpdb->update($store->table('events'), ['requested_at' => '2026-01-15 12:34:56'], ['message_id' => $message->id]);
    $datedQuery = ['page' => 'wtp', 'project' => $fixtures[0], 'tab' => 'events', 'external_id' => $emailId];
    $dated = $render($datedQuery);
    $check(str_contains($dated, '<time datetime="2026-01-15T12:34:56Z">15.01.2026 13:34</time>'), 'Admin dates use WordPress formats and site timezone.');
    $check(substr_count($dated, '15.01.2026 13:34') === 3, 'First/latest summaries and table share the site format.');
    $check(str_contains($dated, 'Europe/Zurich') && str_contains($dated, 'type="datetime-local"'), 'Date filters identify the site timezone and use native pickers.');
    $check($store->report($fixtures[0], ['external_id' => $emailId])['first_request'] === '2026-01-15T12:34:56Z', 'Storage/reporting timestamps remain ISO UTC.');
    $local = $render($datedQuery + ['from' => '2026-01-15T13:34:56', 'to' => '2026-01-15T13:34:56']);
    $check(str_contains($local, '<time datetime="2026-01-15T12:34:56Z">'), 'Local filter boundaries convert to UTC inclusively.');
    $legacyDate = $render($datedQuery + ['from' => '2026-01-15T12:34:56Z']);
    $check(str_contains($legacyDate, 'name="from" value="2026-01-15T13:34:56"'), 'Old UTC filters are displayed as site-local picker values.');
    $outside = $render($datedQuery + ['from' => '2026-01-15T13:35']);
    $check(!str_contains($outside, '<time datetime="2026-01-15T12:34:56Z">'), 'Minute-precision local filters exclude earlier events.');
    $gap = $render($datedQuery + ['from' => '2026-03-29T02:30']);
    $check(str_contains($gap, 'role="alert"'), 'Reject nonexistent local times during the daylight-saving transition.');
    update_option('date_format', 'm/d/Y');
    update_option('time_format', 'g:i a');
    update_option('timezone_string', 'America/New_York');
    $american = $render($datedQuery);
    $check(str_contains($american, '>01/15/2026 7:34 am</time>'), 'Different WordPress format and timezone apply without code changes.');
    update_option('timezone_string', '');
    update_option('gmt_offset', 5.5);
    update_option('time_format', 'H:i');
    $offset = $render($datedQuery);
    $check(str_contains($offset, '>01/15/2026 18:04</time>'), 'Fractional fixed-offset WordPress timezones are supported.');
    $pageId = Identifiers::random(128);
    $pageMessage = $store->register($fixtures[0], $pageId);
    for ($event = 0; $event < 53; $event++) {
        $store->record($fixtures[0], $pageMessage->token);
    }
    $pageQuery = ['page' => 'wtp', 'project' => $fixtures[0], 'tab' => 'events', 'external_id' => $pageId];
    $defaultPage = $render($pageQuery);
    $check(substr_count($defaultPage, '<time datetime=') === 50, 'Event table defaults to 50 records per page.');
    $middlePage = $render($pageQuery + ['event_per_page' => 25, 'event_page' => 2]);
    $check(substr_count($middlePage, '<time datetime=') === 25, 'Event table respects the selected page size.');
    $check(substr_count($middlePage, 'wtp-event-pagination') === 2, 'Event paging controls appear above and below the table.');
    $check(str_contains($middlePage, sprintf(__('%1$s-%2$s of %3$s events', 'wordpress-tracking-pixel'), '26', '50', '53')), 'Event paging shows the visible range and total.');
    $check(str_contains($middlePage, 'external_id=' . $pageId) && str_contains($middlePage, 'event_per_page=25') && str_contains($middlePage, 'event_page=3'), 'Paging links preserve filters, page size, and navigation targets.');
    $lastPage = $render($pageQuery + ['event_per_page' => 25, 'event_page' => 999]);
    $check(substr_count($lastPage, '<time datetime=') === 3 && str_contains($lastPage, sprintf(__('Page %1$d of %2$d', 'wordpress-tracking-pixel'), 3, 3)), 'Out-of-range event pages clamp to the last available page.');
    $invalidSize = $render($pageQuery + ['event_per_page' => 100000]);
    $check(substr_count($invalidSize, '<time datetime=') === 50, 'Unsupported event page sizes fall back to the bounded default.');
    $check(!str_contains($middlePage, 'name="event_page"'), 'Changing filters or page size resets pagination to page one.');
    echo "Passed {$checks} admin overview checks.\n";
} finally {
    foreach ($fixtures as $fixture) {
        $store->deleteProject($fixture);
    }
    $_GET = $previousQuery;
    wp_set_current_user($previousUser);
    foreach ($previousSettings as $setting => $value) {
        update_option($setting, $value);
    }
}