# WordPress Tracking Pixel

A WordPress plugin for tracking image requests from externally sent HTML emails.
Projects have separate API keys, permanent tracking links, and configurable event
retention. The plugin does not send emails or track clicks.

## Requirements and Installation

- PHP 8.2 or newer; WordPress 6.6 or newer is the initial compatibility target.
- Single-site activation only; network-wide activation is not supported.
- HTTPS for production API credentials.

Install the **wordpress-tracking-pixel.zip** asset from a stable GitHub Release
through WordPress's plugin uploader. Do not install GitHub's source archive: it
does not contain Composer dependencies. Open **Tracking Pixel > Setup / Tutorial**
for complete integration instructions and examples using your site's API URL.

Source installations need `composer install --no-dev`. The bundled updater checks
public GitHub Releases periodically and integrates with WordPress's update UI.
Automatic installation follows the site's automatic-update settings.

## Message Registration

The Projects overview is searchable by name or ID and paginated at 20 projects
per page. It shows retention, registered message counts, and active key counts.
Open a project to use its **Settings** or **Events** tab. Settings contain key
management and retention; Events contain filters, request totals, and history.

Create a project and issue a read/write key in WordPress admin. Project IDs have
exactly 10 case-sensitive ASCII alphanumeric characters and are not credentials.
Keys are project-scoped, individually revocable, and shown only on issuance.
Issue a new key and revoke the old one to rotate credentials. Read-only keys can
retrieve reports but cannot register messages.

```sh
curl --request POST 'https://example.com/wp-json/tracking-pixel/v1/projects/PROJECT_ID/messages' \
  --header 'Authorization: Bearer YOUR_API_KEY' \
  --header 'Content-Type: application/json' \
  --data '{}'
```

The JSON response contains `project_id`, `external_id`, `tracking_url`, and `html`.
Insert the returned `html` into that message's HTML body:

```html
<img src="RETURNED_TRACKING_URL" width="1" height="1" alt="" style="border:0" />
```

Optionally supply `{"external_id":"YOUR_128_CHARACTER_ALPHANUMERIC_ID"}` (replace
the placeholder with a valid ID). IDs must be exactly **128 characters** using
`A-Z`, `a-z`, `0-9`, are case-sensitive, and unique per project. Empty, null, and
malformed supplied IDs are rejected; only omission generates a new ID.

Persist one ID per message/recipient delivery. Re-registering the same ID in the
same project returns the existing tracking URL without resetting history. Each
registration with an omitted ID creates a new message. For idempotent retries
after a lost response, generate and persist your own ID before registration.
Never include API credentials or recipient addresses in email URLs.

### Read-only token resolver

The source now includes a project-scoped resolver for senders that need to map
an existing pixel token back to its external message ID:

```text
POST /wp-json/tracking-pixel/v1/projects/{project}/messages/resolve
Authorization: Bearer <project-scoped-key>
Content-Type: application/json

{"token":"<64-character-pixel-token>"}
```

The endpoint accepts read-capable keys, requires HTTPS outside local
development, returns only `project_id` and `external_id`, and uses
`Cache-Control: no-store`. Unknown tokens return HTTP 404 with code
`wtp_message_not_found`. It does not register messages or record opens. This
route is included in v0.3.0; v0.2.0 and earlier do not provide it.

## Reporting API

Send the same Bearer header for:

- `GET /wp-json/tracking-pixel/v1/projects/{project}/events`
- `GET /wp-json/tracking-pixel/v1/projects/{project}/summary`

Both accept optional `external_id`, `from`, `to`, and `last_days`. Dates must be valid UTC
timestamps such as `2026-10-30T00:00:00Z`; boundaries are inclusive. Events accept
`page` (1-1000000, default 1) and `per_page` (1-100, default 50).

```json
{
  "project_id": "Ab3dE7fG9h",
  "count": 2,
  "first_request": "2026-10-30T08:00:00Z",
  "latest_request": "2026-10-30T09:00:00Z"
}
```

Events also include `page`, `per_page`, and `events`, each with `id`, `external_id`,
and `requested_at`. Results are newest first, ordered by timestamp then event ID.
`X-WP-Total` and `X-WP-TotalPages` expose pagination totals. Offset pagination can
shift under incoming traffic; reconcile by event ID. A message without events
has count 0 and null first/latest timestamps. Reports do not expose pixel tokens.

Omit `external_id` to query events across the entire project. There is no
unbounded all-events response: use `per_page=100` and fetch every page using
`X-WP-TotalPages`. `/summary` returns the count and first/latest timestamps for
the whole matching project/time range in one response. It does not list unopened
messages or export project configuration and credentials.

Use the shortcut for a rolling five-day window, across the entire project or
combined with `external_id` for one email:

```text
GET /wp-json/tracking-pixel/v1/projects/PROJECT_ID/events?last_days=5&per_page=100&page=1
GET /wp-json/tracking-pixel/v1/projects/PROJECT_ID/summary?last_days=5
```

