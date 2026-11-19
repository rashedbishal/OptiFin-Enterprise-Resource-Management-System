import { describe, expect, it, vi } from 'vitest';
import { uploadApkInChunks } from './calling-app-apk-upload.js';

const urls = { start: '/start', chunk: '/u/__UPLOAD__/chunks/__INDEX__', complete: '/u/__UPLOAD__/complete' };

const ok = (data) => ({ ok: true, status: 200, json: async () => ({ data }) });
const fail = (status, message) => ({ ok: false, status, json: async () => ({ error: { message } }) });

describe('uploadApkInChunks', () => {
    it('starts, sends every chunk in order, then completes', async () => {
        const file = new Blob([new Uint8Array(25)]);
        const fetchImpl = vi.fn(async (url) => {
            if (url === '/start') {
                return ok({ upload_id: 'up1', chunk_bytes: 10 });
            }

            return ok(url.endsWith('/complete') ? { version: '1.3.0' } : { ok: true });
        });
        const progress = [];

        const result = await uploadApkInChunks({ file, version: '1.3.0', notes: '', urls, fetchImpl, csrfHeaders: () => ({}), onProgress: (p) => progress.push(p) });

        expect(fetchImpl.mock.calls.map(([url, init]) => `${init.method} ${url}`)).toEqual([
            'POST /start',
            'PUT /u/up1/chunks/0',
            'PUT /u/up1/chunks/1',
            'PUT /u/up1/chunks/2',
            'POST /u/up1/complete',
        ]);
        expect(fetchImpl.mock.calls.slice(1, 4).map(([, init]) => init.body.size)).toEqual([10, 10, 5]);
        expect(progress).toEqual([33, 67, 100]);
        expect(result).toEqual({ version: '1.3.0' });
    });

    it('retries a chunk that failed with a server error', async () => {
        let attempts = 0;
        const fetchImpl = vi.fn(async (url) => {
            if (url === '/start') {
                return ok({ upload_id: 'up1', chunk_bytes: 10 });
            }

            if (url.includes('/chunks/') && ++attempts === 1) {
                return fail(502, 'Bad gateway');
            }

            return ok({});
        });

        await uploadApkInChunks({ file: new Blob([new Uint8Array(5)]), version: '1.3.0', urls, fetchImpl, csrfHeaders: () => ({}) });

        expect(attempts).toBe(2);
    });

    it('stops with the server\'s message when a chunk is refused', async () => {
        const fetchImpl = vi.fn(async (url) => (url === '/start' ? ok({ upload_id: 'up1', chunk_bytes: 10 }) : fail(422, 'Chunk 1 arrived damaged')));

        await expect(uploadApkInChunks({ file: new Blob([new Uint8Array(5)]), version: '1.3.0', urls, fetchImpl, csrfHeaders: () => ({}) }))
            .rejects.toThrow('Chunk 1 arrived damaged');
        expect(fetchImpl).toHaveBeenCalledTimes(2);
    });
});
