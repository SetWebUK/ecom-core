/*!
 * Pine Commerce back office – content & settings components (no build step).
 *
 * Loaded with @push('vendor') by the content views and the <x-admin.link-input>, <x-admin.html-editor>,
 * <x-admin.media-bridge> components, so it runs before admin.js; everything is registered on alpine:init,
 * when window.Admin already exists. Adds to window.Admin:
 *
 *   Admin.mediaUrl(path)                      "uploads/2026/09/x.jpg" -> "/storage/uploads/2026/09/x.jpg" (URLs kept)
 *   Admin.pickImage(function (item) {…})      media library dialog (needs <x-admin.media-bridge/>) -> {path, url, alt}
 *   Admin.pickLink(function (url, item) {…}, currentUrl)   link search dialog (needs <x-admin.link-picker/>)
 *   Admin.editHtml({ value, title }, function (html) {…})  rich-text dialog (needs <x-admin.html-editor/>)
 *
 * Alpine components: blocksEditor (page blocks / home page builder), menuBuilder (nested drag-and-drop menu tree),
 * mediaManager (media library page), linkPicker, htmlEditor, linkInput, listInput.
 */
(function () {
    'use strict';

    var seq = 0;
    function uid() { seq += 1; return 'k' + seq + '-' + Math.random().toString(36).slice(2, 7); }

    function mediaUrl(path) {
        if (!path) return '';
        path = String(path);
        var wp = path.match(/\/wp-content\/uploads\/(.+)$/);
        if (wp) return '/storage/uploads/' + wp[1];
        if (/^(https?:)?\/\//i.test(path) || path.charAt(0) === '/') return path;
        return '/storage/' + path.replace(/^\/+/, '');
    }

    function clone(value) { return JSON.parse(JSON.stringify(value)); }

    /** Give every object inside an array a stable key (x-for :key) and an open/closed flag. */
    function prepare(value, openByDefault) {
        if (Array.isArray(value)) {
            value.forEach(function (item) {
                if (item && typeof item === 'object' && !Array.isArray(item)) {
                    if (!item._k) item._k = uid();
                    if (item._open === undefined) item._open = openByDefault !== undefined ? openByDefault : value.length <= 3;
                    Object.keys(item).forEach(function (k) { if (k.charAt(0) !== '_') prepare(item[k]); });
                }
            });
        } else if (value && typeof value === 'object') {
            Object.keys(value).forEach(function (k) { prepare(value[k]); });
        }
        return value;
    }

    /**
     * SortableJS on a list rendered by Alpine's x-for. Sortable moves DOM nodes, but Alpine owns them, so on drop the node
     * is put back and the data array is reordered instead (Alpine then moves the DOM itself).
     */
    function bindSortable(el, getList, options) {
        options = options || {};
        var draggable = options.draggable || '[data-sortable-item]';
        return window.Admin.sortable(el, {
            handle: options.handle || '.drag-handle',
            draggable: draggable,
            onEnd: function (evt) {
                var from = evt.oldDraggableIndex, to = evt.newDraggableIndex;
                if (from === undefined || to === undefined || from === to) return;
                var parent = evt.from, moved = evt.item;
                parent.removeChild(moved);
                var siblings = parent.querySelectorAll(':scope > ' + draggable);
                parent.insertBefore(moved, siblings[from] || null);
                var list = getList();
                list.splice(to, 0, list.splice(from, 1)[0]);
            }
        });
    }

    document.addEventListener('alpine:init', function () {
        var Alpine = window.Alpine;
        var Admin = window.Admin;

        Admin.mediaUrl = mediaUrl;
        Admin.pickImage = function (callback) {
            window.dispatchEvent(new CustomEvent('admin:media-pick', { detail: { callback: callback } }));
        };
        Admin.pickLink = function (callback, value) {
            window.dispatchEvent(new CustomEvent('admin:link-pick', { detail: { callback: callback, value: value || '' } }));
        };
        Admin.editHtml = function (opts, callback) {
            opts = opts || {};
            window.dispatchEvent(new CustomEvent('admin:html-edit', { detail: { value: opts.value || '', title: opts.title || 'Edit text', callback: callback } }));
        };

        /*
         * Structured page data (home page builder, FAQ lists). Inputs are named blocks[…] and bound with x-model to `b`.
         * config: { blocks, errors: {'blocks.hero.title': ['…']}, blanks: {'categories.tiles': {title:'', …}} }
         */
        Alpine.data('blocksEditor', function (config) {
            return {
                b: prepare(config.blocks || {}),
                errors: config.errors || {},
                blanks: config.blanks || {},
                init: function () {
                    var self = this;
                    this.$watch('b', function () { self.$nextTick(function () { Admin.markDirty(self.$el); }); });
                },
                err: function (key) {
                    var e = this.errors[key];
                    return e ? (Array.isArray(e) ? e[0] : e) : '';
                },
                hasErrors: function (prefix) {
                    return Object.keys(this.errors).some(function (k) { return k === prefix || k.indexOf(prefix + '.') === 0; });
                },
                fid: function (name) { return 'f-' + String(name).replace(/[^A-Za-z0-9_-]+/g, '-').replace(/^-+|-+$/g, ''); },
                add: function (list, blankKey) {
                    var item = clone(this.blanks[blankKey] || {});
                    item._k = uid();
                    item._open = true;
                    list.push(item);
                    var root = this.$el;
                    this.$nextTick(function () {
                        var el = root.querySelector('[data-item-key="' + item._k + '"] input:not([type=hidden]), [data-item-key="' + item._k + '"] textarea');
                        if (el) el.focus();
                    });
                },
                remove: function (list, i) { list.splice(i, 1); },
                move: function (list, i, delta) {
                    var j = i + delta;
                    if (j < 0 || j >= list.length) return;
                    list.splice(j, 0, list.splice(i, 1)[0]);
                },
                sortable: function (el, getList) {
                    this.$nextTick(function () { bindSortable(el, getList); });
                },
                pickImage: function (obj, prop) { Admin.pickImage(function (m) { obj[prop] = m.path; }); },
                pickLink: function (obj, prop) { Admin.pickLink(function (url) { obj[prop] = url; }, obj[prop]); },
                editHtml: function (obj, prop, title) {
                    Admin.editHtml({ value: obj[prop], title: title }, function (html) { obj[prop] = html; });
                },
                mediaUrl: mediaUrl,
                preview: function (html) {
                    var text = String(html || '').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
                    return text.length > 140 ? text.slice(0, 137) + '…' : text;
                }
            };
        });

        /*
         * Menu builder: nested drag-and-drop tree (3 levels) saved as JSON in one POST.
         * config: { items: [{id,label,url,badge,icon,css_class,open_in_new_tab,children:[…]}], maxDepth: 3 }
         */
        Alpine.data('menuBuilder', function (config) {
            var blank = function () {
                return { _k: uid(), _open: true, id: null, label: '', url: '', badge: '', icon: '', css_class: '', open_in_new_tab: false, children: [] };
            };
            var walk = function (nodes, fn, parent) {
                nodes.forEach(function (node, index) { fn(node, nodes, index, parent); walk(node.children || [], fn, node); });
            };
            var prepareTree = function (nodes) {
                walk(nodes, function (node) {
                    node._k = node._k || uid();
                    node._open = false;
                    node.children = node.children || [];
                    ['label', 'url', 'badge', 'icon', 'css_class'].forEach(function (k) { if (node[k] === null || node[k] === undefined) node[k] = ''; });
                    node.open_in_new_tab = !!node.open_in_new_tab;
                });
                return nodes;
            };
            var strip = function (nodes) {
                return nodes.map(function (n) {
                    return { id: n.id || null, label: n.label, url: n.url, badge: n.badge, icon: n.icon, css_class: n.css_class, open_in_new_tab: !!n.open_in_new_tab, children: strip(n.children || []) };
                });
            };
            var height = function (node) {
                var h = 1;
                (node.children || []).forEach(function (c) { h = Math.max(h, 1 + height(c)); });
                return h;
            };

            return {
                items: prepareTree(config.items || []),
                maxDepth: config.maxDepth || 3,
                dragging: false,
                init: function () {
                    var self = this;
                    this.$watch('items', function () { self.$nextTick(function () { Admin.markDirty(self.$refs.treeInput); }); });
                    var form = this.$el.closest('form');
                    if (form) {
                        form.addEventListener('submit', function (e) {
                            var missing = null;
                            walk(self.items, function (node) { if (!missing && !String(node.label || '').trim()) missing = node; });
                            if (missing) {
                                e.preventDefault();
                                e.stopImmediatePropagation();
                                missing._open = true;
                                Admin.toast('Every menu item needs a label.', 'error');
                                self.$nextTick(function () {
                                    var input = self.$el.querySelector('[data-key="' + missing._k + '"] [data-label-input]');
                                    if (input) { input.focus(); input.scrollIntoView({ block: 'center' }); }
                                });
                            }
                        }, true);
                    }
                },
                get serialized() { return JSON.stringify(strip(this.items)); },
                get count() { var n = 0; walk(this.items, function () { n++; }); return n; },
                find: function (key) {
                    var found = null;
                    walk(this.items, function (node, list, index, parent) { if (node._k === key) found = { node: node, list: list, index: index, parent: parent }; });
                    return found;
                },
                listFor: function (parentKey) {
                    if (!parentKey || parentKey === 'root') return this.items;
                    var hit = this.find(parentKey);
                    return hit ? hit.node.children : null;
                },
                depthOf: function (key) {
                    var depth = 0, self = this, hit = this.find(key);
                    while (hit) { depth++; hit = hit.parent ? self.find(hit.parent._k) : null; }
                    return depth;
                },
                bindList: function (el) {
                    var self = this;
                    if (el._sortable) return;
                    el._sortable = window.Admin.sortable(el, {
                        group: 'menu-tree',
                        handle: '.drag-handle',
                        draggable: '.menu-node',
                        fallbackOnBody: true,
                        swapThreshold: 0.65,
                        emptyInsertThreshold: 14,
                        onStart: function () { self.dragging = true; },
                        onMove: function (evt) {
                            var hit = self.find(evt.dragged.getAttribute('data-key'));
                            var depth = parseInt(evt.to.getAttribute('data-depth'), 10) || 1;
                            return !!hit && depth + height(hit.node) - 1 <= self.maxDepth;
                        },
                        onEnd: function (evt) {
                            self.dragging = false;
                            var from = evt.oldDraggableIndex, to = evt.newDraggableIndex;
                            if (from === undefined || to === undefined || (evt.from === evt.to && from === to)) return;
                            var moved = evt.item;
                            evt.to.removeChild(moved);
                            var siblings = evt.from.querySelectorAll(':scope > .menu-node');
                            evt.from.insertBefore(moved, siblings[from] || null);
                            var source = self.listFor(evt.from.getAttribute('data-parent'));
                            var target = self.listFor(evt.to.getAttribute('data-parent'));
                            if (!source || !target) return;
                            var node = source.splice(from, 1)[0];
                            target.splice(to, 0, node);
                        }
                    });
                },
                add: function (parent) {
                    var node = blank();
                    (parent ? parent.children : this.items).push(node);
                    this.focusNode(node);
                },
                addLink: function () {
                    var self = this;
                    Admin.pickLink(function (url, item) {
                        var node = blank();
                        node.label = (item && item.label) || '';
                        node.url = url;
                        node._open = !node.label;
                        self.items.push(node);
                        self.focusNode(node);
                    });
                },
                focusNode: function (node) {
                    var root = this.$el;
                    this.$nextTick(function () {
                        var el = root.querySelector('[data-key="' + node._k + '"]');
                        if (!el) return;
                        el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        var input = el.querySelector('[data-label-input]');
                        if (input && node._open) input.focus();
                    });
                },
                remove: function (list, index) {
                    var node = list[index];
                    var go = function () { list.splice(index, 1); };
                    if (node.children && node.children.length) {
                        Admin.confirm({ title: 'Remove “' + (node.label || 'this item') + '”?', message: 'Its ' + node.children.length + ' sub-item(s) will be removed too.', confirmText: 'Remove' })
                            .then(function (ok) { if (ok) go(); });
                    } else {
                        go();
                    }
                },
                moveUp: function (list, i) { if (i > 0) list.splice(i - 1, 0, list.splice(i, 1)[0]); },
                moveDown: function (list, i) { if (i < list.length - 1) list.splice(i + 1, 0, list.splice(i, 1)[0]); },
                canIndent: function (list, i, depth) { return i > 0 && depth + height(list[i]) <= this.maxDepth; },
                indent: function (list, i, depth) {
                    if (!this.canIndent(list, i, depth)) return;
                    var node = list.splice(i, 1)[0];
                    list[i - 1].children.push(node);
                    this.focusNode(node);
                },
                outdent: function (list, i, parentList, parentIndex) {
                    if (!parentList) return;
                    var node = list.splice(i, 1)[0];
                    parentList.splice(parentIndex + 1, 0, node);
                    this.focusNode(node);
                },
                expandAll: function (open) { walk(this.items, function (n) { n._open = open; }); },
                pickLink: function (node) { Admin.pickLink(function (url, item) { node.url = url; if (!node.label && item) node.label = item.label; }, node.url); },
                pickImage: function (node) { Admin.pickImage(function (m) { node.icon = m.path; }); },
                mediaUrl: mediaUrl,
                kind: function (node, depth) {
                    var cls = String(node.css_class || '');
                    if (cls.indexOf('promo') !== -1 && node.icon) return 'Promo image';
                    if (node.icon && !(node.children || []).length && depth > 1) return 'Image tile';
                    if (!node.url) return depth === 1 ? 'No link' : 'Heading';
                    return '';
                }
            };
        });

        /* Link search dialog (one per page, <x-admin.link-picker/>): Admin.pickLink(cb, current) opens it. */
        Alpine.data('linkPicker', function (config) {
            var controller = null;
            return {
                open: false,
                q: '',
                custom: '',
                loading: false,
                groups: [],
                flat: [],
                active: 0,
                callback: null,
                init: function () {
                    var self = this;
                    window.addEventListener('admin:link-pick', function (e) { self.show(e.detail || {}); });
                    this.$watch('q', Admin.debounce(function () { if (self.open) self.search(); }, 200));
                },
                show: function (detail) {
                    this.callback = detail.callback || null;
                    this.custom = detail.value || '';
                    this.q = '';
                    this.open = true;
                    this.search();
                    var self = this;
                    this.$nextTick(function () { self.$refs.search && self.$refs.search.focus(); });
                },
                close: function () { this.open = false; this.callback = null; },
                search: function () {
                    var self = this;
                    if (controller) controller.abort();
                    controller = new AbortController();
                    this.loading = true;
                    Admin.fetch(config.endpoint, { data: { q: this.q.trim() }, signal: controller.signal }).then(function (json) {
                        var flat = [];
                        (json.groups || []).forEach(function (g) { g.items.forEach(function (it) { it._i = flat.length; flat.push(it); }); });
                        self.groups = json.groups || [];
                        self.flat = flat;
                        self.active = 0;
                        self.loading = false;
                    }).catch(function (err) {
                        if (err.name !== 'AbortError') { self.loading = false; Admin.toast(err.message, 'error'); }
                    });
                },
                choose: function (item) {
                    if (!item) return;
                    if (this.callback) this.callback(item.url, item);
                    this.close();
                },
                useCustom: function () {
                    var url = this.custom.trim();
                    if (!url) { this.$refs.custom && this.$refs.custom.focus(); return; }
                    if (/^\s*(javascript|data|vbscript):/i.test(url)) { Admin.toast('That link isn’t allowed.', 'error'); return; }
                    if (this.callback) this.callback(url, { label: '', url: url });
                    this.close();
                },
                onKey: function (e) {
                    if (e.key === 'ArrowDown') { e.preventDefault(); this.active = Math.min(this.active + 1, this.flat.length - 1); this.scroll(); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); this.active = Math.max(this.active - 1, 0); this.scroll(); }
                    else if (e.key === 'Enter') { e.preventDefault(); this.choose(this.flat[this.active]); }
                },
                scroll: function () {
                    var el = this.$root.querySelector('[data-link-index="' + this.active + '"]');
                    if (el) el.scrollIntoView({ block: 'nearest' });
                }
            };
        });

        /* Rich-text dialog (one per page, <x-admin.html-editor/>): Admin.editHtml({value, title}, cb). No focus trap – TinyMCE's own dialogs live outside it. */
        Alpine.data('htmlEditor', function (config) {
            var instance = null;
            var starting = null;
            return {
                open: false,
                ready: false,
                title: 'Edit text',
                callback: null,
                lastFocus: null,
                init: function () {
                    var self = this;
                    window.addEventListener('admin:html-edit', function (e) { self.show(e.detail || {}); });
                },
                show: function (detail) {
                    var self = this;
                    this.lastFocus = document.activeElement;
                    this.title = detail.title || 'Edit text';
                    this.callback = detail.callback || null;
                    this.open = true;
                    var value = detail.value || '';
                    this.$nextTick(function () {
                        if (instance) {
                            instance.setContent(value);
                            instance.undoManager.clear();
                            instance.focus();
                            return;
                        }
                        self.$refs.textarea.value = value;
                        if (!window.tinymce) { self.ready = true; self.$refs.textarea.focus(); return; }
                        starting = starting || Admin.editor(self.$refs.textarea, {
                            height: config.height || 380,
                            uploadUrl: config.uploadUrl,
                            config: { menubar: false, plugins: 'code link lists autolink image table', toolbar: 'bold italic underline | bullist numlist | link | alignleft aligncenter | removeformat | code' }
                        });
                        starting.then(function (ed) {
                            instance = ed;
                            self.ready = true;
                            if (ed) { ed.setContent(self.$refs.textarea.value); ed.undoManager.clear(); ed.focus(); }
                        });
                    });
                },
                save: function () {
                    var html = instance ? instance.getContent() : this.$refs.textarea.value;
                    if (this.callback) this.callback(html);
                    this.close();
                },
                close: function () {
                    this.open = false;
                    this.callback = null;
                    if (this.lastFocus && this.lastFocus.focus) this.lastFocus.focus();
                }
            };
        });

        /* <x-admin.link-input>: a URL field with a "Browse" button (link search). */
        Alpine.data('linkInput', function () {
            return {
                browse: function () {
                    var input = this.$refs.input;
                    Admin.pickLink(function (url) {
                        input.value = url;
                        Admin.markDirty(input);
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }, input.value);
                }
            };
        });

        /* Simple list of short texts (settings "trust badges"): posts name[]. */
        Alpine.data('listInput', function (items, max) {
            return {
                items: (items || []).map(function (text) { return { _k: uid(), text: text }; }),
                max: max || 12,
                init: function () {
                    var self = this;
                    this.$watch('items', function () { self.$nextTick(function () { Admin.markDirty(self.$el); }); });
                    if (this.$refs.list) this.$nextTick(function () { bindSortable(self.$refs.list, function () { return self.items; }); });
                },
                add: function () {
                    if (this.items.length >= this.max) return;
                    var item = { _k: uid(), text: '' };
                    this.items.push(item);
                    var root = this.$el;
                    this.$nextTick(function () { var el = root.querySelector('[data-item-key="' + item._k + '"] input'); if (el) el.focus(); });
                },
                remove: function (i) { this.items.splice(i, 1); }
            };
        });

        /*
         * Media library page: upload queue (drag-and-drop anywhere), detail drawer (alt/title, copy URL, where used, delete).
         * config: { uploadUrl, showUrl: '/admin/media/__ID__', uploadedKey }
         */
        Alpine.data('mediaManager', function (config) {
            return {
                dragover: false,
                queue: 0,
                done: 0,
                failed: 0,
                drawer: { open: false, loading: false, saving: false, item: null, alt: '', title: '' },
                init: function () {
                    var message = null;
                    try { message = sessionStorage.getItem('admin-media-toast'); sessionStorage.removeItem('admin-media-toast'); } catch (e) { /* private mode */ }
                    if (message) Admin.toast(message);
                    var self = this;
                    var params = new URLSearchParams(window.location.search);
                    if (params.get('open')) this.show(params.get('open'));
                    window.addEventListener('dragover', function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1) { e.preventDefault(); self.dragover = true; } });
                },
                onDrop: function (e) {
                    this.dragover = false;
                    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) this.upload(e.dataTransfer.files);
                },
                upload: function (fileList) {
                    var self = this;
                    var files = Array.prototype.slice.call(fileList || []).filter(function (f) {
                        if (/^image\/(jpeg|png|gif|webp|avif)$/.test(f.type)) return true;
                        Admin.toast(f.name + ' isn’t a JPG, PNG, GIF, WebP or AVIF image.', 'error');
                        return false;
                    });
                    if (!files.length) return;
                    this.queue += files.length;
                    var next = 0;
                    var worker = function () {
                        if (next >= files.length) return Promise.resolve();
                        var file = files[next++];
                        var fd = new FormData();
                        fd.append('file', file);
                        return Admin.fetch(config.uploadUrl, { method: 'POST', data: fd })
                            .then(function () { self.done++; })
                            .catch(function (err) { self.failed++; Admin.toast(file.name + ': ' + err.message, 'error'); })
                            .then(worker);
                    };
                    Promise.all([worker(), worker(), worker()]).then(function () {
                        if (self.done + self.failed < self.queue) return;
                        if (self.done) {
                            try { sessionStorage.setItem('admin-media-toast', self.done + (self.done === 1 ? ' image uploaded.' : ' images uploaded.')); } catch (e) { /* ignore */ }
                            var url = new URL(window.location.href);
                            url.searchParams.delete('page');
                            url.searchParams.delete('open');
                            window.location.href = url.toString();
                        } else {
                            self.queue = 0; self.done = 0; self.failed = 0;
                        }
                    });
                    if (this.$refs.file) this.$refs.file.value = '';
                },
                show: function (id) {
                    var self = this, d = this.drawer;
                    d.open = true;
                    d.loading = true;
                    d.item = null;
                    Admin.fetch(config.showUrl.replace('__ID__', encodeURIComponent(id))).then(function (json) {
                        d.item = json;
                        d.alt = json.alt || '';
                        d.title = json.title || '';
                        d.loading = false;
                    }).catch(function (err) {
                        d.loading = false;
                        d.open = false;
                        Admin.toast(err.message, 'error');
                    });
                    this.$nextTick(function () { self.$refs.drawerClose && self.$refs.drawerClose.focus(); });
                },
                close: function () { this.drawer.open = false; },
                save: function () {
                    var d = this.drawer;
                    if (!d.item) return;
                    d.saving = true;
                    Admin.fetch(d.item.update_url, { method: 'PUT', data: { alt: d.alt, title: d.title } }).then(function (json) {
                        d.item.alt = json.alt;
                        d.item.title = json.title;
                        Admin.toast(json.message || 'Image details saved');
                        var tile = document.querySelector('[data-media-id="' + d.item.id + '"] img');
                        if (tile) tile.alt = json.alt || '';
                    }).catch(function (err) { Admin.toast(err.message, 'error'); })
                        .then(function () { d.saving = false; });
                },
                copy: function (text) {
                    var done = function () { Admin.toast('Copied to the clipboard'); };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy this address:', text); });
                    } else {
                        window.prompt('Copy this address:', text);
                    }
                },
                remove: function () {
                    var self = this, d = this.drawer, item = d.item;
                    if (!item) return;
                    var used = item.usage_count > 0;
                    Admin.confirm({
                        title: 'Delete ' + item.filename + '?',
                        message: used
                            ? 'This image is used in ' + item.usage_count + ' place(s) listed under “Where it’s used”. Those images will show as broken. The file is removed from the server and can’t be recovered.'
                            : 'The file is removed from the server and can’t be recovered.',
                        confirmText: used ? 'Delete anyway' : 'Delete image'
                    }).then(function (ok) {
                        if (!ok) return;
                        d.saving = true;
                        Admin.fetch(item.delete_url, { method: 'DELETE' }).then(function (json) {
                            Admin.toast(json.message || 'Image deleted');
                            var el = document.querySelector('[data-media-id="' + item.id + '"]');
                            if (el) el.remove();
                            self.close();
                        }).catch(function (err) { Admin.toast(err.message, 'error'); })
                            .then(function () { d.saving = false; });
                    });
                }
            };
        });
    });
})();