`last_days` accepts a whole number from **1 to 36500**. It means the preceding
N x 24 hours in UTC, ending at request time, not local calendar days. Combining
it with `from` or `to` returns HTTP 400. Retention rules still apply.

Time-filtered responses include `date_range.from` and `date_range.to` as ISO UTC
timestamps; an unspecified explicit boundary is null. For consistent pagination,
take the resolved boundaries from the first `last_days` response and request
later pages with **from/to instead of last_days**. Otherwise the relative window
is recalculated on each request. Events remain newest first, so reconcile by
event ID if new records arrive inside the selected window.

An explicit fixed five-day example, using placeholders only:

```text
GET /wp-json/tracking-pixel/v1/projects/PROJECT_ID/events?from=2026-01-01T12:00:00Z&to=2026-01-06T12:00:00Z&per_page=100&page=1
```

The WordPress Events table displays dates using **Settings > General > Date
Format, Time Format, and Timezone**. Its native date/time pickers use the site
timezone; API timestamps and API filters remain ISO UTC. Admin event pagination
supports 10, 25, 50 (default), or 100 rows per page, with range/totals and navigation
above and below the table. Filters and page size persist while paging.

Errors use WordPress REST error JSON: 400 for invalid input, 401 for invalid or
insufficient project credentials, 403 for production HTTP, and 500 for storage
failures. Unrecognized routes use WordPress's standard 404 response.

## Retention and Privacy

Every valid pixel GET request records an event, including repeated requests.
HEAD requests are not recorded. No email addresses, IP addresses, or user agents
are stored; the plugin sets no tracking cookies. API secrets are stored as hashes.

Projects default to **indefinite** event history. **Rolling 90 days** excludes old
events from reports immediately and deletes them through hourly WP-Cron cleanup.
Message IDs and pixel tokens remain indefinitely so old emails still work.
Switching back to indefinite cannot restore deleted events. Data remains after
deactivation/uninstall. Explicit project deletion permanently removes its data.

WP-Cron depends on traffic. A strict physical deletion deadline requires server
cron, for example `wp cron event run --due-now`. Backups need their own policy.
Pseudonymous identifiers do not remove privacy/consent obligations. Web server,
hosting, and CDN logs may independently record personal information.

**These are pixel requests, not verified human opens.** Apple Mail Privacy
Protection, image proxies, security scanners, image blocking, and caching can
inflate or suppress observations. Plain-text emails cannot load tracking pixels.

Exclude the `wtp_pixel` query parameter and authenticated API requests from all
page/CDN/reverse-proxy caches. Forward Authorization headers to PHP. Production
installations require HTTPS; HTTP credentials are allowed only when WordPress's
environment type is explicitly `local`. Do not use that mode in production.
Apply request/connection limits at your hosting or reverse proxy for abuse control;
per-IP plugin throttling is deliberately avoided because it would store addresses
and could discard legitimate requests from shared email proxies.

## Languages

English is the source/fallback language; German (`de_DE`) is bundled, including
the admin pages, setup tutorial, confirmations, and error messages. WordPress
selects the admin language from **Users > Profile > Language**, falling back to
**Settings > General > Site Language**. Install/select German in WordPress to use
it. API field names, error codes, identifiers, examples, and UTC timestamp formats
remain unchanged; API error text follows the request's WordPress locale.

German variants such as `de_DE_formal` (Deutsch Sie), `de_AT`, `de_CH`, and
`de_CH_informal` fall back to the bundled German catalog if their exact catalog
is unavailable. Existing exact `.mo` or `.l10n.php` translations take priority.
Only this plugin's catalog is affected; the WordPress locale is not changed.
If the plugin appears in English on a German site, check the profile language:
an explicitly English profile overrides the site language. Use German or the
site default, and ensure the installed ZIP includes the languages directory.

To add another language, translate the POT template using Poedit, Loco Translate,
or another gettext editor. Save `wordpress-tracking-pixel-LOCALE.po` and compile
the matching `.mo` in the languages directory. No PHP changes are needed. English
fallback does not need its own catalog. Regional/formal German catalogs can be
added separately to override the shared German fallback.

Regenerate catalogs after source changes:

```sh
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app wordpress:cli-php8.2 wp i18n make-pot . languages/wordpress-tracking-pixel.pot --domain=wordpress-tracking-pixel --exclude=vendor,dist,tests --skip-audit
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app wordpress:cli-php8.2 wp i18n make-mo languages
```

## Development

Native PHP and Composer:

```sh
composer install
composer lint
composer test
```

With Docker instead:

