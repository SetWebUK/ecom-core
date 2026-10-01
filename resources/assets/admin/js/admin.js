/*!
 * Pine Commerce – back office runtime (Alpine.js components + small helpers). No build step.
 *
 * Public API (window.Admin):
 *   Admin.toast(message, type = 'success' | 'error' | 'warning' | 'info', { timeout, action: {label, href} })
 *   Admin.confirm({ title, message, confirmText, cancelText, danger }) -> Promise<boolean>
 *   Admin.fetch(url, { method = 'GET', data, body, headers, signal }) -> Promise<json>
 *       CSRF + Accept JSON. GET: data -> query string. POST/PUT/PATCH/DELETE: data -> JSON body (or FormData as-is).
 *       Rejects with an Error whose .message is user-friendly (.status, .errors = Laravel validation errors).
 *   Admin.csrf() · Admin.debounce(fn, ms) · Admin.money(n) · Admin.slugify(str) · Admin.randomCode(len)
 *   Admin.sortable(el, options) -> Sortable instance (needs the sortable vendor script – see <x-admin.sortable-list>)
 *   Admin.editor(textarea, options) -> Promise<TinyMCE editor> (needs the tinymce vendor script – see <x-admin.rich-editor>)
 *   Admin.openModal(name) · Admin.closeModal(name) · Admin.markDirty(el)
 *
 * Global behaviours (no markup needed):
 *   - <form data-confirm="…"> / <button data-confirm="…">  -> confirm dialog before submitting
 *     (optional data-confirm-title, data-confirm-button, data-confirm-danger="false")
 *   - every submitting form shows a spinner on the clicked button and blocks double submits
 *     (opt out with <form data-no-loading>, e.g. CSV downloads)
 *   - "/" focuses the page's search box ([data-page-search]) or the global search; Ctrl/⌘+K always the global search
 *   - flash messages (session 'success' | 'error' | 'warning' | 'info' | 'status') appear as toasts
 *   - the first invalid field is scrolled into view after a failed validation
 *
 * Page scripts must register Alpine components inside:  document.addEventListener('alpine:init', () => { … })
 */
