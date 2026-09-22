/**
 * WhatsApp inbound calling — the softphone's thin client for the Step 4 call
 * endpoints and the active-calls read. It never sees a credential: the browser
 * only ever sends the call id (in the URL) and, for `answer`, its own SDP answer.
 * Responses are normalized into `{ data }` or a thrown ApiError; Meta's wording
 * never reaches here (the server already replaces it with fixed text).
 */

export class ApiError extends Error {
    constructor({ status = 0, code = 'error', message = 'Something went wrong.', retryable = false } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.retryable = retryable;
    }
}

/**
 * The CSRF token Livewire already puts on its own script tag, falling back to
 * Laravel's XSRF-TOKEN cookie. No layout change is needed for either.
 */
export function defaultCsrfHeaders(doc = document) {
    const token = doc.querySelector('script[data-csrf]')?.getAttribute('data-csrf');

    if (token) {
        return { 'X-CSRF-TOKEN': token };
    }

    const cookie = (doc.cookie || '').split('; ').find((part) => part.startsWith('XSRF-TOKEN='));

    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) } : {};
}

export function createApi({ urls, fetchImpl = (...args) => fetch(...args), csrfHeaders = defaultCsrfHeaders } = {}) {
    const forCall = (template, id) => template.replace('__CALL__', encodeURIComponent(id));

    async function request(method, url, body) {
        let response;

        try {
            response = await fetchImpl(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
                    ...csrfHeaders(),
                },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
        } catch {
            throw new ApiError({ status: 0, code: 'network', message: 'Could not reach the server.', retryable: true });
        }

        const payload = await response.json().catch(() => null);

        if (response.ok) {
            return payload?.data ?? {};
        }

        const error = payload?.error;

        if (error) {
            throw new ApiError({ status: response.status, code: error.code, message: error.message, retryable: error.retryable === true });
        }

        if (response.status === 401 || response.status === 419) {
            throw new ApiError({ status: response.status, code: 'session_expired', message: 'Your session has expired. Reload the page.', retryable: false });
        }

        throw new ApiError({ status: response.status, code: 'error', message: 'Something went wrong.', retryable: response.status >= 500 });
    }

    return {
        async active() {
            const data = await request('GET', urls.active);

            return Array.isArray(data.calls) ? data.calls : [];
        },
        claim: (id) => request('POST', forCall(urls.claim, id)),
        answer: (id, sdp) => request('POST', forCall(urls.answer, id), { sdp_type: 'answer', sdp }),
        accept: (id) => request('POST', forCall(urls.accept, id)),
        reject: (id) => request('POST', forCall(urls.reject, id)),
        hangup: (id) => request('POST', forCall(urls.hangup, id)),
    };
}