```sh
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app composer:2 install --no-interaction
docker run --rm -v "$PWD:/app:ro" -w /app php:8.2-cli sh tests/lint.sh
docker run --rm -v "$PWD:/app:ro" -w /app php:8.2-cli php tests/unit.php
docker compose up -d --wait wordpress
docker compose run --rm cli core install --url=http://localhost:8098 --title='Pixel Development' --admin_user=localadmin --admin_password=local-test-password --admin_email=local@example.test --skip-email
docker compose run --rm cli plugin activate wordpress-tracking-pixel
docker compose run --rm cli eval-file wp-content/plugins/wordpress-tracking-pixel/tests/integration.php
docker compose run --rm cli eval-file wp-content/plugins/wordpress-tracking-pixel/tests/admin.php
docker compose run --rm cli language core install de_DE
docker compose run --rm cli eval-file wp-content/plugins/wordpress-tracking-pixel/tests/i18n.php
```

Local site: http://localhost:8098. Credentials above are **local development only**;
the port binds to loopback. Set `WTP_PORT` to choose another port and use that port
in the install URL. Set `WORDPRESS_IMAGE` to test another WordPress image. The
plugin source is read-only in the container; test real updates on a separate
staging installation, not this bind-mounted development copy.

Initial local verification (2026-10-30): 417 unit checks and 61 integration checks
on WordPress 7.1.4 / PHP 8.2.35, plus the same integration checks on WordPress
6.6.2 / PHP 8.2.35. The ZIP-installed plugin also passes the 61 integration checks.
Admin workflows and desktop/mobile field sizing were checked in a browser.
Hosted CI and a live GitHub release-to-release update remain release gates.

The integration test creates isolated fixture projects and deletes them on exit.
It also creates a temporary administrator, logs in over HTTP, submits the real
project and API-key forms with WordPress nonces, and exercises the external
Bearer-authenticated API, including project-isolated token resolution. The flow covers generated/supplied email IDs,
idempotent registration, repeated pixel GETs, HEAD exclusion, paginated events,
summaries, API-key revocation, and project deletion. Temporary accounts and
projects are removed in a finally block, including after failed assertions.
This flow is part of the existing integration command and both WordPress CI jobs.
Use a disposable test site. `docker compose down` stops it while preserving data;
`docker compose down -v` additionally destroys the development database and files.

## Live API Smoke Test

The optional [tools/live-test.mjs](tools/live-test.mjs) uses Node.js 22+ and reads
configuration only from environment variables. It never writes credentials,
responses, message IDs, or tracking URLs to files, and does not print them.
Only test sites/projects you are authorized to access. Never put real live-site
credentials, hostnames, project IDs, or response data in source, tests, docs,
planning files, or committed configuration. Keep `.env` files out of this project.

In Bash, collect configuration interactively so the key is not embedded in shell
commands or saved in shell history:

```sh
read -r -p 'HTTPS API namespace URL: ' WTP_LIVE_API_URL
read -r -p 'Project ID: ' WTP_LIVE_PROJECT_ID
read -r -s -p 'Project API-Key: ' WTP_LIVE_API_KEY
printf '\n'
export WTP_LIVE_API_URL WTP_LIVE_PROJECT_ID WTP_LIVE_API_KEY
node tools/live-test.mjs
```

For example, the namespace URL format is
`https://example.com/wp-json/tracking-pixel/v1`; plain-permalink URLs such as
`https://example.com/?rest_route=/tracking-pixel/v1` also work.

The default test is **read-only**: it verifies anonymous access is rejected and
authenticated summary retrieval works. For a dedicated disposable project, opt
into the complete flow:

```sh
WTP_LIVE_ALLOW_WRITES=1 node tools/live-test.mjs
unset WTP_LIVE_API_URL WTP_LIVE_PROJECT_ID WTP_LIVE_API_KEY
```

Write mode creates **two messages and two pixel events** and leaves them in the
project for inspection. It checks generated/supplied IDs, idempotent registration,
validation, repeat pixel requests, HEAD exclusion, pagination, and summaries.
The tester does not delete projects or revoke keys. Revoke a disposable key after
testing. HTTPS is required; redirects are not followed and credentials are never
forwarded to another origin. Server logs still follow the hosting provider's policy.

The tester's automated checks use synthetic data only and make no live requests:

```sh
node --test tests/live-test.test.mjs
```

## Releases

1. Update the main plugin Version header, `WTP_VERSION`, and the readme stable tag.
2. Run tests and verify installation/migration on a staging site.
3. Publish a GitHub Release tagged `vX.Y.Z`; the release workflow builds and attaches
   `wordpress-tracking-pixel.zip` with runtime dependencies. No per-site action is
   needed for detection. WordPress's normal check schedule still applies.

Local packaging after dependency installation: `sh tools/build.sh`. Only explicit
runtime files are included; local plans, tests, Git metadata, and Docker setup are
excluded. Verify an actual version-to-version update on staging before declaring
a production release tested. Public update checks contact GitHub; its API limits
and service availability apply. No GitHub token is shipped to installations.

## License

GPL-2.0-or-later. Bundled Plugin Update Checker retains its MIT license.