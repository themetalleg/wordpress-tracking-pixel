<?php

declare(strict_types=1);

namespace Metalleg\TrackingPixel;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class Admin
{
    private ?string $issuedKey = null;
    private string $notice = '';
    private bool $failed = false;
    private ?string $selectedProject = null;

    public function __construct(private Store $store)
    {
    }

    public function hooks(): void
    {
        add_action('admin_menu', function (): void {
            add_menu_page('Tracking Pixel', 'Tracking Pixel', 'manage_options', 'wtp', [$this, 'page'], 'dashicons-email-alt');
            add_submenu_page('wtp', __('Projects', 'wordpress-tracking-pixel'), __('Projects', 'wordpress-tracking-pixel'), 'manage_options', 'wtp', [$this, 'page']);
            add_submenu_page('wtp', __('Setup / Tutorial', 'wordpress-tracking-pixel'), __('Setup / Tutorial', 'wordpress-tracking-pixel'), 'manage_options', 'wtp-tutorial', [$this, 'tutorial']);
        });
        add_action('admin_init', [$this, 'process']);
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            if ($hook !== 'toplevel_page_wtp' && !str_ends_with($hook, '_page_wtp-tutorial')) {
                return;
            }
            wp_enqueue_style('wtp-admin', plugins_url('assets/admin.css', WTP_FILE), [], WTP_VERSION);
            wp_enqueue_script('wtp-admin', plugins_url('assets/admin.js', WTP_FILE), [], WTP_VERSION, true);
        });
    }

    private function value(string $name): string
    {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? wp_unslash($_POST[$name]) : '';
    }

    public function process(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['wtp_action'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wordpress-tracking-pixel'), '', ['response' => 403]);
        }
        check_admin_referer('wtp_manage');
        nocache_headers();
        $action = $this->value('wtp_action');
        $project = $this->value('project');
        try {
            if ($action === 'create') {
                $name = sanitize_text_field($this->value('name'));
                if ($name === '' || strlen($name) > 190) {
                    throw new RuntimeException(__('Enter a project name of 1-190 bytes.', 'wordpress-tracking-pixel'));
                }
                $this->selectedProject = $this->store->createProject($name);
            } else {
                if (!Identifiers::validProject($project) || !$this->store->project($project)) {
                    throw new RuntimeException(__('Project not found.', 'wordpress-tracking-pixel'));
                }
                switch ($action) {
                    case 'key':
                        $name = sanitize_text_field($this->value('name'));
                        if ($name === '' || strlen($name) > 190) {
                            throw new RuntimeException(__('Enter a key label of 1-190 bytes.', 'wordpress-tracking-pixel'));
                        }
                        $this->issuedKey = $this->store->createKey($project, $name, $this->value('scope'));
                        break;
                    case 'revoke':
                        $this->store->revokeKey($project, $this->value('selector'));
                        break;
                    case 'retention':
                        $retention = $this->value('retention');
                        if (!in_array($retention, ['0', '90'], true)) {
                            throw new RuntimeException(__('Choose indefinite or 90-day history.', 'wordpress-tracking-pixel'));
                        }
                        if ($retention === '90' && $this->value('confirm_retention') !== 'yes') {
                            throw new RuntimeException(__('Confirm permanent deletion of events older than 90 days.', 'wordpress-tracking-pixel'));
                        }
                        $this->store->retention($project, (int) $retention);
                        break;
                    case 'delete':
                        if ($this->value('confirm_delete') !== $project) {
                            throw new RuntimeException(__('Type the project ID to confirm deletion.', 'wordpress-tracking-pixel'));
                        }
                        $this->store->deleteProject($project);
                        break;
                    default:
                        throw new RuntimeException(__('Unknown action.', 'wordpress-tracking-pixel'));
                }
            }
            $this->notice = __('Changes saved.', 'wordpress-tracking-pixel');
        } catch (RuntimeException $exception) {
            $this->failed = true;
            $this->notice = $exception->getMessage();
        }
    }

    private function form(string $action, string $project = ''): void
    {
        $url = admin_url('admin.php?page=wtp');
        if ($project !== '' && $action !== 'delete') {
            $url = add_query_arg('project', $project, $url);
        }
        echo '<form class="wtp-form" data-wtp-action="' . esc_attr($action) . '" method="post" action="' . esc_url($url) . '">';
        wp_nonce_field('wtp_manage');
        echo '<input type="hidden" name="wtp_action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="project" value="' . esc_attr($project) . '">';
    }

    public function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap wtp-admin"><h1>Tracking Pixel</h1>';
        if ($this->notice !== '') {
            echo '<div class="notice ' . ($this->failed ? 'notice-error' : 'notice-success') . '" role="status"><p>' . esc_html($this->notice) . '</p></div>';
        }
        if ($this->issuedKey !== null) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('New API key, shown only in this response. Store it securely:', 'wordpress-tracking-pixel') . '</p><p><code style="overflow-wrap:anywhere">' . esc_html($this->issuedKey) . '</code></p><p>' . esc_html__('Refreshing this POST response may issue another key.', 'wordpress-tracking-pixel') . '</p></div>';
        }
        $requested = $this->selectedProject ?? ($_GET['project'] ?? $_GET['report_project'] ?? '');
        $project = Identifiers::validProject($requested) ? $this->store->project($requested) : null;
        if ($project !== null) {
            $this->projectPage($project);
        } else {
            if ($requested !== '') {
                echo '<div class="notice notice-error"><p>' . esc_html__('Project not found.', 'wordpress-tracking-pixel') . '</p></div>';
            }
            $this->overview();
        }
        echo '</div>';
    }

    private function overview(): void
    {
        echo '<section class="wtp-section"><h2>' . esc_html__('New Project', 'wordpress-tracking-pixel') . '</h2>';
        $this->form('create');
        echo '<div class="wtp-field"><label for="wtp-project-name">' . esc_html__('Project name', 'wordpress-tracking-pixel') . '</label><div class="wtp-control wtp-inline"><input id="wtp-project-name" type="text" name="name" required maxlength="190">';
        submit_button(__('Create Project', 'wordpress-tracking-pixel'), 'primary', 'submit', false);
        echo '</div></div></form></section><h2 class="wtp-projects-title">' . esc_html__('Projects', 'wordpress-tracking-pixel') . '</h2>';
        $search = isset($_GET['s']) && is_string($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $page = max(1, absint($_GET['project_page'] ?? 1));
        $result = $this->store->listProjects($search, $page);
        echo '<form method="get" class="wtp-search"><input type="hidden" name="page" value="wtp"><label class="screen-reader-text" for="wtp-search">' . esc_html__('Search Projects', 'wordpress-tracking-pixel') . '</label><input id="wtp-search" type="search" name="s" value="' . esc_attr($search) . '">';
        submit_button(__('Search Projects', 'wordpress-tracking-pixel'), 'secondary', 'submit', false);
        echo '</form><div class="wtp-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__('Projects', 'wordpress-tracking-pixel') . '"><table class="widefat striped wtp-project-list"><thead><tr><th scope="col">' . esc_html__('Project name', 'wordpress-tracking-pixel') . '</th><th scope="col">' . esc_html__('Project ID', 'wordpress-tracking-pixel') . '</th><th scope="col">' . esc_html__('Event retention', 'wordpress-tracking-pixel') . '</th><th scope="col">' . esc_html__('Messages', 'wordpress-tracking-pixel') . '</th><th scope="col">' . esc_html__('API Keys', 'wordpress-tracking-pixel') . '</th></tr></thead><tbody>';
        foreach ($result['items'] as $project) {
            $url = add_query_arg(['page' => 'wtp', 'project' => $project->id], admin_url('admin.php'));
            echo '<tr><td><strong><a href="' . esc_url($url) . '">' . esc_html($project->name) . '</a></strong><div class="wtp-row-actions"><a href="' . esc_url($url) . '">' . esc_html__('Settings', 'wordpress-tracking-pixel') . '</a><span aria-hidden="true"> | </span><a href="' . esc_url(add_query_arg('tab', 'events', $url)) . '">' . esc_html__('Events', 'wordpress-tracking-pixel') . '</a></div></td><td><code>' . esc_html($project->id) . '</code></td><td>' . esc_html((int) $project->retention === 90 ? __('Rolling 90 days', 'wordpress-tracking-pixel') : __('Indefinite', 'wordpress-tracking-pixel')) . '</td><td>' . esc_html(number_format_i18n((int) $project->message_count)) . '</td><td>' . esc_html(number_format_i18n((int) $project->key_count)) . '</td></tr>';
        }
        if (!$result['items']) {
            echo '<tr><td colspan="5" class="wtp-empty">' . esc_html($search === '' ? __('No projects yet.', 'wordpress-tracking-pixel') : __('No projects match your search.', 'wordpress-tracking-pixel')) . '</td></tr>';
        }
        echo '</tbody></table></div><nav class="wtp-pagination" aria-label="' . esc_attr__('Projects', 'wordpress-tracking-pixel') . '"><span>' . esc_html(sprintf(__('Page %1$d of %2$d', 'wordpress-tracking-pixel'), $result['page'], $result['pages'])) . '</span>';
        foreach ([-1 => __('Previous', 'wordpress-tracking-pixel'), 1 => __('Next', 'wordpress-tracking-pixel')] as $offset => $label) {
            $target = $result['page'] + $offset;
            if ($target >= 1 && $target <= $result['pages']) {
                echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'wtp', 's' => $search, 'project_page' => $target], admin_url('admin.php'))) . '">' . esc_html($label) . '</a>';
            }
        }
        echo '</nav>';
    }

    private function projectPage(object $project): void
    {
        $id = $project->id;
        $events = ($_GET['tab'] ?? '') === 'events' || isset($_GET['report_project']);
        $url = add_query_arg(['page' => 'wtp', 'project' => $id], admin_url('admin.php'));
        echo '<section class="wtp-section wtp-project" aria-labelledby="wtp-project-' . esc_attr($id) . '"><header class="wtp-section-header"><div><h2 id="wtp-project-' . esc_attr($id) . '">' . esc_html($project->name) . '</h2><p class="wtp-project-id">' . esc_html__('Project ID', 'wordpress-tracking-pixel') . ': <code>' . esc_html($id) . '</code></p></div><a class="button" href="' . esc_url(admin_url('admin.php?page=wtp')) . '"><span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span> ' . esc_html__('All Projects', 'wordpress-tracking-pixel') . '</a></header><nav class="nav-tab-wrapper wtp-tabs" aria-label="' . esc_attr__('Project navigation', 'wordpress-tracking-pixel') . '"><a class="nav-tab' . (!$events ? ' nav-tab-active' : '') . '" ' . (!$events ? 'aria-current="page"' : '') . ' href="' . esc_url($url) . '">' . esc_html__('Settings', 'wordpress-tracking-pixel') . '</a><a class="nav-tab' . ($events ? ' nav-tab-active' : '') . '" ' . ($events ? 'aria-current="page"' : '') . ' href="' . esc_url(add_query_arg('tab', 'events', $url)) . '">' . esc_html__('Events', 'wordpress-tracking-pixel') . '</a></nav>';
        if ($events) {
            echo '</section>';
            $this->report($id);
            return;
        }
        $this->form('retention', $id);
        echo '<div class="wtp-field"><label for="wtp-retention-' . esc_attr($id) . '">' . esc_html__('Event retention', 'wordpress-tracking-pixel') . '</label><div class="wtp-control"><select id="wtp-retention-' . esc_attr($id) . '" name="retention"><option value="0" ' . selected((int) $project->retention, 0, false) . '>' . esc_html__('Indefinite', 'wordpress-tracking-pixel') . '</option><option value="90" ' . selected((int) $project->retention, 90, false) . '>' . esc_html__('Rolling 90 days', 'wordpress-tracking-pixel') . '</option></select></div></div>';
        echo '<div class="wtp-retention-confirmation wtp-offset"><label class="wtp-confirmation"><input type="checkbox" name="confirm_retention" value="yes"><span>' . esc_html__('I accept permanent deletion of events older than 90 days.', 'wordpress-tracking-pixel') . '</span></label></div><div class="wtp-actions wtp-offset">';
        submit_button(__('Save Retention', 'wordpress-tracking-pixel'), 'secondary', 'submit', false);
        echo '</div></form><div class="wtp-subsection"><h3>' . esc_html__('API Keys', 'wordpress-tracking-pixel') . '</h3>';
        echo '<div class="wtp-table-scroll"><table class="widefat striped wtp-keys"><thead><tr><th scope="col">' . esc_html__('Label', 'wordpress-tracking-pixel') . '</th><th scope="col">' . esc_html__('Key ID', 'wordpress-tracking-pixel') . '</th><th scope="col">' . esc_html__('Access', 'wordpress-tracking-pixel') . '</th><th scope="col">' . esc_html__('Action', 'wordpress-tracking-pixel') . '</th></tr></thead><tbody>';
        $keys = $this->store->keys($id);
        if (!$keys) {
            echo '<tr><td colspan="4" class="wtp-empty">' . esc_html__('No API keys yet.', 'wordpress-tracking-pixel') . '</td></tr>';
        }
        foreach ($keys as $key) {
            echo '<tr><td>' . esc_html($key->name) . '</td><td><code>' . esc_html($key->id) . '</code></td><td>' . esc_html($key->scope === 'write' ? __('Read / write', 'wordpress-tracking-pixel') : __('Read only', 'wordpress-tracking-pixel')) . '</td><td>';
            $this->form('revoke', $id);
            echo '<input type="hidden" name="selector" value="' . esc_attr($key->id) . '">';
            submit_button(__('Revoke', 'wordpress-tracking-pixel'), 'small', 'submit', false);
            echo '</form></td></tr>';
        }
        echo '</tbody></table></div>';
        $this->form('key', $id);
        echo '<div class="wtp-field"><label for="wtp-key-name-' . esc_attr($id) . '">' . esc_html__('Key label', 'wordpress-tracking-pixel') . '</label><div class="wtp-control"><input id="wtp-key-name-' . esc_attr($id) . '" type="text" name="name" required maxlength="190"></div></div><div class="wtp-field"><label for="wtp-key-scope-' . esc_attr($id) . '">' . esc_html__('Access', 'wordpress-tracking-pixel') . '</label><div class="wtp-control"><select id="wtp-key-scope-' . esc_attr($id) . '" name="scope"><option value="write">' . esc_html__('Read / write', 'wordpress-tracking-pixel') . '</option><option value="read">' . esc_html__('Read only', 'wordpress-tracking-pixel') . '</option></select></div></div><div class="wtp-actions wtp-offset">';
        submit_button(__('Issue Key', 'wordpress-tracking-pixel'), 'secondary', 'submit', false);
        echo '</div></form></div><details class="wtp-danger"><summary>' . esc_html__('Delete Project', 'wordpress-tracking-pixel') . '</summary>';
        $this->form('delete', $id);
        echo '<p class="wtp-danger-message">' . esc_html__('Permanently deletes every message, event, and key in this project. Its tracking links stop recording requests.', 'wordpress-tracking-pixel') . '</p><div class="wtp-field"><label for="wtp-delete-' . esc_attr($id) . '">' . esc_html__('Confirm project ID', 'wordpress-tracking-pixel') . '</label><div class="wtp-control"><input id="wtp-delete-' . esc_attr($id) . '" type="text" name="confirm_delete" required autocomplete="off"></div></div><div class="wtp-actions wtp-offset">';
        submit_button(__('Delete Project Permanently', 'wordpress-tracking-pixel'), 'delete', 'submit', false);
        echo '</div></form></details></section>';
    }

    private function date(?string $value, ?string $format = null): string
    {
        $utc = Api::date($value);
        if ($utc === null) {
            return '-';
        }
        $timestamp = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp();
        return wp_date($format ?? get_option('date_format') . ' ' . get_option('time_format'), $timestamp, wp_timezone());
    }

    private function filterDate(string $value): ?string
    {
        $utc = Api::date($value);
        if ($utc !== null) {
            return $utc;
        }
        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, wp_timezone());
            if ($date && $date->format($format) === $value) {
                return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    private function eventPagination(array $base, int $page, int $pages, int $total, int $perPage): void
    {
        $start = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
        $end = min($page * $perPage, $total);
        echo '<nav class="wtp-pagination wtp-event-pagination" aria-label="' . esc_attr__('Event pagination', 'wordpress-tracking-pixel') . '"><span class="wtp-item-range">' . esc_html(sprintf(__('%1$s-%2$s of %3$s events', 'wordpress-tracking-pixel'), number_format_i18n($start), number_format_i18n($end), number_format_i18n($total))) . '</span><span>' . esc_html(sprintf(__('Page %1$d of %2$d', 'wordpress-tracking-pixel'), $page, $pages)) . '</span><div class="wtp-page-links">';
        $links = [
            [1, $page > 1, 'controls-skipback', __('First page', 'wordpress-tracking-pixel')],
            [$page - 1, $page > 1, 'arrow-left-alt2', __('Previous page', 'wordpress-tracking-pixel')],
            [$page + 1, $page < $pages, 'arrow-right-alt2', __('Next page', 'wordpress-tracking-pixel')],
            [$pages, $page < $pages, 'controls-skipforward', __('Last page', 'wordpress-tracking-pixel')],
        ];
        foreach ($links as [$target, $enabled, $icon, $label]) {
            if ($enabled) {
                echo '<a class="button wtp-icon-button" href="' . esc_url(add_query_arg($base + ['event_page' => $target], admin_url('admin.php'))) . '" title="' . esc_attr($label) . '">';
            } else {
                echo '<span class="button wtp-icon-button disabled" aria-disabled="true" title="' . esc_attr($label) . '">';
            }
            echo '<span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($label) . '</span>' . ($enabled ? '</a>' : '</span>');
        }
        echo '</div></nav>';
    }

    private function report(string $project): void
    {
        $filters = [];
        $error = '';
        $perPage = absint($_GET['event_per_page'] ?? 50);
        if (!in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 50;
        }
        foreach (['external_id', 'from', 'to'] as $name) {
            $value = isset($_GET[$name]) && is_string($_GET[$name]) ? wp_unslash($_GET[$name]) : '';
            if ($value !== '') {
                if ($name === 'external_id' ? Identifiers::validExternal($value) : $this->filterDate($value) !== null) {
                    $filters[$name] = $name === 'external_id' ? $value : $this->filterDate($value);
                } else {
                    $error = __('Invalid filter. IDs require 128 alphanumeric characters; choose a valid date and time in the site timezone.', 'wordpress-tracking-pixel');
                }
            }
        }
        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
            $error = __('From must not be later than to.', 'wordpress-tracking-pixel');
        }
        echo '<section class="wtp-section wtp-report"><h2>' . esc_html(sprintf(__('Events: %s', 'wordpress-tracking-pixel'), $project)) . '</h2><form class="wtp-form" method="get"><input type="hidden" name="page" value="wtp"><input type="hidden" name="project" value="' . esc_attr($project) . '"><input type="hidden" name="tab" value="events">';
        echo '<p class="description wtp-offset">' . esc_html(sprintf(__('Timezone: %s', 'wordpress-tracking-pixel'), wp_timezone_string())) . '</p>';
        foreach (['external_id' => __('Email ID', 'wordpress-tracking-pixel'), 'from' => __('From', 'wordpress-tracking-pixel'), 'to' => __('To', 'wordpress-tracking-pixel')] as $name => $label) {
            $value = isset($_GET[$name]) && is_string($_GET[$name]) ? wp_unslash($_GET[$name]) : '';
            $type = $name === 'external_id' ? 'text' : 'datetime-local';
            if ($name !== 'external_id' && Api::date($value) !== null) {
                $value = $this->date($value, 'Y-m-d\TH:i:s');
            }
            echo '<div class="wtp-field"><label for="wtp-filter-' . esc_attr($name) . '">' . esc_html($label) . '</label><div class="wtp-control"><input id="wtp-filter-' . esc_attr($name) . '" type="' . esc_attr($type) . '"' . ($type === 'datetime-local' ? ' step="1"' : '') . ' name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></div></div>';
        }
        echo '<div class="wtp-field"><label for="wtp-event-per-page">' . esc_html__('Events per page', 'wordpress-tracking-pixel') . '</label><div class="wtp-control"><select id="wtp-event-per-page" name="event_per_page">';
        foreach ([10, 25, 50, 100] as $size) {
            echo '<option value="' . esc_attr((string) $size) . '"' . selected($perPage, $size, false) . '>' . esc_html(number_format_i18n($size)) . '</option>';
        }
        echo '</select></div></div><div class="wtp-actions wtp-offset">';
        submit_button(__('Filter Events', 'wordpress-tracking-pixel'), 'secondary', 'submit', false);
        echo '</div></form>';
        if ($error !== '') {
            echo '<p role="alert">' . esc_html($error) . '</p></section>';
            return;
        }
        $page = max(1, min(1000000, absint($_GET['event_page'] ?? 1)));
        try {
            $report = $this->store->report($project, $filters, $page, $perPage);
            $pages = max(1, (int) ceil($report['count'] / $perPage));
            if ($page > $pages) {
                $page = $pages;
                $report = $this->store->report($project, $filters, $page, $perPage);
            }
        } catch (RuntimeException $exception) {
            echo '<p role="alert">' . esc_html__('Unable to retrieve events.', 'wordpress-tracking-pixel') . '</p></section>';
            return;
        }
        echo '<dl class="wtp-summary"><div><dt>' . esc_html__('Requests', 'wordpress-tracking-pixel') . '</dt><dd>' . esc_html(number_format_i18n($report['count'])) . '</dd></div><div><dt>' . esc_html__('First request', 'wordpress-tracking-pixel') . '</dt><dd>' . esc_html($this->date($report['first_request'])) . '</dd></div><div><dt>' . esc_html__('Latest request', 'wordpress-tracking-pixel') . '</dt><dd>' . esc_html($this->date($report['latest_request'])) . '</dd></div></dl>';
        $base = ['page' => 'wtp', 'project' => $project, 'tab' => 'events', 'event_per_page' => $perPage];
        foreach ($filters as $name => $value) {
            $base[$name] = $name === 'external_id' ? $value : str_replace(' ', 'T', $value) . 'Z';
        }
        $this->eventPagination($base, $page, $pages, $report['count'], $perPage);
        echo '<div class="wtp-table-scroll"><table class="widefat striped wtp-events"><thead><tr><th scope="col">' . esc_html__('Email ID', 'wordpress-tracking-pixel') . '</th><th scope="col">' . esc_html__('Requested At', 'wordpress-tracking-pixel') . '</th></tr></thead><tbody>';
        foreach ($report['events'] as $event) {
            echo '<tr><td><code>' . esc_html($event['external_id']) . '</code></td><td><time datetime="' . esc_attr($event['requested_at']) . '">' . esc_html($this->date($event['requested_at'])) . '</time></td></tr>';
        }
        if (!$report['events']) {
            echo '<tr><td colspan="2">' . esc_html__('No requests in this window.', 'wordpress-tracking-pixel') . '</td></tr>';
        }
        echo '</tbody></table></div>';
        $this->eventPagination($base, $page, $pages, $report['count'], $perPage);
        echo '</section>';
    }

    public function tutorial(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap wtp-admin wtp-tutorial"><h1>' . esc_html__('Setup / Tutorial', 'wordpress-tracking-pixel') . '</h1>';
        require __DIR__ . '/tutorial.php';
        echo '</div>';
    }
}