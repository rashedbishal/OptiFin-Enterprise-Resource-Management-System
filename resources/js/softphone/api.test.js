import { describe, expect, it, vi } from 'vitest';
import { ApiError, createApi, defaultCsrfHeaders } from './api.js';

const urls = {
    active: '/lead-management/calls/active',
    claim: '/lead-management/calls/__CALL__/claim',
    answer: '/lead-management/calls/__CALL__/answer',
    accept: '/lead-management/calls/__CALL__/accept',
    reject: '/lead-management/calls/__CALL__/reject',
};

const jsonResponse = (status, body) => ({ ok: status >= 200 && status < 300, status, json: async () => body });

describe('softphone api', () => {
    it('sends only the call id in the URL and the browser answer as the body', async () => {
        const fetchImpl = vi.fn(async () => jsonResponse(200, { data: {} }));
        const api = createApi({ urls, fetchImpl, csrfHeaders: () => ({ 'X-CSRF-TOKEN': 't' }) });

        await api.claim('01ABC');
        await api.answer('01ABC', 'v=0 sdp');
        await api.accept('01ABC');
        await api.reject('01ABC');

        const calls = fetchImpl.mock.calls.map(([url, init]) => [init.method, url, init.body]);
        expect(calls).toEqual([
            ['POST', '/lead-management/calls/01ABC/claim', undefined],
            ['POST', '/lead-management/calls/01ABC/answer', JSON.stringify({ sdp_type: 'answer', sdp: 'v=0 sdp' })],
            ['POST', '/lead-management/calls/01ABC/accept', undefined],
            ['POST', '/lead-management/calls/01ABC/reject', undefined],
        ]);
        expect(fetchImpl.mock.calls[1][1].headers).toMatchObject({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 't' });
        expect(fetchImpl.mock.calls[0][1].credentials).toBe('same-origin');
    });

    it('never sends a credential, phone number id, tenant or lead id', async () => {
        const fetchImpl = vi.fn(async () => jsonResponse(200, { data: {} }));
        const api = createApi({ urls, fetchImpl, csrfHeaders: () => ({}) });

        await api.answer('01ABC', 'v=0 sdp');

        const sent = JSON.stringify(fetchImpl.mock.calls[0]);
        expect(sent).not.toMatch(/access_token|app_secret|phone_number_id|tenant_id|lead_id/);
    });

    it('reads the active calls list and tolerates a malformed body', async () => {
        const api = createApi({ urls, fetchImpl: vi.fn(async () => jsonResponse(200, { data: { calls: [{ id: 'a' }] } })), csrfHeaders: () => ({}) });
        expect(await api.active()).toEqual([{ id: 'a' }]);

        const bad = createApi({ urls, fetchImpl: vi.fn(async () => jsonResponse(200, { data: {} })), csrfHeaders: () => ({}) });
        expect(await bad.active()).toEqual([]);
    });

    it('turns the server error structure into an ApiError', async () => {
        const api = createApi({
            urls,
            fetchImpl: vi.fn(async () => jsonResponse(409, { error: { code: 'already_claimed', message: 'Another agent is already answering this call.', retryable: false } })),
            csrfHeaders: () => ({}),
        });

        const error = await api.claim('x').catch((e) => e);

        expect(error).toBeInstanceOf(ApiError);
        expect(error).toMatchObject({ status: 409, code: 'already_claimed', retryable: false, message: 'Another agent is already answering this call.' });
    });

    it('keeps the retryable flag of a retryable server error', async () => {
        const api = createApi({ urls, fetchImpl: vi.fn(async () => jsonResponse(503, { error: { code: 'meta_unavailable', message: 'Try again.', retryable: true } })), csrfHeaders: () => ({}) });

        expect(await api.accept('x').catch((e) => e)).toMatchObject({ code: 'meta_unavailable', retryable: true, status: 503 });
    });

    it('treats a lost connection as a retryable network error', async () => {
        const api = createApi({ urls, fetchImpl: vi.fn(async () => { throw new TypeError('Failed to fetch'); }), csrfHeaders: () => ({}) });

        expect(await api.answer('x', 's').catch((e) => e)).toMatchObject({ code: 'network', retryable: true, status: 0 });
    });

    it('maps an expired session and an unstructured failure safely', async () => {
        const expired = createApi({ urls, fetchImpl: vi.fn(async () => jsonResponse(419, null)), csrfHeaders: () => ({}) });
        expect(await expired.claim('x').catch((e) => e)).toMatchObject({ code: 'session_expired', retryable: false });

        const broken = createApi({ urls, fetchImpl: vi.fn(async () => jsonResponse(500, null)), csrfHeaders: () => ({}) });
        expect(await broken.claim('x').catch((e) => e)).toMatchObject({ code: 'error', retryable: true });
    });

    it('reads the CSRF token Livewire exposes, falling back to the XSRF cookie', () => {
        document.head.innerHTML = '<script data-csrf="livewire-token"></script>';
        expect(defaultCsrfHeaders(document)).toEqual({ 'X-CSRF-TOKEN': 'livewire-token' });

        document.head.innerHTML = '';
        document.cookie = 'XSRF-TOKEN=cookie%20token';
        expect(defaultCsrfHeaders(document)).toEqual({ 'X-XSRF-TOKEN': 'cookie token' });
    });
});
