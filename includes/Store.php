<?php

declare(strict_types=1);

namespace Metalleg\TrackingPixel;

use RuntimeException;

final class Store
{
    public function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wtp_' . $name;
    }

    public function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collation = $wpdb->get_charset_collate();
        $projects = $this->table('projects');
        $keys = $this->table('keys');
        $messages = $this->table('messages');
        $events = $this->table('events');
        dbDelta("CREATE TABLE {$projects} (
            id char(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            name varchar(190) NOT NULL,
            retention smallint unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) {$collation};");
        dbDelta("CREATE TABLE {$keys} (
            id char(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            project_id char(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            name varchar(190) NOT NULL,
            secret_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            scope varchar(10) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY project_id (project_id)
        ) {$collation};");
        dbDelta("CREATE TABLE {$messages} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            project_id char(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            external_id char(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            token char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY project_external (project_id,external_id),
            UNIQUE KEY token (token)
        ) {$collation};");
        dbDelta("CREATE TABLE {$events} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            message_id bigint unsigned NOT NULL,
            requested_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY message_time (message_id,requested_at),
            KEY requested_at (requested_at)
        ) {$collation};");
        foreach ([$projects, $keys, $messages, $events] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                throw new RuntimeException(__('Unable to install tracking pixel database tables.', 'wordpress-tracking-pixel'));
            }
        }
        update_option('wtp_schema', WTP_VERSION, false);
    }

    public function project(string $id): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('projects')} WHERE id = %s", $id));
    }

    public function projects(): array
    {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table('projects')} ORDER BY created_at DESC, id");
    }

    public function listProjects(string $search = '', int $page = 1, int $perPage = 20): array
    {
        global $wpdb;
        $pattern = '%' . $wpdb->esc_like($search) . '%';
        $where = $wpdb->prepare('(p.name LIKE %s OR p.id LIKE %s)', $pattern, $pattern);
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table('projects')} p WHERE {$where}");
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*,
                (SELECT COUNT(*) FROM {$this->table('messages')} m WHERE m.project_id = p.id) AS message_count,
                (SELECT COUNT(*) FROM {$this->table('keys')} k WHERE k.project_id = p.id) AS key_count
             FROM {$this->table('projects')} p WHERE {$where}
             ORDER BY p.created_at DESC, p.id LIMIT %d OFFSET %d", $perPage, ($page - 1) * $perPage
        ));
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function createProject(string $name): string
    {
        global $wpdb;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $id = Identifiers::random(10);
            if ($wpdb->insert($this->table('projects'), ['id' => $id, 'name' => $name, 'created_at' => gmdate('Y-m-d H:i:s')])) {
                return $id;
            }
            if (!$this->project($id)) {
                break;
            }
        }
        throw new RuntimeException(__('Unable to create project.', 'wordpress-tracking-pixel'));
    }

    public function createKey(string $project, string $name, string $scope): string
    {
        global $wpdb;
        if (!$this->project($project) || !in_array($scope, ['read', 'write'], true)) {
            throw new RuntimeException(__('Invalid project or key scope.', 'wordpress-tracking-pixel'));
        }
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $selector = Identifiers::random(16);
            $secret = Identifiers::random(64);
            if ($wpdb->insert($this->table('keys'), [
                'id' => $selector, 'project_id' => $project, 'name' => $name,
                'scope' => $scope, 'secret_hash' => hash('sha256', $secret),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ])) {
                return 'wtp_' . $selector . '.' . $secret;
            }
        }
        throw new RuntimeException(__('Unable to issue key.', 'wordpress-tracking-pixel'));
    }

    public function authorize(string $project, string $credential, bool $write): bool
    {
        global $wpdb;
        if (!preg_match('/\Awtp_([A-Za-z0-9]{16})\.([A-Za-z0-9]{64})\z/D', $credential, $parts)) {
            return false;
        }
        $key = $wpdb->get_row($wpdb->prepare(
            "SELECT secret_hash, scope FROM {$this->table('keys')} WHERE id = %s AND project_id = %s",
            $parts[1], $project
        ));
        return $key && hash_equals($key->secret_hash, hash('sha256', $parts[2]))
            && (!$write || $key->scope === 'write') && $this->project($project) !== null;
    }

    public function keys(string $project): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, scope, created_at FROM {$this->table('keys')} WHERE project_id = %s ORDER BY created_at DESC",
            $project
        ));
    }

    public function revokeKey(string $project, string $selector): void
    {
        global $wpdb;
        $wpdb->delete($this->table('keys'), ['project_id' => $project, 'id' => $selector]);
    }

    public function register(string $project, ?string $external): object
    {
        global $wpdb;
        if (!$this->project($project)) {
            throw new RuntimeException(__('Project not found.', 'wordpress-tracking-pixel'));
        }
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $identifier = $external ?? Identifiers::random(128);
            $existing = $this->message($project, $identifier);
            if ($existing) {
                if ($external !== null) {
                    return $existing;
                }
                continue;
            }
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$this->table('messages')} (project_id, external_id, token, created_at) VALUES (%s, %s, %s, %s)",
                $project, $identifier, Identifiers::random(64), gmdate('Y-m-d H:i:s')
            ));
            $message = $this->message($project, $identifier);
            if ($message && ($inserted === 1 || $external !== null)) {
                return $message;
            }
            if ($inserted === false) {
                break;
            }
        }
        throw new RuntimeException(__('Unable to register message.', 'wordpress-tracking-pixel'));
    }

    public function message(string $project, string $external): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('messages')} WHERE project_id = %s AND external_id = %s", $project, $external
        ));
    }

    public function record(string $project, string $token): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->table('events')} (message_id, requested_at)
            SELECT m.id, %s FROM {$this->table('messages')} m
            INNER JOIN {$this->table('projects')} p ON p.id = m.project_id
            WHERE m.project_id = %s AND m.token = %s", gmdate('Y-m-d H:i:s'), $project, $token
        ));
    }

    public function retention(string $project, int $days): void
    {
        global $wpdb;
        if (!in_array($days, [0, 90], true)) {
            throw new RuntimeException(__('Invalid retention.', 'wordpress-tracking-pixel'));
        }
        if ($wpdb->update($this->table('projects'), ['retention' => $days], ['id' => $project]) === false) {
            throw new RuntimeException(__('Unable to update retention.', 'wordpress-tracking-pixel'));
        }
    }

    public function cleanup(): void
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare(
            "DELETE e FROM {$this->table('events')} e
            INNER JOIN {$this->table('messages')} m ON m.id = e.message_id
            INNER JOIN {$this->table('projects')} p ON p.id = m.project_id
            WHERE p.retention = 90 AND e.requested_at < %s", $cutoff
        ));
    }

    public function deleteProject(string $project): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE e FROM {$this->table('events')} e INNER JOIN {$this->table('messages')} m ON m.id = e.message_id WHERE m.project_id = %s", $project
        ));
        $wpdb->delete($this->table('messages'), ['project_id' => $project]);
        $wpdb->delete($this->table('keys'), ['project_id' => $project]);
        $wpdb->delete($this->table('projects'), ['id' => $project]);
    }

    public function report(string $project, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        global $wpdb;
        $clauses = ['m.project_id = %s', '(p.retention = 0 OR e.requested_at >= %s)'];
        $values = [$project, gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS)];
        foreach (['external_id' => 'm.external_id = %s', 'from' => 'e.requested_at >= %s', 'to' => 'e.requested_at <= %s'] as $name => $clause) {
            if (isset($filters[$name])) {
                $clauses[] = $clause;
                $values[] = $filters[$name];
            }
        }
        $where = implode(' AND ', $clauses);
        $join = "FROM {$this->table('events')} e INNER JOIN {$this->table('messages')} m ON m.id = e.message_id
            INNER JOIN {$this->table('projects')} p ON p.id = m.project_id WHERE {$where}";
        $summary = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS count, MIN(e.requested_at) AS first_request, MAX(e.requested_at) AS latest_request {$join}", ...$values
        ), ARRAY_A);
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT e.id, m.external_id, e.requested_at {$join} ORDER BY e.requested_at DESC, e.id DESC LIMIT %d OFFSET %d",
            ...array_merge($values, [$perPage, ($page - 1) * $perPage])
        ), ARRAY_A);
        if ($summary === null || $wpdb->last_error !== '') {
            throw new RuntimeException(__('Unable to retrieve events.', 'wordpress-tracking-pixel'));
        }
        $iso = static fn (?string $value): ?string => $value === null ? null : str_replace(' ', 'T', $value) . 'Z';
        return [
            'project_id' => $project, 'count' => (int) $summary['count'],
            'first_request' => $iso($summary['first_request']), 'latest_request' => $iso($summary['latest_request']),
            'page' => $page, 'per_page' => $perPage,
            'events' => array_map(static fn (array $event): array => [
                'id' => (int) $event['id'], 'external_id' => $event['external_id'], 'requested_at' => $iso($event['requested_at']),
            ], $events),
        ];
    }
}