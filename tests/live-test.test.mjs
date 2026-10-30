import test from 'node:test';
import assert from 'node:assert/strict';
import { testLiveApi } from '../tools/live-test.mjs';

const configuration = {
    WTP_LIVE_API_URL: 'https://example.test/wp-json/tracking-pixel/v1',
    WTP_LIVE_PROJECT_ID: 'A'.repeat(10),
    WTP_LIVE_API_KEY: `wtp_${'B'.repeat(16)}.${'C'.repeat(64)}`,
};

function fixture() {
    const requests = [];
    const messages = new Map();
    const generated = 'D'.repeat(128);
    const tracking = `https://example.test/?wtp_pixel=${'E'.repeat(64)}`;
    let events = 0;
    const json = (body, status = 200) => Response.json(body, { status });
    const transport = async (target, options) => {
        const url = new URL(target);
        requests.push({ url, options });
        if (url.searchParams.has('wtp_pixel')) {
            assert.equal(options.headers.Authorization, undefined);
            if (options.method === 'GET') events++;
            return new Response(options.method === 'HEAD' ? null : Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64'), {
                headers: { 'Content-Type': 'image/gif', 'Cache-Control': 'no-store' },
            });
        }
        if (!options.headers.Authorization) return json({ code: 'unauthorized' }, 401);
        assert.equal(options.headers.Authorization, `Bearer ${configuration.WTP_LIVE_API_KEY}`);
        const resource = (url.searchParams.get('rest_route') ?? url.pathname).split('/').pop();
        if (resource === 'messages') {
            const body = JSON.parse(options.body);
            if (body.external_id === '') return json({ code: 'invalid_id' }, 400);
            const id = body.external_id ?? generated;
            if (!messages.has(id)) messages.set(id, { project_id: configuration.WTP_LIVE_PROJECT_ID, external_id: id, tracking_url: tracking });
            return json(messages.get(id));
        }
        const id = url.searchParams.get('external_id');
        const count = id && id !== generated ? 0 : events;
        const summary = { project_id: configuration.WTP_LIVE_PROJECT_ID, count, first_request: count ? '2026-01-01T00:00:00Z' : null, latest_request: count ? '2026-01-01T00:00:01Z' : null };
        return json(resource === 'events' ? { ...summary, events: [{ id: url.searchParams.get('page') === '2' ? 1 : 2, external_id: id }] } : summary);
    };
    return { requests, transport };
}

test('default mode performs only read-only requests', async () => {
    const { requests, transport } = fixture();
    const output = [];
    const result = await testLiveApi(configuration, transport, (line) => output.push(line));
    assert.equal(result.createdMessages, 0);
    assert.equal(requests.length, 2);
    assert.ok(requests.every(({ options }) => options.method === 'GET'));
    assert.ok(!output.join('\n').includes(configuration.WTP_LIVE_API_KEY));
});

test('write mode verifies registration, pixels, and reports without logging sensitive values', async () => {
    const { transport } = fixture();
    const output = [];
    const result = await testLiveApi({ ...configuration, WTP_LIVE_ALLOW_WRITES: '1' }, transport, (line) => output.push(line));
    assert.equal(result.createdMessages, 2);
    assert.equal(result.recordedPixelRequests, 2);
    for (const value of [...Object.values(configuration), 'D'.repeat(128), 'wtp_pixel=']) {
        assert.ok(!output.join('\n').includes(value));
    }
});

test('plain permalink API namespaces are supported', async () => {
    const { requests, transport } = fixture();
    await testLiveApi({ ...configuration, WTP_LIVE_API_URL: 'https://example.test/?rest_route=/tracking-pixel/v1' }, transport, () => {});
    assert.ok(requests.every(({ url }) => url.searchParams.get('rest_route').endsWith('/summary')));
});

test('unsafe configuration is rejected before making requests', async () => {
    let requests = 0;
    const transport = async () => { requests++; };
    for (const url of ['http://example.test/api', 'https://user:password@example.test/api', 'https://example.test/api?key=value', 'invalid']) {
        await assert.rejects(testLiveApi({ ...configuration, WTP_LIVE_API_URL: url }, transport, () => {}));
    }
    await assert.rejects(testLiveApi({ ...configuration, WTP_LIVE_PROJECT_ID: '' }, transport, () => {}));
    await assert.rejects(testLiveApi({ ...configuration, WTP_LIVE_API_KEY: '' }, transport, () => {}));
    assert.equal(requests, 0);
});

test('redirects are never followed', async () => {
    let requests = 0;
    await assert.rejects(testLiveApi(configuration, async (url, options) => {
        requests++;
        assert.equal(options.redirect, 'manual');
        return new Response(null, { status: 302, headers: { Location: 'https://other.example.test/' } });
    }, () => {}), /redirect/);
    assert.equal(requests, 1);
});

test('tracking URLs cannot send requests outside the authorized origin', async () => {
    for (const target of ['https://other.example.test/pixel', 'http://example.test/pixel', 'https://user:password@example.test/pixel', `https://example.test/pixel?key=${configuration.WTP_LIVE_API_KEY}`]) {
        const { requests, transport } = fixture();
        const modified = async (url, options) => {
            const response = await transport(url, options);
            if (options.method === 'POST' && response.status === 200) {
                const body = await response.json();
                body.tracking_url = target;
                return Response.json(body);
            }
            return response;
        };
        await assert.rejects(testLiveApi({ ...configuration, WTP_LIVE_ALLOW_WRITES: '1' }, modified, () => {}), /Refusing/);
        assert.ok(!requests.some(({ url }) => url.toString() === target));
    }
});

test('network errors never echo configuration values', async () => {
    await assert.rejects(testLiveApi(configuration, async () => { throw new Error(configuration.WTP_LIVE_API_KEY); }, () => {}), (error) => {
        assert.equal(error.message, 'Network or TLS request failed.');
        return true;
    });
});