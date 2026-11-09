/**
 * Grafida Shell — browser-side helper for the shell's native endpoints.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   MIT; see LICENSE
 *
 * The native shell answers `<scheme>://app/_native/*` itself; those requests never reach the PHP
 * worker. This file is the page's side of that contract (grafida/grafida#82, "Native endpoints").
 * It is a classic script that defines `globalThis.GrafidaShell`. Serve it as a static asset.
 *
 * STATUS: contract only. The endpoints arrive with the shell in Phase 2. Under any other host (Boson
 * today, or a plain browser) `available()` resolves false, and every other call rejects with a
 * `ShellUnavailableError`, so callers can fall back to their existing code path.
 *
 * Files are referred to by opaque per-session **tokens**, never by paths. To hand a picked file to
 * the app, send its token in the `X-Shell-File` request header. The shell strips any incoming
 * `X-Shell-File-Path`, resolves the token, and forwards `X-Shell-File-Path: /real/path` to the
 * worker. Script injected into the page can therefore read only what the user picked this session.
 */
(function (root) {
    'use strict';

    var BASE = '/_native/';

    /** The page is not hosted by the shell, or the shell refused the call. */
    class ShellUnavailableError extends Error {
        constructor(message, status) {
            super(message);
            this.name = 'ShellUnavailableError';
            this.status = status || 0;
        }
    }

    function isJson(response) {
        return (response.headers.get('content-type') || '').indexOf('application/json') === 0;
    }

    /** One call to a native endpoint: JSON in, JSON out. */
    async function call(method, endpoint, body) {
        var init = { method: method, cache: 'no-store', headers: {} };

        if (body !== undefined) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(body);
        }

        var response;

        try {
            response = await fetch(BASE + endpoint, init);
        } catch (e) {
            throw new ShellUnavailableError(String(e && e.message || e));
        }

        // Any other host answers these paths with its own page or a 404, not with our JSON.
        if (!response.ok || !isJson(response)) {
            throw new ShellUnavailableError('Native endpoint ' + endpoint + ' unavailable', response.status);
        }

        return response.json();
    }

    var api = {
        ShellUnavailableError: ShellUnavailableError,

        /** Request header carrying a file token to the app. */
        FILE_HEADER: 'X-Shell-File',

        /**
         * `GET _native/info` → `{shell, protocol, platform, arch, webview}`.
         * @returns {Promise<{shell: string, protocol: number, platform: string, arch: string, webview: string}>}
         */
        info: function () {
            return call('GET', 'info');
        },

        /** Whether the page is hosted by the native shell. Never rejects. */
        available: async function () {
            try {
                var info = await call('GET', 'info');
                return typeof info === 'object' && info !== null && typeof info.protocol === 'number';
            } catch (e) {
                return false;
            }
        },

        /**
         * `POST _native/dialog/open-file`.
         * @param {{filters?: Array<{name: string, extensions: string[]}>, multiple?: boolean}} [options]
         * @returns {Promise<{cancelled: true} | {files: Array<{token: string, name: string, size: number}>}>}
         */
        openFile: function (options) {
            options = options || {};
            return call('POST', 'dialog/open-file', { filters: options.filters || [], multiple: !!options.multiple });
        },

        /**
         * `POST _native/dialog/select-folder`.
         * @returns {Promise<{cancelled: true} | {folder: {token: string, name: string}}>}
         */
        selectFolder: function () {
            return call('POST', 'dialog/select-folder', {});
        },

        /**
         * `POST _native/dialog/save-file`.
         * @param {{suggestedName?: string, filters?: Array<{name: string, extensions: string[]}>}} [options]
         * @returns {Promise<{cancelled: true} | {file: {token: string, name: string}}>}
         */
        saveFile: function (options) {
            options = options || {};
            return call('POST', 'dialog/save-file', { suggestedName: options.suggestedName || '', filters: options.filters || [] });
        },

        /** `POST _native/open-url`: http(s) only, opened in the OS browser. */
        openUrl: function (url) {
            return call('POST', 'open-url', { url: url });
        },

        /** `POST _native/reveal`: show a picked file or folder in the OS file manager. */
        reveal: function (token) {
            return call('POST', 'reveal', { token: token });
        },

        /** `POST _native/window/theme`: title bar / decoration theme, `light` | `dark` | `system`. */
        setWindowTheme: function (theme) {
            return call('POST', 'window/theme', { theme: theme });
        },

        /** `POST _native/window/title`. */
        setWindowTitle: function (title) {
            return call('POST', 'window/title', { title: title });
        },

        /** `POST _native/quit`: closes the window; the worker gets EOF and exits. */
        quit: function () {
            return call('POST', 'quit', {});
        },

        /**
         * `fetch()` init headers that hand a picked file to the app, e.g.
         * `fetch('/api/dialog/open-file', {method: 'POST', headers: GrafidaShell.fileHeaders(token)})`.
         */
        fileHeaders: function (token) {
            var headers = {};
            headers['X-Shell-File'] = token;
            return headers;
        }
    };

    root.GrafidaShell = Object.freeze(api);
})(typeof globalThis !== 'undefined' ? globalThis : this);
