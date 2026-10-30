<?php

use Metalleg\TrackingPixel\Identifiers;
use Metalleg\TrackingPixel\Plugin;
use Metalleg\TrackingPixel\Store;

if (!defined('ABSPATH') || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Run this test only inside a disposable local WordPress installation.');
}

$store = new Store();
$checks = 0;
$fixtures = [];
$httpUserId = 0;
$httpProjectName = null;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$request = static function (string $project, string $resource, string $key, string $method = 'GET', mixed $body = null, array $query = []): WP_REST_Response {
    $request = new WP_REST_Request($method, '/tracking-pixel/v1/projects/' . $project . '/' . $resource);
    if ($key !== '') {
        $request->set_header('Authorization', 'Bearer ' . $key);
    }
    $request->set_query_params($query);
    if ($method === 'POST') {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }
    return rest_do_request($request);
};
$pixel = static function (string $url, string $method = 'GET'): array {
    $response = wp_remote_request('http://wordpress/?' . (parse_url($url, PHP_URL_QUERY) ?? ''), ['method' => $method, 'timeout' => 15]);
    if (is_wp_error($response)) {
        throw new RuntimeException($response->get_error_message());
    }
    return $response;
};

try {
    $project = $store->createProject('Integration primary');
    $fixtures[] = $project;
    $other = $store->createProject('Integration other');
    $fixtures[] = $other;
    $key = $store->createKey($project, 'Writer', 'write');
    $read = $store->createKey($project, 'Reader', 'read');
    $otherKey = $store->createKey($other, 'Other writer', 'write');
    $check(Identifiers::validProject($project), 'Project ID format.');
    $check((int) $store->project($project)->retention === 0, 'Indefinite by default.');
    $check($request($project, 'events', '')->get_status() === 401, 'No anonymous reporting.');
    $check($request($other, 'events', $key)->get_status() === 401, 'Cross-project reads denied.');
    $check($request($other, 'messages', $key, 'POST', new stdClass())->get_status() === 401, 'Cross-project writes denied.');
    $check($request($project, 'messages', $read, 'POST', new stdClass())->get_status() === 401, 'Read-only key cannot write.');

    $registered = $request($project, 'messages', $key, 'POST', new stdClass());
    $check($registered->get_status() === 200, 'Register without external ID.');
    $message = $registered->get_data();
    $check(Identifiers::validExternal($message['external_id']), 'Generated external ID format.');
    $check(!str_contains($message['tracking_url'], $key), 'No credential in tracking URL.');
    $check(str_contains($message['html'], 'width="1" height="1"'), 'Return HTML image tag.');
    $repeated = $request($project, 'messages', $key, 'POST', ['external_id' => $message['external_id']]);
    $check($repeated->get_data() === $message, 'Re-registering supplied ID is idempotent.');
    $generatedAgain = $request($project, 'messages', $key, 'POST', new stdClass())->get_data();
    $check($generatedAgain['external_id'] !== $message['external_id'], 'Omission creates a new message.');
    foreach (['', null, 'email-123', str_repeat('A', 127), str_repeat('A', 129), [], str_repeat('A', 128) . "\n"] as $invalid) {
        $check($request($project, 'messages', $key, 'POST', ['external_id' => $invalid])->get_status() === 400, 'Reject supplied invalid ID.');
    }
    $check($request($project, 'messages', $key, 'POST', [])->get_status() === 400, 'Reject JSON arrays.');
    $upper = str_repeat('A', 128);
    $lower = str_repeat('a', 128);
    $upperMessage = $request($project, 'messages', $key, 'POST', ['external_id' => $upper])->get_data();
    $lowerMessage = $request($project, 'messages', $key, 'POST', ['external_id' => $lower])->get_data();
    $check($upperMessage['tracking_url'] !== $lowerMessage['tracking_url'], 'External ID comparison is case-sensitive.');
    $otherMessage = $request($other, 'messages', $otherKey, 'POST', ['external_id' => $upper])->get_data();
    $check($otherMessage['tracking_url'] !== $upperMessage['tracking_url'], 'Same external ID allowed in different projects.');
    $check(!$store->authorize($project, strtolower($key), true), 'Credentials are case-sensitive.');

    $image = $pixel($message['tracking_url']);
    $check(wp_remote_retrieve_response_code($image) === 200, 'Pixel returns 200.');
    $check(wp_remote_retrieve_header($image, 'content-type') === 'image/gif', 'Pixel has image content type.');
    $check(str_contains(wp_remote_retrieve_header($image, 'cache-control'), 'no-store'), 'Pixel prevents caching.');
    $check(substr(wp_remote_retrieve_body($image), 0, 6) === 'GIF89a', 'Pixel returns a real GIF, not REST JSON.');
    $size = getimagesizefromstring(wp_remote_retrieve_body($image));
    $check($size[0] === 1 && $size[1] === 1, 'Image dimensions are 1x1.');
    $pixel($message['tracking_url']);
    $pixel($message['tracking_url'], 'HEAD');
    $report = $request($project, 'events', $read)->get_data();
    $check($report['count'] === 2, 'Record repeated GETs, not HEAD.');
    $check(count($report['events']) === 2, 'Return separate events.');
    $check(str_ends_with($report['events'][0]['requested_at'], 'Z'), 'Event timestamps are UTC.');
    $check($report['events'][0]['id'] > $report['events'][1]['id'], 'Deterministic newest-first ordering.');
    $check($request($other, 'summary', $otherKey)->get_data()['count'] === 0, 'Reports isolate events by project.');
    $summary = $request($project, 'summary', $read, 'GET', null, ['external_id' => $message['external_id']])->get_data();
    $check($summary['count'] === 2 && !isset($summary['events']), 'Summary without event list.');
    $empty = $request($project, 'summary', $read, 'GET', null, ['external_id' => str_repeat('Z', 128)])->get_data();
    $check($empty['count'] === 0 && $empty['first_request'] === null, 'Missing message returns empty summary.');
    $paged = $request($project, 'events', $read, 'GET', null, ['per_page' => 1, 'page' => 2]);
    $check(count($paged->get_data()['events']) === 1 && $paged->get_headers()['X-WP-TotalPages'] === '2', 'Bounded pagination with totals.');
    foreach ([['per_page' => 101], ['page' => 0], ['page' => []], ['from' => '2025-02-29T00:00:00Z'], ['from' => '2026-12-01T00:00:00Z', 'to' => '2026-01-01T00:00:00Z']] as $query) {
        $check($request($project, 'events', $read, 'GET', null, $query)->get_status() === 400, 'Invalid report filters rejected.');
    }
    $invalidImage = $pixel(home_url('/?wtp_pixel=' . $project . '.' . str_repeat('0', 64)));
    $check(wp_remote_retrieve_body($invalidImage) === wp_remote_retrieve_body($image), 'Unknown tokens return same image.');
    $check($store->report($project)['count'] === 2, 'Unknown token adds no event.');

    global $wpdb;
    $stored = $store->message($project, $message['external_id']);
    $old = gmdate('Y-m-d H:i:s', time() - 2 * 365 * DAY_IN_SECONDS);
    $wpdb->update($store->table('messages'), ['created_at' => $old], ['id' => $stored->id]);
    $wpdb->update($store->table('events'), ['requested_at' => $old], ['message_id' => $stored->id]);
    $check($store->report($project)['count'] === 2, 'Indefinite retains years-old events.');
    $pixel($message['tracking_url']);
    $check($store->report($project)['count'] === 3, 'Years-old message still records new request.');
    $store->retention($project, 90);
    $check($store->report($project)['count'] === 1, 'Rolling filter excludes expired events before cleanup.');
    $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$store->table('events')} WHERE message_id = %d", $stored->id)) === 3, 'Expired events still exist before scheduled cleanup.');
    $pixel($otherMessage['tracking_url']);
    $otherStored = $store->message($other, $upper);
    $wpdb->update($store->table('events'), ['requested_at' => $old], ['message_id' => $otherStored->id]);
    do_action('wtp_cleanup');
    $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$store->table('events')} WHERE message_id = %d", $stored->id)) === 1, 'Cleanup physically deletes expired rolling events.');
    $check($store->report($other)['count'] === 1, 'Cleanup preserves indefinite project history.');
    $check($store->message($project, $message['external_id'])->token === $stored->token, 'Cleanup preserves token mapping.');
    $pixel($message['tracking_url']);
    $check($store->report($project)['count'] === 2, 'Link remains valid after cleanup.');
    $store->retention($project, 0);
    $check($store->report($project)['count'] === 2, 'Deleted history cannot reappear after retention change.');
    $dated = $store->report($project, ['from' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)]);
    $check($dated['count'] === 0, 'Date filter limits reports.');

    $rangeId = Identifiers::random(128);
    $rangeMessage = $store->register($project, $rangeId);
    foreach ([6, 4, 0, -1] as $age) {
        $wpdb->insert($store->table('events'), ['message_id' => $rangeMessage->id, 'requested_at' => gmdate('Y-m-d H:i:s', time() - $age * DAY_IN_SECONDS)]);
    }
    $relative = $request($project, 'events', $read, 'GET', null, ['external_id' => $rangeId, 'last_days' => 5, 'per_page' => 1]);
    $range = $relative->get_data();
    $check($relative->get_status() === 200 && $range['count'] === 2 && count($range['events']) === 1, 'last_days filters recent events and excludes older/future events.');
    $check(strtotime($range['date_range']['to']) - strtotime($range['date_range']['from']) === 5 * DAY_IN_SECONDS, 'last_days resolves an exact UTC five-day window.');
    $check(abs(time() - strtotime($range['date_range']['to'])) < 10, 'Relative range ends at the current UTC request time.');
    $pinned = $request($project, 'events', $read, 'GET', null, ['external_id' => $rangeId, 'from' => $range['date_range']['from'], 'to' => $range['date_range']['to'], 'per_page' => 1, 'page' => 2])->get_data();
    $check($pinned['count'] === 2 && $pinned['events'][0]['id'] !== $range['events'][0]['id'] && $pinned['date_range'] === $range['date_range'], 'Resolved range can be pinned across subsequent event pages.');
    $relativeSummary = $request($project, 'summary', $read, 'GET', null, ['external_id' => $rangeId, 'last_days' => '5'])->get_data();
    $check($relativeSummary['count'] === 2 && isset($relativeSummary['date_range']) && !isset($relativeSummary['events']), 'last_days also applies to summaries.');
    $projectRange = $request($project, 'summary', $read, 'GET', null, ['last_days' => 5])->get_data();
    $check($projectRange['count'] === 4, 'last_days without external_id queries the whole project.');
    foreach ([0, -1, 1.5, 'five', 36501, '', [], true, null] as $invalidDays) {
        $invalidRange = $request($project, 'events', $read, 'GET', null, ['last_days' => $invalidDays]);
        $check($invalidRange->get_status() === 400 && $invalidRange->get_data()['code'] === 'wtp_invalid_days', 'Invalid last_days values are rejected.');
    }
    foreach ([['from' => '2026-01-01T00:00:00Z'], ['to' => '2026-01-01T00:00:00Z']] as $explicitRange) {
        $conflict = $request($project, 'summary', $read, 'GET', null, ['last_days' => 5] + $explicitRange);
        $check($conflict->get_status() === 400 && $conflict->get_data()['code'] === 'wtp_conflicting_range', 'Relative and explicit ranges cannot be combined.');
    }

    $selector = explode('.', substr($read, 4))[0];
    $store->revokeKey($project, $selector);
    $check($request($project, 'events', $read)->get_status() === 401, 'Revocation takes effect immediately.');
    $storedKeys = $wpdb->get_results("SELECT secret_hash FROM {$store->table('keys')}");
    $check(!str_contains(wp_json_encode($storedKeys), $key), 'Database never contains raw credentials.');
    $check(wp_next_scheduled('wtp_cleanup') !== false, 'Cleanup is scheduled.');
    $check(class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class), 'Updater bundled and autoloaded.');
    $store->deleteProject($project);
    $check(!$store->authorize($project, $key, false), 'Deleted project credentials no longer authorize.');
    $check($store->message($project, $message['external_id']) === null, 'Project deletion removes message.');
    $check(wp_remote_retrieve_body($pixel($message['tracking_url'])) === wp_remote_retrieve_body($image), 'Deleted link still returns indistinguishable image.');
    $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$store->table('events')} WHERE message_id = %d", $stored->id)) === 0, 'Project deletion removes history.');

    $http = static function (string $url, array $arguments = []): array {
        $parts = wp_parse_url($url);
        $internal = 'http://wordpress' . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $response = wp_remote_request($internal, $arguments + ['timeout' => 15, 'redirection' => 0]);
        if (is_wp_error($response)) {
            throw new RuntimeException('HTTP flow request failed: ' . $response->get_error_code());
        }
        return $response;
    };
    $nonce = static function (array $response, string $action): string {
        $tags = new WP_HTML_Tag_Processor(wp_remote_retrieve_body($response));
        $target = false;
        while ($tags->next_tag()) {
            if ($tags->get_tag() === 'FORM') {
                $target = $tags->get_attribute('data-wtp-action') === $action;
            }
            if ($target && $tags->get_tag() === 'INPUT' && $tags->get_attribute('name') === '_wpnonce') {
                return (string) $tags->get_attribute('value');
            }
        }
        throw new RuntimeException('Missing admin nonce for HTTP flow action: ' . $action);
    };
    $username = 'wtp-http-' . Identifiers::random(16);
    $password = Identifiers::random(48);
    $createdUser = wp_insert_user([
        'user_login' => $username, 'user_pass' => $password,
        'user_email' => $username . '@example.test', 'role' => 'administrator',
    ]);
    $check(!is_wp_error($createdUser), 'Create isolated HTTP test administrator.');
    $httpUserId = (int) $createdUser;
    update_user_meta($httpUserId, 'locale', 'de_CH');
    $loginPage = $http(wp_login_url());
    $login = $http(wp_login_url(), [
        'method' => 'POST', 'cookies' => wp_remote_retrieve_cookies($loginPage),
        'body' => ['log' => $username, 'pwd' => $password, 'testcookie' => '1', 'wp-submit' => 'Log In', 'redirect_to' => admin_url('admin.php?page=wtp')],
    ]);
    $check(wp_remote_retrieve_response_code($login) === 302, 'HTTP administrator login succeeds.');
    $cookies = array_merge(wp_remote_retrieve_cookies($loginPage), wp_remote_retrieve_cookies($login));
    $overview = $http(admin_url('admin.php?page=wtp'), ['cookies' => $cookies]);
    $check(wp_remote_retrieve_response_code($overview) === 200, 'Authenticated project overview loads over HTTP.');
    $check(str_contains(wp_remote_retrieve_body($overview), '<h2 class="wtp-projects-title">Projekte</h2>'), 'Fresh admin HTTP request uses German for a Swiss German user profile.');
    update_user_meta($httpUserId, 'locale', 'de_DE_formal');
    $formalOverview = $http(admin_url('admin.php?page=wtp'), ['cookies' => $cookies]);
    $check(str_contains(wp_remote_retrieve_body($formalOverview), '<h2 class="wtp-projects-title">Projekte</h2>'), 'Fresh admin HTTP request also uses German for a German-formal user profile.');
    update_user_meta($httpUserId, 'locale', 'de_CH');
    $httpProjectName = 'HTTP Flow ' . Identifiers::random(16);
    $created = $http(admin_url('admin.php?page=wtp'), [
        'method' => 'POST', 'cookies' => $cookies,
        'body' => ['wtp_action' => 'create', '_wpnonce' => $nonce($overview, 'create'), 'name' => $httpProjectName],
    ]);
    $check(wp_remote_retrieve_response_code($created) === 200, 'Create a project through the real admin POST.');
    $tags = new WP_HTML_Tag_Processor(wp_remote_retrieve_body($created));
    $httpProject = null;
    while ($tags->next_tag(['tag_name' => 'H2'])) {
        $headingId = $tags->get_attribute('id');
        if (is_string($headingId) && preg_match('/\Awtp-project-([A-Za-z0-9]{10})\z/D', $headingId, $matched)) {
            $httpProject = $matched[1];
            $fixtures[] = $httpProject;
            break;
        }
    }
    $check(Identifiers::validProject($httpProject), 'Admin-created project has a generated 10-character ID.');
    $check($store->project($httpProject)?->name === $httpProjectName, 'Admin-created project is persisted.');
    $settingsUrl = add_query_arg('project', $httpProject, admin_url('admin.php?page=wtp'));
    $issued = $http($settingsUrl, [
        'method' => 'POST', 'cookies' => $cookies,
        'body' => ['wtp_action' => 'key', '_wpnonce' => $nonce($created, 'key'), 'project' => $httpProject, 'name' => 'HTTP flow writer', 'scope' => 'write'],
    ]);
    $check(wp_remote_retrieve_response_code($issued) === 200, 'Issue a project API key through the real admin POST.');
    $check(preg_match('/wtp_[A-Za-z0-9]{16}\.[A-Za-z0-9]{64}/', wp_remote_retrieve_body($issued), $credential) === 1, 'Issued key appears in the one-time response.');
    $httpKey = $credential[0];
    $settingsAgain = $http($settingsUrl, ['cookies' => $cookies]);
    $check(!str_contains(wp_remote_retrieve_body($settingsAgain), $httpKey), 'Raw key is not displayed on subsequent requests.');
    $apiHttp = static function (string $resource, string $credential, string $method = 'GET', mixed $body = null, array $query = []) use ($http, $httpProject): array {
        $url = add_query_arg($query, rest_url('tracking-pixel/v1/projects/' . $httpProject . '/' . $resource));
        $arguments = ['method' => $method, 'headers' => ['Authorization' => 'Bearer ' . $credential]];
        if ($method === 'POST') {
            $arguments['headers']['Content-Type'] = 'application/json';
            $arguments['body'] = wp_json_encode($body);
        }
        $response = $http($url, $arguments);
        return [$response, json_decode(wp_remote_retrieve_body($response), true, 512, JSON_THROW_ON_ERROR)];
    };
    [$unauthorized] = $apiHttp('events', 'invalid');
    $check(wp_remote_retrieve_response_code($unauthorized) === 401, 'HTTP API rejects invalid Bearer credentials.');
    [$registrationResponse, $registration] = $apiHttp('messages', $httpKey, 'POST', new stdClass());
    $check(wp_remote_retrieve_response_code($registrationResponse) === 200, 'External HTTP API registers an email with only a Bearer key.');
    $check($registration['project_id'] === $httpProject && Identifiers::validExternal($registration['external_id']), 'HTTP registration returns matching project and generated email ID.');
    $check(isset($registration['tracking_url'], $registration['html']) && !str_contains($registration['html'], $httpKey), 'HTTP response contains safe pixel URL and HTML.');
    [, $initial] = $apiHttp('summary', $httpKey);
    $check($initial['count'] === 0 && $initial['first_request'] === null, 'New HTTP project starts without requests.');
    [$retriedResponse, $retried] = $apiHttp('messages', $httpKey, 'POST', ['external_id' => $registration['external_id']]);
    $check(wp_remote_retrieve_response_code($retriedResponse) === 200 && $retried === $registration, 'HTTP registration retry returns the original URL and ID.');
    $suppliedId = Identifiers::random(128);
    [$suppliedResponse, $supplied] = $apiHttp('messages', $httpKey, 'POST', ['external_id' => $suppliedId]);
    $check(wp_remote_retrieve_response_code($suppliedResponse) === 200 && $supplied['external_id'] === $suppliedId, 'HTTP registration accepts a sender-supplied 128-character ID.');
    [$invalidIdResponse] = $apiHttp('messages', $httpKey, 'POST', ['external_id' => '']);
    $check(wp_remote_retrieve_response_code($invalidIdResponse) === 400, 'HTTP registration rejects invalid supplied IDs.');
    for ($opening = 0; $opening < 2; $opening++) {
        $opened = $http($registration['tracking_url']);
        $check(wp_remote_retrieve_response_code($opened) === 200 && substr(wp_remote_retrieve_body($opened), 0, 6) === 'GIF89a', 'HTTP pixel request returns GIF data.');
    }
    $http($registration['tracking_url'], ['method' => 'HEAD']);
    [$eventsResponse, $events] = $apiHttp('events', $httpKey, 'GET', null, ['external_id' => $registration['external_id'], 'per_page' => 1]);
    $check(wp_remote_retrieve_response_code($eventsResponse) === 200, 'Retrieve event history through the external HTTP API.');
    $check($events['count'] === 2 && count($events['events']) === 1, 'HTTP reports contain repeat GETs, exclude HEAD, and paginate.');
    $check($events['events'][0]['external_id'] === $registration['external_id'], 'HTTP event links back to the registered email ID.');
    $check(wp_remote_retrieve_header($eventsResponse, 'x-wp-totalpages') === '2', 'HTTP response exposes pagination totals.');
    [, $next] = $apiHttp('events', $httpKey, 'GET', null, ['external_id' => $registration['external_id'], 'per_page' => 1, 'page' => 2]);
    $check($next['events'][0]['id'] !== $events['events'][0]['id'], 'HTTP pagination returns the other opening.');
    [, $httpSummary] = $apiHttp('summary', $httpKey, 'GET', null, ['external_id' => $registration['external_id']]);
    $check($httpSummary['count'] === 2 && $httpSummary['first_request'] !== null && $httpSummary['latest_request'] !== null, 'HTTP summary returns total and first/latest opening timestamps.');
    [$recentResponse, $recent] = $apiHttp('summary', $httpKey, 'GET', null, ['external_id' => $registration['external_id'], 'last_days' => 5]);
    $check(wp_remote_retrieve_response_code($recentResponse) === 200 && $recent['count'] === 2 && isset($recent['date_range']['from'], $recent['date_range']['to']), 'External HTTP API supports last_days and exposes resolved boundaries.');
    [, $unopened] = $apiHttp('summary', $httpKey, 'GET', null, ['external_id' => $suppliedId]);
    $check($unopened['count'] === 0, 'HTTP reporting keeps unopened email history separate.');
    $revoked = $http($settingsUrl, [
        'method' => 'POST', 'cookies' => $cookies,
        'body' => ['wtp_action' => 'revoke', '_wpnonce' => $nonce($settingsAgain, 'revoke'), 'project' => $httpProject, 'selector' => explode('.', substr($httpKey, 4))[0]],
    ]);
    $check(wp_remote_retrieve_response_code($revoked) === 200, 'Revoke the flow API key through the admin POST.');
    [$revokedResponse] = $apiHttp('events', $httpKey);
    $check(wp_remote_retrieve_response_code($revokedResponse) === 401, 'HTTP API immediately rejects the revoked key.');
    $deleted = $http(admin_url('admin.php?page=wtp'), [
        'method' => 'POST', 'cookies' => $cookies,
        'body' => ['wtp_action' => 'delete', '_wpnonce' => $nonce($revoked, 'delete'), 'project' => $httpProject, 'confirm_delete' => $httpProject],
    ]);
    $check(wp_remote_retrieve_response_code($deleted) === 200 && $store->project($httpProject) === null, 'Delete the flow project through the admin POST.');
    echo "Passed {$checks} integration checks on WordPress " . get_bloginfo('version') . ' / PHP ' . PHP_VERSION . ".\n";
} finally {
    foreach ($fixtures as $fixture) {
        $store->deleteProject($fixture);
    }
    if ($httpProjectName !== null) {
        foreach ($store->listProjects($httpProjectName)['items'] as $candidate) {
            if ($candidate->name === $httpProjectName) {
                $store->deleteProject($candidate->id);
            }
        }
    }
    if ($httpUserId > 0) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($httpUserId);
    }
}