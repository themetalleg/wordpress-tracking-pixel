import { randomBytes } from 'node:crypto';
import { pathToFileURL } from 'node:url';

export async function testLiveApi(environment = process.env, transport = globalThis.fetch, report = console.log) {
    let api;
    try {
        api = new URL(environment.WTP_LIVE_API_URL);
    } catch {
        throw new Error('Set WTP_LIVE_API_URL to the HTTPS API namespace URL.');
    }
    if (api.protocol !== 'https:' || api.username || api.password || api.hash
        || [...api.searchParams.keys()].some((name) => name !== 'rest_route')) {
        throw new Error('Use an HTTPS API URL without credentials or unrelated query parameters.');
    }
    const project = environment.WTP_LIVE_PROJECT_ID;
    const key = environment.WTP_LIVE_API_KEY;
    if (!/^[A-Za-z0-9]{10}$/.test(project ?? '')) {
        throw new Error('Set WTP_LIVE_PROJECT_ID to a 10-character alphanumeric project ID.');
    }
    if (!/^wtp_[A-Za-z0-9]{16}\.[A-Za-z0-9]{64}$/.test(key ?? '')) {
        throw new Error('Set WTP_LIVE_API_KEY to a valid project API key.');
    }
    const writes = environment.WTP_LIVE_ALLOW_WRITES === '1';
    let checks = 0;
    const check = (condition, message) => {
        if (!condition) throw new Error(message);
        checks++;
        report(`PASS ${message}`);
    };
    const route = (resource, query = {}) => {
        const url = new URL(api);
        const suffix = `/projects/${project}/${resource}`;
        if (url.searchParams.has('rest_route')) {
            url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + suffix);
        } else {
            url.pathname = url.pathname.replace(/\/$/, '') + suffix;
        }
        for (const [name, value] of Object.entries(query)) url.searchParams.set(name, value);
        return url;
    };
    const request = async (target, { method = 'GET', body, authenticated = true } = {}) => {
        let url;
        try {
            url = new URL(target);
        } catch {
            throw new Error('The server returned an invalid tracking URL.');
        }
        if (url.origin !== api.origin || url.username || url.password || url.href.includes(key)) {
            throw new Error('Refusing to contact another origin or a URL containing credentials.');
        }
        const headers = { Accept: 'application/json' };
        if (authenticated) headers.Authorization = `Bearer ${key}`;
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        let response;
        try {
            response = await transport(url, {
                method, headers, body: body === undefined ? undefined : JSON.stringify(body),
                redirect: 'manual', signal: AbortSignal.timeout(30000),
            });
        } catch {
            throw new Error('Network or TLS request failed.');
        }
        if (response.status >= 300 && response.status < 400) {
            throw new Error('Unexpected redirect; credentials were not forwarded.');
        }
        return response;
    };
    const json = async (response) => {
        try {
            return await response.json();
        } catch {
            throw new Error('Expected a JSON API response.');
        }
    };
    check((await request(route('summary'), { authenticated: false })).status === 401, 'Anonymous reporting is rejected.');
    const initialResponse = await request(route('summary'));
    const initial = await json(initialResponse);
    check(initialResponse.status === 200 && initial.project_id === project && Number.isInteger(initial.count), 'Authenticated reporting succeeds.');
    if (writes) {
        const registration = await request(route('messages'), { method: 'POST', body: {} });
        const message = await json(registration);
        check(registration.status === 200 && message.project_id === project && /^[A-Za-z0-9]{128}$/.test(message.external_id), 'Registration returns a generated email ID.');
        const retry = await request(route('messages'), { method: 'POST', body: { external_id: message.external_id } });
        check(retry.status === 200 && (await json(retry)).tracking_url === message.tracking_url, 'Registration is idempotent.');
        const suppliedId = randomBytes(64).toString('hex');
        const supplied = await request(route('messages'), { method: 'POST', body: { external_id: suppliedId } });
        check(supplied.status === 200 && (await json(supplied)).external_id === suppliedId, 'Sender-supplied email ID is accepted.');
        const invalid = await request(route('messages'), { method: 'POST', body: { external_id: '' } });
        check(invalid.status === 400, 'Invalid email ID is rejected.');
        for (let opening = 0; opening < 2; opening++) {
            const pixel = await request(message.tracking_url, { authenticated: false });
            const image = Buffer.from(await pixel.arrayBuffer());
            check(pixel.status === 200 && pixel.headers.get('content-type')?.includes('image/gif')
                && image.length >= 10 && image.subarray(0, 6).toString() === 'GIF89a'
                && image.readUInt16LE(6) === 1 && image.readUInt16LE(8) === 1, 'Pixel returns a 1x1 GIF.');
            check(pixel.headers.get('cache-control')?.includes('no-store'), 'Pixel response disables caching.');
        }
        check((await request(message.tracking_url, { method: 'HEAD', authenticated: false })).status === 200, 'Pixel accepts HEAD requests.');
        const eventsResponse = await request(route('events', { external_id: message.external_id, per_page: '1' }));
        const events = await json(eventsResponse);
        check(eventsResponse.status === 200 && events.count === 2 && events.events.length === 1
            && events.events[0].external_id === message.external_id, 'Reports record repeat GETs, exclude HEAD, and paginate.');
        const next = await json(await request(route('events', { external_id: message.external_id, per_page: '1', page: '2' })));
        check(next.events.length === 1 && next.events[0].id !== events.events[0].id, 'Next page returns the other event.');
        const summary = await json(await request(route('summary', { external_id: message.external_id })));
        check(summary.count === 2 && summary.first_request && summary.latest_request, 'Summary includes total and first/latest timestamps.');
        const unopened = await json(await request(route('summary', { external_id: suppliedId })));
        check(unopened.count === 0, 'Unopened message has separate history.');
    }
    const result = { checks, createdMessages: writes ? 2 : 0, recordedPixelRequests: writes ? 2 : 0 };
    report(`Completed ${checks} checks; created ${result.createdMessages} messages and ${result.recordedPixelRequests} pixel events.`);
    return result;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    testLiveApi().catch((error) => {
        console.error(`Live API test failed: ${error.message}`);
        process.exitCode = 1;
    });
}