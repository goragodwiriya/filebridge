// Thin JSON transport. Every mutating call carries the CSRF token.

export const state = {
    csrf: '',
    user: '',
    sites: [],
    settings: {},
    backends: {},
};

class ApiError extends Error {
    constructor(message, code, status) {
        super(message);
        this.name = 'ApiError';
        this.code = code;
        this.status = status;
    }
}

export { ApiError };

export async function call(action, payload = {}) {
    const response = await fetch('api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': state.csrf,
            'X-Requested-With': 'fetch',
        },
        body: JSON.stringify({ action, ...payload }),
        credentials: 'same-origin',
    });

    let body;
    try {
        body = await response.json();
    } catch {
        throw new ApiError('The server returned an unreadable response.', 'bad_json', response.status);
    }

    if (!body.ok) {
        const error = body.error || {};
        throw new ApiError(error.message || 'Request failed.', error.code || 'error', response.status);
    }

    return body.data;
}

/** Multipart POST used by the uploader; reports progress through a callback. */
export function upload(fields, file, onProgress) {
    return new Promise((resolve, reject) => {
        const form = new FormData();
        Object.entries(fields).forEach(([key, value]) => form.append(key, value));
        form.append('file', file);

        const request = new XMLHttpRequest();
        request.open('POST', 'api.php?action=fs.upload', true);
        request.setRequestHeader('X-CSRF-Token', state.csrf);
        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable && onProgress) onProgress(event.loaded, event.total);
        });
        request.addEventListener('load', () => {
            let body;
            try {
                body = JSON.parse(request.responseText);
            } catch {
                reject(new ApiError('Upload response was unreadable.', 'bad_json', request.status));
                return;
            }
            if (!body.ok) {
                reject(new ApiError(body.error?.message || 'Upload failed.', body.error?.code, request.status));
                return;
            }
            resolve(body.data);
        });
        request.addEventListener('error', () => reject(new ApiError('Network error during upload.', 'network', 0)));
        request.send(form);
    });
}

export function downloadUrl(site, paths) {
    const params = new URLSearchParams();
    params.set('site', site);
    params.set('token', state.csrf);
    paths.forEach((path) => params.append('path[]', path));

    return `download.php?${params.toString()}`;
}

/**
 * Same stream, but asked for inline so the browser paints it instead of saving
 * it. `version` (the file's mtime) keeps a rewritten file from being served
 * from the browser cache.
 */
export function previewUrl(site, path, version = 0) {
    return `${downloadUrl(site, [path])}&inline=1&v=${version}`;
}

export function site(id) {
    return state.sites.find((entry) => entry.id === id) || null;
}