(function () {
    'use strict';

    var ICON_PATHS = {
        success: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
        error: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>',
        warning: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>',
        info: '<path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/>'
    };

    function icon(name) {
        return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">' + (ICON_PATHS[name] || ICON_PATHS.info) + '</svg>';
    }

    // Utilities ---------------------------------------------------------------------------------

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, ms === undefined ? 250 : ms);
        };
    }

    var moneyFormat = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' });
    function money(n) {
        var v = parseFloat(n);
        return moneyFormat.format(isNaN(v) ? 0 : v);
    }

    function slugify(str) {
        return String(str || '')
            .normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase().replace(/&/g, ' and ').replace(/['’]/g, '')
            .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 190);
    }

    function randomCode(length) {
        var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
        var out = '', bytes = new Uint32Array(length || 8);
        window.crypto.getRandomValues(bytes);
        for (var i = 0; i < bytes.length; i++) out += alphabet[bytes[i] % alphabet.length];
        return out;
    }

    function isTyping(el) {
        if (!el) return false;
        var tag = el.tagName;
        return el.isContentEditable || tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
    }

    /** Tell dirty-form tracking (and anything else listening) that a control changed programmatically. */
    function markDirty(el) {
        if (el) el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // Toasts ------------------------------------------------------------------------------------

    var toastQueue = [];
    var toastSeq = 0;

    function toast(message, type, opts) {
        if (!message) return;
        var item = { id: ++toastSeq, message: String(message), type: type || 'success', action: (opts && opts.action) || null };
        item.timeout = (opts && opts.timeout) || (item.type === 'error' ? 8000 : 4500);
        if (window.Alpine && Alpine.store('toasts')) {
            Alpine.store('toasts').add(item);
        } else {
            toastQueue.push(item);
        }
    }

    // Confirm dialog ------------------------------------------------------------------------------

    function confirmDialog(opts) {
        opts = opts || {};
        if (!(window.Alpine && Alpine.store('confirm'))) {
            return Promise.resolve(window.confirm(opts.message || opts.title || 'Are you sure?'));
        }
        return Alpine.store('confirm').ask(opts);
    }

    // Fetch ---------------------------------------------------------------------------------------

    function request(url, opts) {
        opts = opts || {};
        var headers = Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() }, opts.headers || {});
        var body = opts.body;
        // GET unless told otherwise (or a body / FormData is given). GET data goes in the query string, other methods send JSON.
        var method = (opts.method || (body || opts.data instanceof FormData ? 'POST' : 'GET')).toUpperCase();
        if (opts.data !== undefined) {
            if (opts.data instanceof FormData) {
                body = opts.data;
            } else if (method === 'GET') {
                var qs = new URLSearchParams(opts.data).toString();
                if (qs) url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
            } else {
                body = JSON.stringify(opts.data);
                headers['Content-Type'] = 'application/json';
            }
        }
        return fetch(url, { method: method, headers: headers, body: body, credentials: 'same-origin', signal: opts.signal })
            .then(function (res) {
                var type = res.headers.get('Content-Type') || '';
                var parse = type.indexOf('json') !== -1 ? res.json() : res.text().then(function (t) { return { message: t }; });
                return parse.catch(function () { return {}; }).then(function (json) {
                    if (res.ok) return json;
                    var message = (json && json.message) || 'Something went wrong (' + res.status + ').';
                    if (res.status === 419) message = 'Your session expired. Reload the page and try again.';
                    if (res.status === 401) {
                        message = 'You have been signed out. Please sign in again.';
                        setTimeout(function () { window.location.reload(); }, 1500);
                    }
                    if (res.status === 413) message = 'That file is too large to upload.';
                    if (res.status === 422 && json && json.errors) {
                        var first = Object.keys(json.errors)[0];
                        if (first) message = json.errors[first][0];
                    }
                    var err = new Error(message);
                    err.status = res.status;
                    err.errors = (json && json.errors) || {};
                    err.data = json;
                    throw err;
                });
            });
    }

    // Vendor wrappers -------------------------------------------------------------------------------

    function sortable(el, options) {
        if (!window.Sortable) {
            console.warn('Admin.sortable: Sortable.js not loaded on this page (use <x-admin.sortable-list> or push the vendor script).');
            return null;
        }
        return new window.Sortable(el, Object.assign({
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            forceFallback: false
        }, options || {}));
    }

    // published admin asset directory (commerce.admin.assets_url): derived from this script's own URL, else the
    // <meta name="admin-asset-base"> the layouts print
    var EDITOR_BASE = (document.currentScript && document.currentScript.src.replace(/js\/admin\.js.*$/, ''))
        || (document.querySelector('meta[name="admin-asset-base"]') || {}).content || '/vendor/commerce/admin/';

    function editor(textarea, options) {
        options = options || {};
        if (!window.tinymce) {
            console.warn('Admin.editor: TinyMCE not loaded on this page (use <x-admin.rich-editor>).');
            return Promise.resolve(null);
        }
        var uploadUrl = options.uploadUrl;
        var config = {
            target: textarea,
            base_url: EDITOR_BASE + 'vendor/tinymce-6.8.6',
            suffix: '.min',
            promotion: false,
            branding: false,
            menubar: 'edit view insert format table',
            height: options.height || 480,
            min_height: 240,
            resize: true,
            plugins: 'code link lists advlist image media table autolink searchreplace visualblocks fullscreen wordcount charmap anchor',
            toolbar: 'undo redo | blocks | bold italic underline strikethrough | forecolor | bullist numlist outdent indent | alignleft aligncenter alignright | link image media table | removeformat | visualblocks code fullscreen',
            toolbar_mode: 'sliding',
            block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Heading 6=h6; Preformatted=pre; Div=div',
            // Keep imported HTML exactly as authored: any element, any attribute (class, style, data-*, ids…)
            valid_elements: '*[*]',
            extended_valid_elements: '*[*],i[*],span[*],svg[*],path[*],g[*],use[*],circle[*],rect[*],line[*],polyline[*],polygon[*],script[*],style[*],noscript[*],iframe[*],source[*],picture[*],video[*],audio[*],button[*],form[*],input[*],label[*],select[*],option[*],textarea[*],section[*],article[*],header[*],footer[*],nav[*],aside[*],figure[*],figcaption[*],details[*],summary[*]',
            valid_children: '+body[style|script|link|noscript|section|header|footer|nav|article|aside],+div[style|script|noscript],+a[div|p|h1|h2|h3|h4|h5|h6|ul|ol|li|span|img|picture|figure|section|article|header|footer|svg|button],+span[div|p|svg],+button[span|svg|img|div]',
            verify_html: false,
            allow_script_urls: false,
            allow_conditional_comments: true,
            allow_html_in_named_anchor: true,
            convert_urls: false,
            relative_urls: false,
            remove_script_host: false,
            entity_encoding: 'raw',
            keep_styles: true,
            indent: true,
            element_format: 'html',
            schema: 'html5',
            end_container_on_empty_block: true,
            image_advtab: true,
            image_caption: true,
            image_title: true,
            link_default_target: '',
            link_assume_external_targets: 'https',
            object_resizing: 'img',
            paste_data_images: false,
            content_css: options.contentCss || (EDITOR_BASE + 'css/editor-content.css'),
            content_style: options.contentStyle || '',
            body_class: options.bodyClass || '',
            images_upload_handler: function (blobInfo, progress) {
                if (!uploadUrl) return Promise.reject({ message: 'Uploads are not enabled for this editor.', remove: true });
                var fd = new FormData();
                fd.append('file', blobInfo.blob(), blobInfo.filename());
                return request(uploadUrl, { method: 'POST', data: fd }).then(function (json) {
                    return json.location;
                }).catch(function (err) {
                    return Promise.reject({ message: err.message, remove: true });
                });
            },
            file_picker_types: 'image',
            file_picker_callback: function (callback) {
                // Pick from the media library with the shared image-picker modal
                window.dispatchEvent(new CustomEvent('admin:media-pick', { detail: { callback: function (item) { callback(item.url, { alt: item.alt || '' }); } } }));
            },
            setup: function (ed) {
                var sync = function () {
                    ed.save();
                    markDirty(textarea);
                };
                ed.on('change input undo redo', debounce(sync, 150));
                ed.on('init', function () {
                    // TinyMCE re-serialises the HTML on load; accept that as the unchanged value for dirty tracking
                    ed.save();
                    textarea.dispatchEvent(new CustomEvent('admin:rebase', { bubbles: true, detail: { name: textarea.name } }));
                });
                if (typeof options.setup === 'function') options.setup(ed);
            }
        };
        Object.keys(options.config || {}).forEach(function (k) { config[k] = options.config[k]; });
        return window.tinymce.init(config).then(function (editors) { return editors[0] || null; });
    }

    function openModal(name) { window.dispatchEvent(new CustomEvent('open-modal', { detail: name })); }
    function closeModal(name) { window.dispatchEvent(new CustomEvent('close-modal', { detail: name })); }

    window.Admin = {
        assetBase: EDITOR_BASE,
        toast: toast,
        confirm: confirmDialog,
        fetch: request,
        csrf: csrf,
        debounce: debounce,
        money: money,
        slugify: slugify,
        randomCode: randomCode,
        sortable: sortable,
        editor: editor,
        openModal: openModal,
        closeModal: closeModal,
        markDirty: markDirty,
        icon: icon
    };

    // Alpine components ------------------------------------------------------------------------------

    document.addEventListener('alpine:init', function () {
        var Alpine = window.Alpine;

        Alpine.store('toasts', {
            items: [],
            add: function (item) {
                var self = this;
                this.items.push(item);
                if (this.items.length > 4) this.items.shift();
                if (item.timeout > 0) setTimeout(function () { self.remove(item.id); }, item.timeout);
            },
            remove: function (id) {
                this.items = this.items.filter(function (t) { return t.id !== id; });
            },
            icon: function (type) { return icon(type === 'error' ? 'error' : type); }
        });

        Alpine.store('confirm', {
            open: false,
            title: '',
            message: '',
            confirmText: 'Confirm',
            cancelText: 'Cancel',
            danger: true,
            _resolve: null,
            ask: function (opts) {
                var self = this;
                if (this._resolve) this._resolve(false);
                this.title = opts.title || 'Are you sure?';
                this.message = opts.message || '';
                this.confirmText = opts.confirmText || (opts.danger === false ? 'Confirm' : 'Delete');
                this.cancelText = opts.cancelText || 'Cancel';
                this.danger = opts.danger !== false;
                this.open = true;
                return new Promise(function (resolve) { self._resolve = resolve; });
            },
            answer: function (value) {
                this.open = false;
                if (this._resolve) this._resolve(!!value);
                this._resolve = null;
            }
        });

        toastQueue.splice(0).forEach(function (t) { Alpine.store('toasts').add(t); });

        /* App shell: mobile sidebar */
        Alpine.data('shell', function () {
            return {
                sidebarOpen: false,
                toggleSidebar: function () { this.sidebarOpen = !this.sidebarOpen; },
                closeSidebar: function () { this.sidebarOpen = false; }
            };
        });

        /* Top-bar search: orders, products, customers (JSON from SearchController@suggest) */
        Alpine.data('globalSearch', function (endpoint, pageUrl) {
            return {
                q: '',
                open: false,
                loading: false,
                groups: [],
                items: [],
                active: -1,
                searched: '',
                controller: null,
                init: function () {
                    var self = this;
                    this.$watch('q', debounce(function () { self.search(); }, 180));
                },
                search: function () {
                    var self = this, q = this.q.trim();
                    if (q.length < 2) {
                        this.groups = []; this.items = []; this.active = -1; this.searched = ''; this.loading = false;
                        return;
                    }
                    if (this.controller) this.controller.abort();
                    this.controller = new AbortController();
                    this.loading = true;
                    this.open = true;
                    request(endpoint, { data: { q: q }, signal: this.controller.signal }).then(function (json) {
                        self.groups = json.groups || [];
                        var flat = [];
                        self.groups.forEach(function (g) { g.items.forEach(function (it) { it._i = flat.length; flat.push(it); }); });
                        self.items = flat;
                        self.active = flat.length ? 0 : -1;
                        self.searched = q;
                        self.loading = false;
                    }).catch(function (err) {
                        if (err.name !== 'AbortError') { self.loading = false; }
                    });
                },
                move: function (delta) {
                    if (!this.items.length) return;
                    this.open = true;
                    this.active = (this.active + delta + this.items.length) % this.items.length;
                    var el = this.$root.querySelector('[data-index="' + this.active + '"]');
                    if (el) el.scrollIntoView({ block: 'nearest' });
                },
                go: function () {
                    var item = this.items[this.active];
                    if (item && this.open) {
                        window.location.href = item.url;
                    } else if (this.q.trim()) {
                        window.location.href = pageUrl + '?q=' + encodeURIComponent(this.q.trim());
                    }
                },
                close: function () { this.open = false; },
                clear: function () { this.q = ''; this.open = false; }
            };
        });

        /*
         * Dirty-form tracking + Shopify-style contextual save bar.
         * <form x-data="dirtyForm"> … </form>  (rendered for you by <x-admin.form dirty>)
         */
        Alpine.data('dirtyForm', function (opts) {
            opts = opts || {};
            return {
                dirty: false,
                submitting: false,
                initial: null,
                init: function () {
                    var self = this;
                    this.form = this.$el.tagName === 'FORM' ? this.$el : this.$el.querySelector('form');
                    if (!this.form) return;
                    // Snapshot once child components (pickers, editors) have rendered their inputs
                    requestAnimationFrame(function () { setTimeout(function () { self.initial = self.snapshot(); }, 60); });
                    var check = debounce(function () { self.check(); }, 120);
                    this.form.addEventListener('input', check);
                    this.form.addEventListener('change', check);
                    this.form.addEventListener('submit', function (e) {
                        // Wait for confirm dialogs / validation handlers that may cancel the submit
                        setTimeout(function () { if (!e.defaultPrevented) self.submitting = true; }, 0);
                    });
                    this.form.addEventListener('dirty-reset', function () { self.reset(); });
                    this.form.addEventListener('admin:rebase', function (e) {
                        var run = function () { self.rebase(e.detail && e.detail.name); };
                        if (self.initial === null) { setTimeout(run, 120); } else { run(); }
                    });
                    window.addEventListener('beforeunload', function (e) {
                        if (self.dirty && !self.submitting) {
                            e.preventDefault();
                            e.returnValue = '';
                        }
                    });
                    window.addEventListener('pageshow', function (e) { if (e.persisted) self.submitting = false; });
                    if (opts.startDirty) this.dirty = true;
                },
                snapshot: function () {
                    var parts = [];
                    new FormData(this.form).forEach(function (value, key) {
                        if (key === '_token' || key === '_method') return;
                        parts.push([key, typeof value === 'string' ? value : (value && value.name ? 'file:' + value.name + ':' + value.size : '')]);
                    });
                    return parts;
                },
                serialize: function (parts) {
                    return parts.map(function (p) { return p[0] + '=' + p[1]; }).join('&');
                },
                check: function () {
                    if (this.initial === null) return;
                    this.dirty = this.serialize(this.snapshot()) !== this.serialize(this.initial);
                },
                reset: function () {
                    this.initial = this.snapshot();
                    this.dirty = false;
                },
                /** Accept the current value of one field as its "saved" value (e.g. after an editor normalises HTML on load). */
                rebase: function (name) {
                    if (this.initial === null || !name) return;
                    var current = this.snapshot().filter(function (p) { return p[0] === name; });
                    var others = this.initial.filter(function (p) { return p[0] !== name; });
                    this.initial = others.concat(current);
                    // keep original ordering stable for comparison
                    var order = this.snapshot().map(function (p) { return p[0]; });
                    this.initial.sort(function (a, b) { return order.indexOf(a[0]) - order.indexOf(b[0]); });
                    this.check();
                },
                discard: function () {
                    var self = this;
                    confirmDialog({ title: 'Discard all unsaved changes?', message: 'Any changes you made since you last saved will be lost.', confirmText: 'Discard changes' }).then(function (ok) {
                        if (!ok) return;
                        self.submitting = true;
                        window.location.replace(window.location.href);
                    });
                }
            };
        });

        /*
         * Selectable table rows + bulk bar.  x-data="bulkTable(['1','2',…])" on the card around the table.
         */
        Alpine.data('bulkTable', function (ids) {
            return {
                ids: (ids || []).map(String),
                selected: [],
                last: null,
                get count() { return this.selected.length; },
                get all() { return this.ids.length > 0 && this.selected.length === this.ids.length; },
                get some() { return this.selected.length > 0 && this.selected.length < this.ids.length; },
                isSelected: function (id) { return this.selected.indexOf(String(id)) !== -1; },
                toggleAll: function () { this.selected = this.all ? [] : this.ids.slice(); },
                toggle: function (id, event) {
                    id = String(id);
                    var on = !this.isSelected(id);
                    if (event && event.shiftKey && this.last !== null) {
                        var a = this.ids.indexOf(this.last), b = this.ids.indexOf(id);
                        var range = this.ids.slice(Math.min(a, b), Math.max(a, b) + 1);
                        var set = this.selected.slice();
                        range.forEach(function (r) {
                            var idx = set.indexOf(r);
                            if (on && idx === -1) set.push(r);
                            if (!on && idx !== -1) set.splice(idx, 1);
                        });
                        this.selected = set;
                    } else if (on) {
                        this.selected.push(id);
                    } else {
                        this.selected.splice(this.selected.indexOf(id), 1);
                    }
                    this.last = id;
                },
                clear: function () { this.selected = []; }
            };
        });

        /*
         * Search-as-you-type picker (products, categories, customers…).
         * config: { name, multiple, endpoint (JSON: {data:[{id,label,sub,image}]}), options (static list instead of endpoint),
         *           selected: [{id,label,sub,image}], placeholder, max }
         */
        Alpine.data('picker', function (config) {
            return {
                name: config.name,
                multiple: config.multiple !== false,
                endpoint: config.endpoint || null,
                options: config.options || null,
                selected: config.selected || [],
                max: config.max || 0,
                q: '',
                results: [],
                open: false,
                loading: false,
                active: 0,
                controller: null,
                init: function () {
                    var self = this;
                    this.$watch('q', debounce(function () { self.search(); }, 200));
                    this.$watch('selected', function () { self.$nextTick(function () { markDirty(self.$refs.inputs || self.$el); }); });
                },
                isChosen: function (id) {
                    return this.selected.some(function (s) { return String(s.id) === String(id); });
                },
                search: function () {
                    var self = this, q = this.q.trim().toLowerCase();
                    this.active = 0;
                    if (this.options) {
                        this.results = this.options.filter(function (o) {
                            return !q || (o.label + ' ' + (o.sub || '')).toLowerCase().indexOf(q) !== -1;
                        }).slice(0, 250);
                        this.open = true;
                        return;
                    }
                    if (!q) { this.results = []; return; }
                    if (this.controller) this.controller.abort();
                    this.controller = new AbortController();
                    this.loading = true;
                    this.open = true;
                    request(this.endpoint, { data: { q: this.q.trim() }, signal: this.controller.signal }).then(function (json) {
                        self.results = json.data || [];
                        self.loading = false;
                    }).catch(function (err) {
                        if (err.name !== 'AbortError') { self.loading = false; toast(err.message, 'error'); }
                    });
                },
                focus: function () {
                    if (this.options) { this.search(); }
                    else if (this.q.trim()) { this.open = true; }
                },
                choose: function (item) {
                    if (!item) return;
                    if (this.isChosen(item.id)) {
                        this.remove(item.id);
                    } else if (this.multiple) {
                        if (this.max && this.selected.length >= this.max) { toast('You can choose up to ' + this.max + '.', 'warning'); return; }
                        this.selected.push(item);
                    } else {
                        this.selected = [item];
                        this.open = false;
                    }
                    this.q = '';
                    if (this.options) { this.search(); } else { this.results = []; this.open = false; }
                    this.$refs.search && this.$refs.search.focus();
                },
                remove: function (id) {
                    this.selected = this.selected.filter(function (s) { return String(s.id) !== String(id); });
                },
                onKey: function (e) {
                    if (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.active = Math.min(this.active + 1, this.results.length - 1); this.scrollActive(); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); this.active = Math.max(this.active - 1, 0); this.scrollActive(); }
                    else if (e.key === 'Enter') { if (this.open && this.results[this.active]) { e.preventDefault(); this.choose(this.results[this.active]); } else { e.preventDefault(); } }
                    else if (e.key === 'Escape') { if (this.open) { e.stopPropagation(); this.open = false; } }
                    else if (e.key === 'Backspace' && !this.q && this.selected.length && this.multiple) { this.selected.pop(); }
                },
                scrollActive: function () {
                    var el = this.$root.querySelector('[data-option="' + this.active + '"]');
                    if (el) el.scrollIntoView({ block: 'nearest' });
                }
            };
        });

        /*
         * Image picker: choose from the media library or upload. Single -> one path; multiple -> ordered list with alt text.
         * config: { name, multiple, withAlt, items: [{path,url,alt}], uploadUrl, libraryUrl }
         */
        Alpine.data('imagePicker', function (config) {
            return {
                name: config.name,
                multiple: !!config.multiple,
                withAlt: config.withAlt !== false && !!config.multiple,
                items: (config.items || []).filter(function (i) { return i && i.path; }),
                uploadUrl: config.uploadUrl,
                libraryUrl: config.libraryUrl,
                uploading: 0,
                dragover: false,
                library: { open: false, loading: false, q: '', page: 1, lastPage: 1, total: 0, data: [], chosen: [] },
                pickCallback: null,
                init: function () {
                    var self = this;
                    this.$watch('items', function () { self.$nextTick(function () { markDirty(self.$el); }); });
                    if (this.multiple && this.$refs.grid) {
                        this.$nextTick(function () {
                            sortable(self.$refs.grid, {
                                handle: '.image-grid__handle',
                                draggable: '.image-grid__item',
                                onEnd: function (evt) {
                                    // Alpine owns this DOM (x-for): put the node back where it was, then reorder the data
                                    var from = evt.oldDraggableIndex, to = evt.newDraggableIndex;
                                    if (from === undefined || to === undefined || from === to) return;
                                    var parent = evt.from, moved = evt.item;
                                    parent.removeChild(moved);
                                    var siblings = parent.querySelectorAll(':scope > .image-grid__item');
                                    parent.insertBefore(moved, siblings[from] || parent.querySelector(':scope > .image-grid__add'));
                                    var list = self.items.slice();
                                    list.splice(to, 0, list.splice(from, 1)[0]);
                                    self.items = list;
                                }
                            });
                        });
                    }
                    if (config.editorBridge) {
                        window.addEventListener('admin:media-pick', function (e) {
                            self.pickCallback = e.detail.callback;
                            self.openLibrary();
                        });
                    }
                },
                get single() { return this.items[0] || null; },
                openLibrary: function () {
                    this.library.open = true;
                    this.library.chosen = [];
                    if (!this.library.data.length) this.loadLibrary(1);
                    var self = this;
                    this.$nextTick(function () { self.$refs.librarySearch && self.$refs.librarySearch.focus(); });
                },
                closeLibrary: function () { this.library.open = false; this.pickCallback = null; },
                loadLibrary: function (page) {
                    var self = this, lib = this.library;
                    lib.loading = true;
                    request(this.libraryUrl, { data: { q: lib.q, page: page || 1 } }).then(function (json) {
                        lib.data = (page > 1) ? lib.data.concat(json.data) : json.data;
                        lib.page = json.current_page;
                        lib.lastPage = json.last_page;
                        lib.total = json.total;
                        lib.loading = false;
                    }).catch(function (err) { lib.loading = false; toast(err.message, 'error'); });
                },
                searchLibrary: debounce(function () { this.loadLibrary(1); }, 250),
                isChosen: function (m) { return this.library.chosen.some(function (c) { return c.path === m.path; }); },
                toggleChosen: function (m) {
                    if (this.pickCallback || !this.multiple) {
                        this.library.chosen = [m];
                        this.confirmLibrary();
                        return;
                    }
                    if (this.isChosen(m)) {
                        this.library.chosen = this.library.chosen.filter(function (c) { return c.path !== m.path; });
                    } else {
                        this.library.chosen.push(m);
                    }
                },
                confirmLibrary: function () {
                    var chosen = this.library.chosen.map(function (m) { return { path: m.path, url: m.url, alt: m.alt || '' }; });
                    if (this.pickCallback) {
                        if (chosen[0]) this.pickCallback(chosen[0]);
                    } else if (this.multiple) {
                        var existing = this.items.map(function (i) { return i.path; });
                        this.items = this.items.concat(chosen.filter(function (c) { return existing.indexOf(c.path) === -1; }));
                    } else if (chosen[0]) {
                        this.items = [chosen[0]];
                    }
                    this.closeLibrary();
                },
                remove: function (index) {
                    var list = this.items.slice();
                    list.splice(index, 1);
                    this.items = list;
                },
                makeFirst: function (index) {
                    var list = this.items.slice();
                    var item = list.splice(index, 1)[0];
                    list.unshift(item);
                    this.items = list;
                },
                onDrop: function (e) {
                    this.dragover = false;
                    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) this.upload(e.dataTransfer.files);
                },
                upload: function (fileList) {
                    var self = this;
                    var files = Array.prototype.slice.call(fileList || []);
                    if (!this.multiple) files = files.slice(0, 1);
                    files.forEach(function (file) {
                        if (!/^image\//.test(file.type)) { toast(file.name + ' is not an image.', 'error'); return; }
                        var fd = new FormData();
                        fd.append('file', file);
                        self.uploading++;
                        request(self.uploadUrl, { method: 'POST', data: fd }).then(function (json) {
                            var item = { path: json.path, url: json.url, alt: json.alt || '' };
                            self.library.data.unshift(json);
                            if (self.library.open) {
                                self.library.chosen = self.multiple && !self.pickCallback ? self.library.chosen.concat([json]) : [json];
                                if (!self.multiple || self.pickCallback) self.confirmLibrary();
                            } else {
                                self.items = self.multiple ? self.items.concat([item]) : [item];
                            }
                        }).catch(function (err) {
                            toast(file.name + ': ' + err.message, 'error');
                        }).then(function () { self.uploading--; });
                    });
                    if (this.$refs.file) this.$refs.file.value = '';
                    if (this.$refs.libraryFile) this.$refs.libraryFile.value = '';
                }
            };
        });

        /* SEO title/description with Google snippet preview + counters */
        Alpine.data('seoFields', function (config) {
            return {
                title: config.title || '',
                description: config.description || '',
                sourceTitle: config.fallbackTitle || '',
                sourceDescription: config.fallbackDescription || '',
                suffix: config.suffix || '',
                url: config.url || '',
                slugSource: config.slugSource || null,
                baseUrl: config.baseUrl || '',
                titleMax: config.titleMax || 60,
                descriptionMax: config.descriptionMax || 160,
                init: function () {
                    var self = this;
                    var bind = function (selector, prop, transform) {
                        if (!selector) return;
                        var el = document.querySelector(selector);
                        if (!el) return;
                        var update = function () { self[prop] = transform ? transform(el.value) : el.value; };
                        el.addEventListener('input', update);
                        update();
                    };
                    bind(config.titleSource, 'sourceTitle');
                    bind(config.descriptionSource, 'sourceDescription', function (v) { return v.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim(); });
                    if (this.slugSource && this.baseUrl) {
                        bind(this.slugSource, 'url', function (v) { return self.baseUrl.replace(/\/$/, '') + '/' + (v || '').replace(/^\/+|\/+$/g, '') + '/'; });
                    }
                },
                get effectiveTitle() {
                    var t = (this.title || this.sourceTitle || '').trim();
                    return t ? t + (this.title ? '' : (this.suffix ? ' ' + this.suffix : '')) : 'Page title';
                },
                get effectiveDescription() {
                    var d = (this.description || this.sourceDescription || '').trim();
                    return d ? (d.length > 170 ? d.slice(0, 167) + '…' : d) : 'Add a meta description to control the text shown under the title in Google results.';
                },
                get displayUrl() {
                    return (this.url || '').replace(/^https?:\/\//, '').replace(/\/$/, '').split('/').join(' › ');
                }
            };
        });

        /* Drag-and-drop ordered list; posts the new order to `url` ({ids: [...]}) when given. */
        Alpine.data('sortableList', function (config) {
            config = config || {};
            return {
                saving: false,
                init: function () {
                    var self = this;
                    var list = this.$refs.list || this.$el;
                    this.$nextTick(function () {
                        sortable(list, {
                            handle: config.handle || '.drag-handle',
                            group: config.group || undefined,
                            fallbackOnBody: true,
                            swapThreshold: 0.65,
                            onEnd: function () { self.changed(list); }
                        });
                    });
                },
                changed: function (list) {
                    var self = this;
                    var ids = Array.prototype.map.call(list.querySelectorAll(':scope > [data-id]'), function (el) { return el.getAttribute('data-id'); });
                    Array.prototype.forEach.call(list.querySelectorAll(':scope > [data-id] input[data-position]'), function (input, i) { input.value = i; });
                    markDirty(list);
                    this.$dispatch('sorted', { ids: ids });
                    if (!config.url) return;
                    this.saving = true;
                    request(config.url, { method: 'POST', data: { ids: ids } }).then(function (json) {
                        toast((json && json.message) || 'New order saved');
                    }).catch(function (err) {
                        toast(err.message, 'error');
                    }).then(function () { self.saving = false; });
                }
            };
        });

        /* TinyMCE wrapper: <x-admin.rich-editor> */
        Alpine.data('richEditor', function (config) {
            return {
                ready: false,
                instance: null,
                init: function () {
                    var self = this;
                    var start = function () {
                        editor(self.$refs.textarea, config).then(function (ed) {
                            self.instance = ed;
                            self.ready = true;
                        });
                    };
                    if (window.tinymce) { start(); } else { window.addEventListener('load', start, { once: true }); }
                },
                destroy: function () {
                    if (this.instance) { try { this.instance.remove(); } catch (e) { /* already gone */ } }
                }
            };
        });

        /* Live character counter: x-data="charCount(160)" around an input with x-ref="field" */
        Alpine.data('charCount', function (max) {
            return {
                max: max,
                length: 0,
                init: function () {
                    var self = this;
                    var field = this.$refs.field || this.$el.querySelector('input, textarea');
                    if (!field) return;
                    var update = function () { self.length = field.value.length; };
                    field.addEventListener('input', update);
                    update();
                },
                get over() { return this.max && this.length > this.max; }
            };
        });

        /* Slug that follows another field until edited by hand: x-data="slugField('#name', true)" around the slug input */
        Alpine.data('slugField', function (sourceSelector, auto) {
            return {
                auto: auto !== false,
                init: function () {
                    var self = this;
                    var input = this.$el.tagName === 'INPUT' ? this.$el : this.$el.querySelector('input');
                    var source = document.querySelector(sourceSelector);
                    if (!input || !source) return;
                    if (input.value) this.auto = false;
                    source.addEventListener('input', function () {
                        if (!self.auto) return;
                        input.value = slugify(source.value);
                        markDirty(input);
                    });
                    input.addEventListener('input', function (e) { if (e.isTrusted) self.auto = input.value === ''; });
                    input.addEventListener('blur', function () { input.value = slugify(input.value); });
                }
            };
        });

        /* Modal / drawer: <x-admin.modal name="…"> */
        Alpine.data('modal', function (name, startOpen) {
            return {
                name: name,
                open: !!startOpen,
                show: function () { this.open = true; this.$dispatch('modal-opened', name); },
                hide: function () { this.open = false; this.$dispatch('modal-closed', name); }
            };
        });

        /* Dropdown menu with arrow-key navigation */
        Alpine.data('dropdown', function () {
            return {
                open: false,
                toggle: function () { this.open ? this.close() : this.show(); },
                show: function () {
                    var self = this;
                    this.open = true;
                    this.$nextTick(function () {
                        var first = self.$refs.menu && self.$refs.menu.querySelector('a, button:not([disabled])');
                        if (first) first.focus();
                    });
                },
                close: function (focusButton) {
                    if (!this.open) return;
                    this.open = false;
                    if (focusButton && this.$refs.button) this.$refs.button.focus();
                },
                nav: function (e) {
                    var items = Array.prototype.slice.call(this.$refs.menu.querySelectorAll('a, button:not([disabled])'));
                    var i = items.indexOf(document.activeElement);
                    if (e.key === 'ArrowDown') { e.preventDefault(); (items[i + 1] || items[0]).focus(); }
                    if (e.key === 'ArrowUp') { e.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
                }
            };
        });

        /* In-page tabs. Opens the first tab containing a validation error; remembers the tab in the URL hash. */
        Alpine.data('tabs', function (initial) {
            return {
                tab: initial,
                init: function () {
                    var hash = window.location.hash.replace('#tab-', '');
                    if (hash && this.$el.querySelector('[data-tab="' + hash + '"]')) this.tab = hash;
                    var errorPanel = this.$el.querySelector('[data-tab-panel] .field__error');
                    if (errorPanel) this.tab = errorPanel.closest('[data-tab-panel]').getAttribute('data-tab-panel');
                    this.$watch('tab', function (t) { history.replaceState(null, '', '#tab-' + t); });
                },
                select: function (t) { this.tab = t; }
            };
        });
    });

    // Global behaviours -----------------------------------------------------------------------------

    // Confirm before submit: <form data-confirm> or <button data-confirm>
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var submitter = e.submitter;
        var source = (submitter && submitter.hasAttribute('data-confirm')) ? submitter : (form.hasAttribute('data-confirm') ? form : null);
        if (!source || form.__adminConfirmed) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        confirmDialog({
            title: source.getAttribute('data-confirm-title') || source.getAttribute('data-confirm') || 'Are you sure?',
            message: source.getAttribute('data-confirm-title') ? source.getAttribute('data-confirm') : '',
            confirmText: source.getAttribute('data-confirm-button') || undefined,
            danger: source.getAttribute('data-confirm-danger') !== 'false'
        }).then(function (ok) {
            if (!ok) return;
            form.__adminConfirmed = true;
            if (submitter && typeof form.requestSubmit === 'function') { form.requestSubmit(submitter); } else { form.submit(); }
            form.__adminConfirmed = false;
        });
    }, true);

    // Links that need a confirmation first: <a href data-confirm="…">
    document.addEventListener('click', function (e) {
        var link = e.target.closest && e.target.closest('a[data-confirm]');
        if (!link) return;
        e.preventDefault();
        confirmDialog({
            title: link.getAttribute('data-confirm-title') || link.getAttribute('data-confirm'),
            message: link.getAttribute('data-confirm-title') ? link.getAttribute('data-confirm') : '',
            confirmText: link.getAttribute('data-confirm-button') || 'Continue',
            danger: link.getAttribute('data-confirm-danger') === 'true'
        }).then(function (ok) { if (ok) window.location.href = link.href; });
    });

    // Loading state on submit (and no double submits)
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || form.hasAttribute('data-no-loading')) return;
        if (form.target && form.target !== '_self') return;
        var button = e.submitter || form.querySelector('button[type="submit"], button:not([type])');
        setTimeout(function () {
            if (e.defaultPrevented) return;
            if (button) { button.classList.add('is-loading'); button.setAttribute('aria-busy', 'true'); }
            var buttons = form.querySelectorAll('button[type="submit"], button:not([type])');
            if (form.id) buttons = Array.prototype.slice.call(buttons).concat(Array.prototype.slice.call(document.querySelectorAll('button[form="' + form.id + '"]')));
            Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; b.setAttribute('data-was-disabled-by-submit', ''); });
        }, 0);
    });

    // Undo loading states when the page is restored from the back/forward cache
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        Array.prototype.forEach.call(document.querySelectorAll('[data-was-disabled-by-submit]'), function (b) {
            b.disabled = false;
            b.classList.remove('is-loading');
            b.removeAttribute('aria-busy');
            b.removeAttribute('data-was-disabled-by-submit');
        });
    });

    // Keyboard shortcuts: "/" -> page search (or global), Ctrl/⌘+K -> global search
    document.addEventListener('keydown', function (e) {
        var global = document.getElementById('global-search');
        if ((e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey)) {
            if (global) { e.preventDefault(); global.focus(); global.select(); }
            return;
        }
        if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey || isTyping(document.activeElement)) return;
        var target = document.querySelector('[data-page-search]') || global;
        if (target) {
            e.preventDefault();
            target.focus();
            if (target.select) target.select();
        }
    });

    // Flash messages + first validation error
    document.addEventListener('DOMContentLoaded', function () {
        var flash = document.getElementById('admin-flash');
        if (flash) {
            try {
                JSON.parse(flash.textContent || '[]').forEach(function (f) { toast(f.message, f.type); });
            } catch (err) { /* ignore malformed flash */ }
        }
        var firstError = document.querySelector('.field__error');
        if (firstError) {
            var field = firstError.closest('.field') || firstError.parentElement;
            var control = field && field.querySelector('input:not([type="hidden"]), select, textarea');
            setTimeout(function () {
                (field || firstError).scrollIntoView({ block: 'center', behavior: 'smooth' });
                if (control && !control.closest('[x-show]')) control.focus({ preventScroll: true });
            }, 150);
        }
    });
})();
