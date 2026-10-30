<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/Identifiers.php';
require dirname(__DIR__) . '/includes/Api.php';

use Metalleg\TrackingPixel\Identifiers;
use Metalleg\TrackingPixel\Api;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$check(Identifiers::validExternal(str_repeat('A', 128)), 'Accept exactly 128 ASCII alphanumeric characters.');
foreach (['', str_repeat('A', 127), str_repeat('A', 129), str_repeat('A', 127) . '-', str_repeat('A', 128) . "\n", null, [], 128] as $invalid) {
    $check(!Identifiers::validExternal($invalid), 'Reject malformed external IDs.');
}
$generated = [];
for ($iteration = 0; $iteration < 100; $iteration++) {
    $project = Identifiers::random(10);
    $external = Identifiers::random(128);
    $token = Identifiers::random(64);
    $check(Identifiers::validProject($project), 'Generate valid project ID.');
    $check(Identifiers::validExternal($external), 'Generate valid external ID.');
    $check(Identifiers::validToken($token), 'Generate valid pixel token.');
    $check(!isset($generated[$external]), 'Generated IDs do not repeat in sample.');
    $generated[$external] = true;
}
$check(Api::date('2024-02-29T23:59:59Z') === '2024-02-29 23:59:59', 'Accept valid leap day in UTC.');
foreach (['2025-02-29T00:00:00Z', '2026-13-01T00:00:00Z', '2026-10-30', '2026-10-30T00:00:00+00:00', null, [], '2026-10-30T24:00:00Z'] as $invalidDate) {
    $check(Api::date($invalidDate) === null, 'Reject invalid dates and non-UTC formats.');
}
echo "Passed {$checks} checks.\n";