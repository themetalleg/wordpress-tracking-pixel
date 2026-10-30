<?php

declare(strict_types=1);

namespace Metalleg\TrackingPixel;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Api
{
    public function __construct(private Store $store)
    {
    }

    public function routes(): void
    {
        $prefix = '/projects/(?P<project>[A-Za-z0-9]{10})';
        register_rest_route('tracking-pixel/v1', $prefix . '/messages', [
            'methods' => 'POST', 'callback' => [$this, 'register'],
            'permission_callback' => fn (WP_REST_Request $request) => $this->permission($request, true),
        ]);
        foreach (['events', 'summary'] as $resource) {
            register_rest_route('tracking-pixel/v1', $prefix . '/' . $resource, [
                'methods' => 'GET', 'callback' => [$this, 'report'],
                'permission_callback' => fn (WP_REST_Request $request) => $this->permission($request, false),
            ]);
        }
    }

    public function permission(WP_REST_Request $request, bool $write): bool|WP_Error
    {
        if (!is_ssl() && wp_get_environment_type() !== 'local') {
            return new WP_Error('wtp_https_required', __('HTTPS is required.', 'wordpress-tracking-pixel'), ['status' => 403]);
        }
        $header = $request->get_header('authorization') ?? '';
        if (!preg_match('/\ABearer (wtp_[A-Za-z0-9]{16}\.[A-Za-z0-9]{64})\z/D', $header, $parts)
            || !$this->store->authorize($request['project'], $parts[1], $write)) {
            return new WP_Error('wtp_unauthorized', __('Invalid credentials or insufficient project permissions.', 'wordpress-tracking-pixel'), ['status' => 401]);
        }
        return true;
    }

    public function register(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!$request->is_json_content_type()) {
            return new WP_Error('wtp_json_required', __('Send a JSON object with Content-Type: application/json.', 'wordpress-tracking-pixel'), ['status' => 400]);
        }
        $params = $request->get_json_params();
        $raw = ltrim($request->get_body());
        if (!is_array($params) || !str_starts_with($raw, '{')) {
            return new WP_Error('wtp_invalid_body', __('Send a JSON object, optionally containing external_id.', 'wordpress-tracking-pixel'), ['status' => 400]);
        }
        $supplied = array_key_exists('external_id', $params);
        if ($supplied && !Identifiers::validExternal($params['external_id'])) {
            return new WP_Error('wtp_invalid_id', __('external_id must contain exactly 128 ASCII letters or digits.', 'wordpress-tracking-pixel'), ['status' => 400]);
        }
        try {
            $message = $this->store->register($request['project'], $supplied ? $params['external_id'] : null);
            $response = new WP_REST_Response(Plugin::tracking($message), 200);
            $response->header('Cache-Control', 'no-store');
            return $response;
        } catch (RuntimeException $exception) {
            return new WP_Error('wtp_storage_error', __('Unable to register message.', 'wordpress-tracking-pixel'), ['status' => 500]);
        }
    }

    public static function date(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d\TH:i:s\Z') === $value ? $date->format('Y-m-d H:i:s') : null;
    }

    public function report(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $filters = [];
        if ($request->has_param('last_days')) {
            if ($request->has_param('from') || $request->has_param('to')) {
                return new WP_Error('wtp_conflicting_range', __('Use last_days alone, or from/to, not both.', 'wordpress-tracking-pixel'), ['status' => 400]);
            }
            $days = $request['last_days'];
            if ((!is_int($days) && !is_string($days)) || filter_var($days, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 36500]]) === false) {
                return new WP_Error('wtp_invalid_days', __('last_days must be a whole number between 1 and 36500.', 'wordpress-tracking-pixel'), ['status' => 400]);
            }
            $now = time();
            $filters['from'] = gmdate('Y-m-d H:i:s', $now - (int) $days * DAY_IN_SECONDS);
            $filters['to'] = gmdate('Y-m-d H:i:s', $now);
        }
        if ($request->has_param('external_id')) {
            if (!Identifiers::validExternal($request['external_id'])) {
                return new WP_Error('wtp_invalid_id', __('Invalid external_id.', 'wordpress-tracking-pixel'), ['status' => 400]);
            }
            $filters['external_id'] = $request['external_id'];
        }
        foreach (['from', 'to'] as $name) {
            if ($request->has_param($name)) {
                $value = self::date($request[$name]);
                if ($value === null) {
                    return new WP_Error('wtp_invalid_date', __('Dates must be valid UTC timestamps: YYYY-MM-DDTHH:MM:SSZ.', 'wordpress-tracking-pixel'), ['status' => 400]);
                }
                $filters[$name] = $value;
            }
        }
        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
            return new WP_Error('wtp_invalid_range', __('from must not be later than to.', 'wordpress-tracking-pixel'), ['status' => 400]);
        }
        $page = $request->has_param('page') ? $request['page'] : 1;
        $perPage = $request->has_param('per_page') ? $request['per_page'] : 50;
        if (filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) === false
            || filter_var($perPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) === false) {
            return new WP_Error('wtp_invalid_pagination', __('page must be 1-1000000; per_page must be 1-100.', 'wordpress-tracking-pixel'), ['status' => 400]);
        }
        try {
            $data = $this->store->report($request['project'], $filters, (int) $page, (int) $perPage);
        } catch (RuntimeException $exception) {
            return new WP_Error('wtp_storage_error', __('Unable to retrieve events.', 'wordpress-tracking-pixel'), ['status' => 500]);
        }
        if (isset($filters['from']) || isset($filters['to'])) {
            $data['date_range'] = [
                'from' => isset($filters['from']) ? str_replace(' ', 'T', $filters['from']) . 'Z' : null,
                'to' => isset($filters['to']) ? str_replace(' ', 'T', $filters['to']) . 'Z' : null,
            ];
        }
        if (str_ends_with($request->get_route(), '/summary')) {
            unset($data['events'], $data['page'], $data['per_page']);
        }
        $response = new WP_REST_Response($data);
        $response->header('Cache-Control', 'no-store');
        $response->header('X-WP-Total', (string) $data['count']);
        $response->header('X-WP-TotalPages', (string) ceil($data['count'] / (int) $perPage));
        return $response;
    }
}