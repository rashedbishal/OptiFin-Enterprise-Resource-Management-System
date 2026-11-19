import { defaultCsrfHeaders } from './softphone/api.js';

/**
 * Admin → Calling app: uploads the OptiFin Calling APK in small chunks
 * (CallingAppApkUploadController: start → PUT each chunk → complete) so a
 * ~90 MB file never hits a server's PHP/nginx request-size limit. Each chunk
 * is retried a few times before giving up; the server stores chunks
 * idempotently, so a retry can never corrupt the file.
 */
export async function uploadApkInChunks({
    file,
    version,
    notes,
    urls,
    onProgress = () => {},
    fetchImpl = (...args) => fetch(...args),
    csrfHeaders = () => defaultCsrfHeaders(),
    maxAttempts = 3,
}) {
    const request = async (method, url, body, contentType) => {
        const response = await fetchImpl(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(contentType ? { 'Content-Type': contentType } : {}),
                ...csrfHeaders(),
            },
            body,
        });
        const payload = await response.json().catch(() => null);

        if (!response.ok) {
            const message = payload?.error?.message
                ?? Object.values(payload?.errors ?? {})[0]?.[0]
                ?? `Upload failed (${response.status}).`;
            const error = new Error(message);
            error.retryable = response.status >= 500 || response.status === 0;
            throw error;
        }

        return payload?.data ?? {};
    };

    const started = await request('POST', urls.start, JSON.stringify({ size: file.size, version, notes: notes || null }), 'application/json');
    const uploadId = started.upload_id;
    const chunkBytes = started.chunk_bytes;
    const total = Math.ceil(file.size / chunkBytes);

    for (let index = 0; index < total; index++) {
        const chunk = file.slice(index * chunkBytes, Math.min(file.size, (index + 1) * chunkBytes));
        const url = urls.chunk.replace('__UPLOAD__', uploadId).replace('__INDEX__', String(index));

        for (let attempt = 1; ; attempt++) {
            try {
                await request('PUT', url, chunk, 'application/octet-stream');
                break;
            } catch (error) {
                if (attempt >= maxAttempts || error.retryable === false) {
                    throw error;
                }
            }
        }

        onProgress(Math.round(((index + 1) / total) * 100));
    }

    return request('POST', urls.complete.replace('__UPLOAD__', uploadId));
}

export function registerApkUploader(alpine) {
    alpine.data('callingAppApkUploader', (config) => ({
        file: null,
        version: '',
        notes: '',
        progress: 0,
        busy: false,
        error: null,

        pick(event) {
            this.file = event.target.files?.[0] ?? null;
            this.error = null;

            // "app-release-1.3.0.apk" / "OptiFin-Calling-1.3.0.apk" → 1.3.0
            const guessed = this.file?.name.match(/(\d+\.\d+(?:\.\d+){0,2})/)?.[1];

            if (guessed && !this.version) {
                this.version = guessed;
            }
        },

        async upload() {
            if (this.busy) {
                return;
            }

            if (!this.file || !this.version) {
                this.error = config.texts?.missing ?? 'Choose the .apk file and enter its version.';

                return;
            }

            this.busy = true;
            this.error = null;
            this.progress = 0;

            try {
                await uploadApkInChunks({
                    file: this.file,
                    version: this.version.trim(),
                    notes: this.notes.trim(),
                    urls: config.urls,
                    onProgress: (percent) => { this.progress = percent; },
                });

                this.file = null;
                this.version = '';
                this.notes = '';
                this.$refs.apkFile.value = '';
                await this.$wire.apkUploaded();
            } catch (error) {
                this.error = error?.message ?? 'Upload failed.';
            } finally {
                this.busy = false;
            }
        },
    }));
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => registerApkUploader(window.Alpine));
}
