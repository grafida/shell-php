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
 * Under any other host (Boson, a plain browser) `available()` resolves false and every other call
 * rejects with a `ShellUnavailableError`, so callers can fall back to their existing code path.
 * Under the shell, a refused call (an unknown token, a dialog already open) rejects with a
 * `ShellError` carrying the shell's error code: the shell is there, it just said no.
 *
 * `available()` asks once per page and remembers the answer, so calling it before every dialog
 * costs nothing after the first time.
 *
 * Files are referred to by opaque per-session **tokens**, never by paths. To hand a picked file to
 * the app, send its token in the `X-Shell-File` request header. The shell strips any incoming
 * `X-Shell-File-Path`, resolves the token, and forwards `X-Shell-File-Path: /real/path` to the
 * worker. Script injected into the page can therefore read only what the user picked this session.
 */
(function (root) {
    'use strict';

    var BASE = '/_native/';

    /** The page is not hosted by the shell. */
    class ShellUnavailableError extends Error {
        constructor(message, status) {
            super(message);
            this.name = 'ShellUnavailableError';
            this.status = status || 0;
        }
    }

    /** The shell answered, and refused: `code` is its error code, e.g. `dialog_busy`. */
    class ShellError extends Error {
        constructor(code, status, detail) {
            super('Shell error: ' + code + (detail ? ' (' + detail + ')' : ''));
            this.name = 'ShellError';
            this.code = code;
            this.status = status;
            this.detail = detail || null;
        }
    }

    var availability = null;

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
        if (!isJson(response)) {
            throw new ShellUnavailableError('Native endpoint ' + endpoint + ' unavailable', response.status);
        }

        var json = await response.json();

        if (!response.ok) {
            throw new ShellError(String(json && json.error || 'error'), response.status, json && json.detail);
        }

        return json;
    }

    var api = {
        ShellUnavailableError: ShellUnavailableError,
        ShellError: ShellError,

        /** Request header carrying a file token to the app. */
        FILE_HEADER: 'X-Shell-File',

        /** Request header asking the shell to put the clipboard's text in the request body. */
        CLIPBOARD_HEADER: 'X-Shell-Clipboard',

        /**
         * `GET _native/info` → `{shell, protocol, platform, arch, webview}`.
         * @returns {Promise<{shell: string, protocol: number, platform: string, arch: string, webview: string}>}
         */
        info: function () {
            return call('GET', 'info');
        },

        /** Whether the page is hosted by the native shell. Asked once per page; never rejects. */
        available: function () {
            if (availability === null) {
                availability = call('GET', 'info').then(
                    function (info) {
                        return typeof info === 'object' && info !== null && typeof info.protocol === 'number';
                    },
                    function () {
                        return false;
                    }
                );
            }

            return availability;
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
        },

        /**
         * `fetch()` init headers that have the shell read the OS clipboard for this request: the shell
         * replaces the body with the clipboard's text (empty when it holds none) and forwards
         * `X-Shell-Clipboard: text`, so the app reads it from the body. The shell owns the window, so
         * this needs no helper tool and shows no prompt.
         */
        clipboardHeaders: function () {
            var headers = {};
            headers['X-Shell-Clipboard'] = 'read';
            return headers;
        },

        /**
         * Files dropped on the window. The page's sandboxed web process cannot read them itself, so
         * the shell takes the drop and calls `handler({files: [{token, name, size}], x, y})`, `x`/`y`
         * being the drop point in viewport coordinates. Read each file as after a dialog, with
         * `fileHeaders(token)`. Returns a function that removes the handler. Never fires under any
         * other host.
         */
        onDrop: function (handler) {
            var listener = function (event) {
                handler(event.detail);
            };
            root.addEventListener('shell:drop', listener);
            return function () {
                root.removeEventListener('shell:drop', listener);
            };
        }
    };

    root.GrafidaShell = Object.freeze(api);
})(typeof globalThis !== 'undefined' ? globalThis : this);
