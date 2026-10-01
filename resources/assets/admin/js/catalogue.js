/*!
 * Pine Commerce – back office: catalogue pages (products, categories, attributes, inventory).
 * Alpine components registered on 'alpine:init'; loaded (deferred, before admin.js/Alpine) by
 * resources/views/admin/products/partials/assets.blade.php. Uses the shared runtime in admin.js (window.Admin).
 *
 *   productForm(config)      one-page product editor: slug/URL, categories, pricing + margin, stock, attributes, variants, specs
 *   quickEdit(config)        Products list: inline price / sale / stock editor (PATCH JSON)
 *   featuredToggle(config)   Products list: star button (POST JSON)
 *   bulkPrice(config)        Products list: "Change prices" dialog with a server-side preview
 *   categoryTree(config)     Categories: nested drag and drop + move up/down + visibility toggle
 *   valueRow(config)         Attribute edit: inline rename / delete of one value
 *   stockCell(config)        Inventory: inline quantity / price inputs that save on change
 *   Catalogue.sortArray(el, opts)  SortableJS for Alpine x-for lists (restores the DOM, then reorders the array)
 */
(function () {
    'use strict';

    var uid = 0;
    function nextUid() { return 'k' + (++uid) + '-' + Date.now().toString(36); }

    function num(v) {
        var n = parseFloat(String(v === undefined || v === null ? '' : v).replace(/[£,\s]/g, ''));
        return isNaN(n) ? null : n;
    }

    function fixed(v) {
        var n = num(v);
        return n === null ? '' : n.toFixed(2);
    }

    /**
     * SortableJS on a container whose children Alpine renders with x-for. Sortable moves DOM nodes, which would
     * fight Alpine, so we put the node back and let the caller reorder its array: opts.onMove(from, to).
     */
    function sortArray(el, opts) {
        if (!el || !window.Sortable) return null;
        var draggable = opts.draggable || '[data-sort-item]';
        return Admin.sortable(el, {
            handle: opts.handle || '.drag-handle',
            draggable: draggable,
            onEnd: function (evt) {
                var from = evt.oldDraggableIndex, to = evt.newDraggableIndex;
                if (from === undefined || to === undefined || from === to) return;
                el.removeChild(evt.item);
                var rest = el.querySelectorAll(':scope > ' + draggable);
                var ref = rest[from] || (rest.length ? rest[rest.length - 1].nextSibling : null);
                el.insertBefore(evt.item, ref);
                opts.onMove(from, to);
            }
        });
    }

    function move(list, from, to) {
        var copy = list.slice();
        copy.splice(to, 0, copy.splice(from, 1)[0]);
        return copy;
    }

    /**
     * Keep imported HTML byte-for-byte: TinyMCE re-serialises content when it loads (adds <tbody>, re-indents…).
     * If a rich-text field is left as the editor loaded it, the ORIGINAL markup is submitted instead of TinyMCE's
     * normalised copy, so saving a product for another change never rewrites its description.
     */
    function rememberEditorOriginals() {
        document.querySelectorAll('.rich-editor textarea').forEach(function (t) {
            if (t.__catalogueOriginal === undefined) t.__catalogueOriginal = t.value;
        });
    }
    rememberEditorOriginals();
    document.addEventListener('admin:rebase', function (e) {
        var t = e.target;
        if (t && t.tagName === 'TEXTAREA' && t.__catalogueNormalised === undefined) t.__catalogueNormalised = t.value;
    }, true);
    function restoreUntouchedEditors(form) {
        if (!form || !form.querySelectorAll) return;
        form.querySelectorAll('.rich-editor textarea').forEach(function (t) {
            var ed = window.tinymce && t.id ? window.tinymce.get(t.id) : null;
            if (ed) ed.save();
            if (t.__catalogueOriginal !== undefined && t.__catalogueNormalised !== undefined && t.value === t.__catalogueNormalised) {
                t.value = t.__catalogueOriginal;
            }
        });
    }
    document.addEventListener('submit', function (e) { restoreUntouchedEditors(e.target); }, true);

    window.Catalogue = { sortArray: sortArray, num: num, fixed: fixed, restoreUntouchedEditors: restoreUntouchedEditors };

    document.addEventListener('alpine:init', function () {
        var Alpine = window.Alpine;

        /* ------------------------------------------------------------------------------------------
         * Product editor
         * config: { type, name, slug, slugAuto, siteUrl, categoryPaths {id: path}, categoryIds [], primaryId,
         *           regular, sale, cost, schedule, manageStock, attributes [{id,name,slug,values:[{id,value,slug}]}],
         *           rows [{attribute_id, values:[ids], visible, variation}], variations [...], specs [...],
         *           valueUrl (template with __ID__), attributeUrl, productSku }
         * ------------------------------------------------------------------------------------------ */
        Alpine.data('productForm', function (config) {
            var attributes = (config.attributes || []).map(function (a) {
                return { id: a.id, name: a.name, slug: a.slug, values: a.values || [] };
            });
            var rootEl = null; // the component's element (methods may be called from teleported dialogs)

            return {
                type: config.type || 'simple',
                name: config.name || '',
                slug: config.slug || '',
                slugAuto: !!config.slugAuto,
                originalSlug: config.originalSlug || '',
                siteUrl: (config.siteUrl || '').replace(/\/$/, ''),
                categoryPaths: config.categoryPaths || {},
                categoryNames: config.categoryNames || {},
                categoryIds: (config.categoryIds || []).map(String),
                primaryId: config.primaryId ? String(config.primaryId) : '',
                catQuery: '',
                catSelectedOnly: false,
                regular: config.regular || '',
                sale: config.sale || '',
                cost: config.cost || '',
                schedule: !!config.schedule,
                manageStock: !!config.manageStock,
                attributes: attributes,
                rows: (config.rows || []).map(function (r) {
                    return { uid: nextUid(), attribute_id: r.attribute_id, values: (r.values || []).slice(), visible: r.visible !== false, variation: !!r.variation, query: '', open: false, busy: false };
                }),
                variations: (config.variations || []).map(function (v) {
                    return Object.assign({ uid: nextUid() }, v, { options: v.options || {} });
                }),
                specs: (config.specs || []).map(function (s) { return Object.assign({ uid: nextUid() }, s); }),
                bulkPrice: '',
                bulkStock: '',
                imagePop: null,
                newAttribute: '',

                init: function () {
                    var self = this;
                    rootEl = this.$el;
                    this.$watch('name', function (v) {
                        if (self.slugAuto) self.slug = Admin.slugify(v);
                    });
                    this.$watch('categoryIds', function (ids) {
                        if (!ids.length) { self.primaryId = ''; return; }
                        if (ids.indexOf(self.primaryId) === -1) self.primaryId = ids[0];
                    });
                    this.$nextTick(function () {
                        // Show the first ticked category in the scrolling checklist
                        var tree = self.$refs.catTree, first = tree && tree.querySelector('input[type=checkbox]:checked');
                        if (tree && first) tree.scrollTop = Math.max(0, first.closest('.check-tree__item').offsetTop - tree.offsetTop - 8);
                        if (self.$refs.specList) {
                            sortArray(self.$refs.specList, { onMove: function (from, to) { self.specs = move(self.specs, from, to); self.touch(); } });
                        }
                        if (self.$refs.attrList) {
                            sortArray(self.$refs.attrList, { onMove: function (from, to) {
                                // positions are shared with the variant options: reorder within the full list
                                var visible = self.rows.filter(function (r) { return !self.isVariantRow(r); });
                                var a = self.rows.indexOf(visible[from]), b = self.rows.indexOf(visible[to]);
                                self.rows = move(self.rows, a, b);
                                self.touch();
                            } });
                        }
                    });
                },

                /** Tell the dirty-form tracker something changed (after array reorders etc.). */
                touch: function () {
                    setTimeout(function () { Admin.markDirty(rootEl); }, 0);
                },

                // URL ----------------------------------------------------------------------------
                slugInput: function (e) {
                    this.slugAuto = e.target.value === '';
                },
                slugBlur: function () {
                    this.slug = Admin.slugify(this.slug);
                },
                get primaryPath() {
                    return this.primaryId && this.categoryPaths[this.primaryId] ? this.categoryPaths[this.primaryId] : 'product';
                },
                get urlPreview() {
                    return this.siteUrl + '/' + this.primaryPath + '/' + (this.slug || 'your-product') + '/';
                },
                get slugChanged() {
                    return !!this.originalSlug && this.slug !== '' && this.slug !== this.originalSlug;
                },

                // Categories ------------------------------------------------------------------------
                catVisible: function (id, label) {
                    if (this.catSelectedOnly && this.categoryIds.indexOf(String(id)) === -1) return false;
                    var q = this.catQuery.trim().toLowerCase();
                    return !q || label.toLowerCase().indexOf(q) !== -1;
                },
                isChecked: function (id) { return this.categoryIds.indexOf(String(id)) !== -1; },
                get selectedCategories() {
                    var self = this;
                    return this.categoryIds.map(function (id) { return { id: id, name: self.categoryNames[id] || ('#' + id), main: id === self.primaryId }; });
                },
                uncheck: function (id) {
                    this.categoryIds = this.categoryIds.filter(function (c) { return c !== String(id); });
                },

                // Pricing -----------------------------------------------------------------------------
                get currentPrice() {
                    var s = num(this.sale), r = num(this.regular);
                    return s !== null && r !== null && s < r ? s : r;
                },
                get profit() {
                    var p = this.currentPrice, c = num(this.cost);
                    return p === null || c === null ? null : p - c;
                },
                get margin() {
                    var p = this.currentPrice, profit = this.profit;
                    return p && profit !== null ? (profit / p) * 100 : null;
                },
                get saleNote() {
                    var s = num(this.sale), r = num(this.regular);
                    if (s === null) return '';
                    if (r === null) return 'Enter the regular price too.';
                    if (s >= r) return 'The sale price must be lower than the price.';
                    return Math.round((1 - s / r) * 100) + '% off – customers see ' + Admin.money(r) + ' crossed out.';
                },

                // Attributes ---------------------------------------------------------------------------
                attr: function (id) {
                    id = parseInt(id, 10);
                    return this.attributes.find(function (a) { return a.id === id; }) || null;
                },
                findValue: function (attributeId, valueId) {
                    var a = this.attr(attributeId);
                    return a ? a.values.find(function (v) { return v.id === valueId; }) || null : null;
                },
                valueLabel: function (attributeId, valueId) {
                    var v = this.findValue(attributeId, valueId);
                    return v ? v.value : '#' + valueId;
                },
                isVariantRow: function (row) {
                    return this.type === 'variable' && row.variation;
                },
                usedAttribute: function (id, except) {
                    return this.rows.some(function (r) { return r !== except && String(r.attribute_id) === String(id); });
                },
                availableAttributes: function (row) {
                    var self = this;
                    return this.attributes.filter(function (a) { return !self.usedAttribute(a.id, row); });
                },
                addRow: function (variation) {
                    this.rows.push({ uid: nextUid(), attribute_id: '', values: [], visible: true, variation: !!variation, query: '', open: false, busy: false });
                    this.touch();
                },
                removeRow: function (row) {
                    this.rows = this.rows.filter(function (r) { return r !== row; });
                    this.touch();
                },
                suggestions: function (row) {
                    var a = this.attr(row.attribute_id);
                    if (!a) return [];
                    var q = (row.query || '').trim().toLowerCase();
                    return a.values.filter(function (v) {
                        return row.values.indexOf(v.id) === -1 && (!q || v.value.toLowerCase().indexOf(q) !== -1);
                    }).slice(0, 60);
                },
                exactMatch: function (row) {
                    var a = this.attr(row.attribute_id), q = (row.query || '').trim().toLowerCase();
                    if (!a || !q) return null;
                    return a.values.find(function (v) { return v.value.toLowerCase() === q; }) || null;
                },
                pickValue: function (row, value) {
                    if (row.values.indexOf(value.id) === -1) row.values.push(value.id);
                    row.query = '';
                    this.touch();
                },
                removeValue: function (row, id) {
                    row.values = row.values.filter(function (v) { return v !== id; });
                    this.touch();
                },
                selectAllValues: function (row) {
                    var a = this.attr(row.attribute_id);
                    if (!a) return;
                    row.values = a.values.map(function (v) { return v.id; });
                    this.touch();
                },
                /** Enter in the value box: pick the exact/first match, or create a new value on the server. */
                addValue: function (row) {
                    var self = this;
                    var q = (row.query || '').trim();
                    if (!q) return;
                    var match = this.exactMatch(row);
                    if (match) { this.pickValue(row, match); return; }
                    var a = this.attr(row.attribute_id);
                    if (!a || row.busy) return;
                    row.busy = true;
                    Admin.fetch(config.valueUrl.replace('__ID__', a.id), { method: 'POST', data: { value: q } }).then(function (json) {
                        var value = { id: json.id, value: json.value, slug: json.slug };
                        if (!a.values.some(function (v) { return v.id === value.id; })) a.values.push(value);
                        self.pickValue(row, value);
                        Admin.toast(json.message || 'Value added');
                    }).catch(function (err) {
                        Admin.toast(err.message, 'error');
                    }).then(function () { row.busy = false; });
                },
                valueKey: function (row, e) {
                    if (e.key === 'Enter') { e.preventDefault(); this.addValue(row); }
                    else if (e.key === 'Backspace' && !row.query && row.values.length) { row.values.pop(); this.touch(); }
                    else if (e.key === 'Escape') { row.open = false; }
                },
                createAttribute: function () {
                    var self = this;
                    var name = (this.newAttribute || '').trim();
                    if (!name) return;
                    Admin.fetch(config.attributeUrl, { method: 'POST', data: { name: name, is_filterable: 0 } }).then(function (json) {
                        self.attributes.push({ id: json.id, name: json.name, slug: json.slug, values: [] });
                        self.rows.push({ uid: nextUid(), attribute_id: json.id, values: [], visible: true, variation: self.type === 'variable', query: '', open: true, busy: false });
                        self.newAttribute = '';
                        Admin.closeModal('new-attribute');
                        Admin.toast(json.message || 'Attribute created');
                        self.touch();
                    }).catch(function (err) { Admin.toast(err.message, 'error'); });
                },

                // Variants -------------------------------------------------------------------------------
                get variantRows() {
                    var self = this;
                    return this.rows.filter(function (r) { return r.variation && self.attr(r.attribute_id); });
                },
                optionLabel: function (variation) {
                    var self = this;
                    var parts = Object.keys(variation.options || {}).map(function (slug) {
                        var a = self.attributes.find(function (x) { return x.slug === slug; });
                        var v = a ? a.values.find(function (x) { return x.slug === variation.options[slug]; }) : null;
                        return v ? v.value : variation.options[slug];
                    });
                    return parts.join(' / ') || 'Variant';
                },
                optionsJson: function (variation) {
                    var sorted = {};
                    Object.keys(variation.options || {}).sort().forEach(function (k) { sorted[k] = variation.options[k]; });
                    return JSON.stringify(sorted);
                },
                /** Every combination of the chosen variant options that doesn't exist yet. */
                generate: function () {
                    var self = this;
                    var dims = this.variantRows.filter(function (r) { return r.values.length; }).map(function (r) {
                        var a = self.attr(r.attribute_id);
                        return r.values.map(function (id) {
                            var v = a.values.find(function (x) { return x.id === id; });
                            return v ? { attr: a.slug, value: v.slug } : null;
                        }).filter(Boolean);
                    });
                    if (!dims.length) { Admin.toast('Choose the options first (e.g. Memory: 8GB, 16GB).', 'warning'); return; }
                    var combos = dims.reduce(function (acc, dim) {
                        var out = [];
                        acc.forEach(function (c) { dim.forEach(function (d) { var n = Object.assign({}, c); n[d.attr] = d.value; out.push(n); }); });
                        return out;
                    }, [{}]);
                    var existing = this.variations.map(function (v) { return self.optionsJson(v); });
                    var added = 0;
                    combos.forEach(function (options) {
                        var v = { uid: nextUid(), id: null, options: options, sku: '', regular_price: fixed(self.regular), sale_price: '', stock_quantity: '', stock_status: 'instock', image: '', image_url: null, tax_class: '', is_active: true };
                        if (existing.indexOf(self.optionsJson(v)) !== -1) return;
                        if (config.productSku) {
                            v.sku = config.productSku + '-' + Object.keys(options).sort().map(function (k) { return options[k]; }).join('-').toUpperCase();
                        }
                        self.variations.push(v);
                        added++;
                    });
                    if (combos.length > 100) Admin.toast('That makes ' + combos.length + ' variants – consider fewer options.', 'warning');
                    Admin.toast(added ? added + ' variant' + (added === 1 ? '' : 's') + ' added – set their prices and stock, then save.' : 'All combinations already exist.', added ? 'success' : 'info');
                    this.touch();
                },
                removeVariation: function (variation) {
                    var self = this;
                    Admin.confirm({
                        title: 'Remove this variant?',
                        message: this.optionLabel(variation) + ' will be removed when you save. Past orders keep their details.',
                        confirmText: 'Remove variant'
                    }).then(function (ok) {
                        if (!ok) return;
                        self.variations = self.variations.filter(function (v) { return v !== variation; });
                        self.touch();
                    });
                },
                applyAll: function (field) {
                    var value = field === 'regular_price' ? fixed(this.bulkPrice) : String(this.bulkStock).trim();
                    if (value === '' || (field === 'stock_quantity' && !/^-?\d+$/.test(value))) {
                        Admin.toast(field === 'regular_price' ? 'Enter a price to apply.' : 'Enter a whole number to apply.', 'warning');
                        return;
                    }
                    this.variations.forEach(function (v) { v[field] = value; });
                    Admin.toast((field === 'regular_price' ? 'Price ' + Admin.money(value) : 'Stock ' + value) + ' set on all ' + this.variations.length + ' variants.');
                    this.touch();
                },
                variantImages: function () {
                    var inputs = document.querySelectorAll('#product-media input[name^="images["][name$="[path]"]');
                    return Array.prototype.map.call(inputs, function (input) {
                        var path = input.value;
                        return { path: path, url: '/storage/' + path.replace(/^\/+/, '') };
                    });
                },
                setVariantImage: function (variation, image) {
                    variation.image = image ? image.path : '';
                    variation.image_url = image ? image.url : null;
                    this.imagePop = null;
                    this.touch();
                },

                // Specifications ---------------------------------------------------------------------------
                addSpec: function () {
                    this.specs.push({ uid: nextUid(), key: '', label: '', value: '', description: '' });
                    var self = this;
                    this.$nextTick(function () {
                        var inputs = self.$refs.specList ? self.$refs.specList.querySelectorAll('[data-spec-label]') : [];
                        if (inputs.length) inputs[inputs.length - 1].focus();
                    });
                    this.touch();
                },
                removeSpec: function (spec) {
                    this.specs = this.specs.filter(function (s) { return s !== spec; });
                    this.touch();
                },
                moveSpec: function (index, delta) {
                    var to = index + delta;
                    if (to < 0 || to >= this.specs.length) return;
                    this.specs = move(this.specs, index, to);
                    this.touch();
                }
            };
        });

        /* ------------------------------------------------------------------------------------------
         * Products list: inline quick edit of price / sale price / stock for one row.
         * config: { url, row: {regular_price, sale_price, on_sale, price, manage_stock, stock_quantity, stock_status, stock_label, stock_color} }
         * ------------------------------------------------------------------------------------------ */
        Alpine.data('quickEdit', function (config) {
            var anchor = null; // price button of this row (the popover itself is teleported)
            return {
                row: config.row,
                open: false,
                saving: false,
                errors: {},
                form: {},
                pos: { top: 0, left: 0 },
                /** Fixed position next to the price button (the popover is teleported to <body>). */
                place: function () {
                    var trigger = anchor;
                    if (!trigger) return;
                    var r = trigger.getBoundingClientRect();
                    var pop = document.getElementById(config.popId);
                    var w = pop ? pop.offsetWidth || 300 : 300, h = pop ? pop.offsetHeight || 340 : 340;
                    var top = r.bottom + 6;
                    if (top + h > window.innerHeight - 8 && r.top - h - 6 > 8) top = r.top - h - 6;
                    this.pos = { top: Math.max(8, top), left: Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w)) };
                },
                outside: function (e) {
                    if (!this.open) return;
                    if (e.target.closest('[data-qe-toggle]') && anchor && e.target.closest('tr') === anchor.closest('tr')) return;
                    this.open = false;
                },
                close: function (focus) {
                    this.open = false;
                    if (focus && anchor) anchor.focus();
                },
                toggleEditor: function () {
                    anchor = this.$refs.trigger || anchor;
                    if (this.open) { this.open = false; return; }
                    this.form = {
                        regular_price: this.row.regular_price || '',
                        sale_price: this.row.sale_price || '',
                        stock_quantity: this.row.stock_quantity === null || this.row.stock_quantity === undefined ? '' : String(this.row.stock_quantity),
                        manage_stock: !!this.row.manage_stock,
                        stock_status: this.row.stock_status || 'instock'
                    };
                    this.errors = {};
                    this.open = true;
                    var self = this;
                    this.place();
                    this.$nextTick(function () {
                        self.place();
                        var el = document.getElementById(config.popId + '-first');
                        if (el) { el.focus(); el.select(); }
                    });
                },
                save: function () {
                    var self = this;
                    if (this.saving) return;
                    var data = { regular_price: this.form.regular_price, sale_price: this.form.sale_price, manage_stock: this.form.manage_stock ? 1 : 0 };
                    if (this.form.manage_stock) { data.stock_quantity = this.form.stock_quantity; } else { data.stock_status = this.form.stock_status; }
                    this.saving = true;
                    this.errors = {};
                    Admin.fetch(config.url, { method: 'PATCH', data: data }).then(function (json) {
                        self.row = json.row;
                        self.open = false;
                        Admin.toast(json.message || 'Saved');
                    }).catch(function (err) {
                        self.errors = err.errors || {};
                        Admin.toast(err.message, 'error');
                    }).then(function () { self.saving = false; });
                },
                get priceText() { return this.row.price === null || this.row.price === undefined ? '—' : Admin.money(this.row.price); }
            };
        });

        /* Star toggle for "featured" (config: { url, featured }) */
        Alpine.data('featuredToggle', function (config) {
            return {
                featured: !!config.featured,
                busy: false,
                flip: function () {
                    var self = this;
                    if (this.busy) return;
                    this.busy = true;
                    this.featured = !this.featured; // optimistic
                    Admin.fetch(config.url, { method: 'POST', data: { featured: this.featured ? 1 : 0 } }).then(function (json) {
                        self.featured = !!json.featured;
                        Admin.toast(json.message);
                    }).catch(function (err) {
                        self.featured = !self.featured;
                        Admin.toast(err.message, 'error');
                    }).then(function () { self.busy = false; });
                }
            };
        });

        /* ------------------------------------------------------------------------------------------
         * Products list: "Change prices" dialog. Lives inside the bulk table (reads `selected`).
         * config: { previewUrl }
         * ------------------------------------------------------------------------------------------ */
        Alpine.data('bulkPrice', function (config) {
            return {
                field: 'regular_price',
                mode: 'decrease_percent',
                amount: '',
                round: 'none',
                preview: null,
                loading: false,
                get isPercent() { return this.mode.indexOf('percent') !== -1; },
                reset: function () { this.preview = null; },
                load: Admin.debounce(function () { this.run(); }, 350),
                run: function () {
                    var self = this;
                    if (num(this.amount) === null) { this.preview = null; return; }
                    this.loading = true;
                    Admin.fetch(config.previewUrl, { method: 'POST', data: {
                        ids: this.selected, action: 'adjust_price', field: this.field, mode: this.mode, amount: this.amount, round: this.round
                    } }).then(function (json) {
                        self.preview = json;
                    }).catch(function (err) {
                        self.preview = null;
                        Admin.toast(err.message, 'error');
                    }).then(function () { self.loading = false; });
                }
            };
        });

        /* ------------------------------------------------------------------------------------------
         * Categories page: nested drag and drop (reorder + move into another category), keyboard move up/down.
         * config: { url, confirmMoves: true }
         * ------------------------------------------------------------------------------------------ */
        Alpine.data('categoryTree', function (config) {
            return {
                saving: false,
                sortables: [],
                snapshot: null,
                init: function () {
                    var self = this;
                    this.$nextTick(function () {
                        self.snapshot = self.serialize();
                        self.$root.querySelectorAll('[data-tree-list]').forEach(function (list) {
                            self.sortables.push(Admin.sortable(list, {
                                group: 'categories',
                                handle: '.drag-handle',
                                draggable: '[data-tree-item]',
                                fallbackOnBody: true,
                                swapThreshold: 0.6,
                                emptyInsertThreshold: 12,
                                onStart: function () { self.$root.classList.add('is-dragging'); },
                                onEnd: function (evt) {
                                    self.$root.classList.remove('is-dragging');
                                    if (evt.from === evt.to && evt.oldIndex === evt.newIndex) return;
                                    self.commit(evt.item);
                                }
                            }));
                        });
                    });
                },
                serialize: function () {
                    return Array.prototype.map.call(this.$root.querySelectorAll('[data-tree-item]'), function (li) {
                        var parent = li.parentElement.closest('[data-tree-item]');
                        return { id: parseInt(li.getAttribute('data-id'), 10), parent_id: parent ? parseInt(parent.getAttribute('data-id'), 10) : null };
                    });
                },
                parentOf: function (nodes, id) {
                    var n = nodes.find(function (x) { return x.id === id; });
                    return n ? n.parent_id : null;
                },
                commit: function (item) {
                    var self = this;
                    var nodes = this.serialize();
                    var id = item ? parseInt(item.getAttribute('data-id'), 10) : null;
                    var moved = id !== null && this.parentOf(nodes, id) !== this.parentOf(this.snapshot, id);
                    var ask = moved ? Admin.confirm({
                        title: 'Move “' + item.getAttribute('data-name') + '”?',
                        message: 'Its web address (and the addresses of its products and sub-categories) will change. We’ll redirect the old addresses to the new ones so links and Google keep working.',
                        confirmText: 'Move category',
                        danger: false
                    }) : Promise.resolve(true);
                    ask.then(function (ok) {
                        if (!ok) { window.location.reload(); return; }
                        self.saving = true;
                        Admin.fetch(config.url, { method: 'POST', data: { nodes: nodes, redirects: 1 } }).then(function (json) {
                            self.snapshot = nodes;
                            Admin.toast(json.message || 'Saved');
                            if (json.paths) {
                                self.$root.querySelectorAll('[data-path-for]').forEach(function (el) {
                                    var p = json.paths[el.getAttribute('data-path-for')];
                                    if (p) el.textContent = '/' + p + '/';
                                });
                            }
                            self.$root.querySelectorAll('[data-tree-item]').forEach(function (li) {
                                var depth = 0, p = li.parentElement.closest('[data-tree-item]');
                                while (p) { depth++; p = p.parentElement.closest('[data-tree-item]'); }
                                li.style.setProperty('--depth', depth);
                            });
                        }).catch(function (err) {
                            Admin.toast(err.message + ' Reloading…', 'error');
                            setTimeout(function () { window.location.reload(); }, 1500);
                        }).then(function () { self.saving = false; });
                    });
                },
                /** Keyboard alternative to dragging: swap with the previous/next sibling. */
                shift: function (button, delta) {
                    var li = button.closest('[data-tree-item]');
                    var list = li.parentElement;
                    var siblings = Array.prototype.filter.call(list.children, function (c) { return c.hasAttribute('data-tree-item'); });
                    var i = siblings.indexOf(li), j = i + delta;
                    if (j < 0 || j >= siblings.length) return;
                    if (delta < 0) { list.insertBefore(li, siblings[j]); } else { list.insertBefore(li, siblings[j].nextSibling); }
                    button.focus();
                    this.commit(null);
                },
                toggleVisible: function (button, url) {
                    var visible = button.getAttribute('aria-pressed') !== 'true';
                    Admin.fetch(url, { method: 'POST', data: { visible: visible ? 1 : 0 } }).then(function (json) {
                        button.setAttribute('aria-pressed', json.visible ? 'true' : 'false');
                        var row = button.closest('[data-tree-item]');
                        if (row) row.classList.toggle('is-hidden', !json.visible);
                        Admin.toast(json.message);
                    }).catch(function (err) { Admin.toast(err.message, 'error'); });
                }
            };
        });

        /* ------------------------------------------------------------------------------------------
         * Attribute page: one value row – inline rename + delete (JSON).
         * config: { url, value, used }
         * ------------------------------------------------------------------------------------------ */
        Alpine.data('valueRow', function (config) {
            return {
                value: config.value,
                draft: config.value,
                editing: false,
                saving: false,
                removed: false,
                edit: function () {
                    this.draft = this.value;
                    this.editing = true;
                    var self = this;
                    this.$nextTick(function () { self.$refs.input.focus(); self.$refs.input.select(); });
                },
                cancel: function () { this.editing = false; this.draft = this.value; },
                save: function () {
                    var self = this;
                    var text = (this.draft || '').trim();
                    if (!text || text === this.value) { this.cancel(); return; }
                    this.saving = true;
                    Admin.fetch(config.url, { method: 'PATCH', data: { value: text } }).then(function (json) {
                        self.value = json.value;
                        self.editing = false;
                        Admin.toast(json.message || 'Renamed');
                    }).catch(function (err) { Admin.toast(err.message, 'error'); }).then(function () { self.saving = false; });
                },
                remove: function () {
                    var self = this;
                    Admin.confirm({ title: 'Delete “' + this.value + '”?', message: 'This value isn’t used by any product.', confirmText: 'Delete value' }).then(function (ok) {
                        if (!ok) return;
                        Admin.fetch(config.url, { method: 'DELETE' }).then(function (json) {
                            self.removed = true;
                            Admin.toast(json.message || 'Deleted');
                        }).catch(function (err) { Admin.toast(err.message, 'error'); });
                    });
                }
            };
        });

        /* ------------------------------------------------------------------------------------------
         * Inventory: inline inputs for one product/variant row that save on change.
         * config: { url, row: {regular_price, sale_price, manage_stock, stock_quantity, stock_status, stock_label, stock_color} }
         * ------------------------------------------------------------------------------------------ */
        Alpine.data('stockCell', function (config) {
            return {
                row: config.row,
                qty: config.row.stock_quantity === null || config.row.stock_quantity === undefined ? '' : String(config.row.stock_quantity),
                regular: config.row.regular_price || '',
                sale: config.row.sale_price || '',
                status: config.row.stock_status || 'instock',
                state: '', // saving | saved | error
                timer: null,
                send: function (data) {
                    var self = this;
                    this.state = 'saving';
                    clearTimeout(this.timer);
                    return Admin.fetch(config.url, { method: 'PATCH', data: data }).then(function (json) {
                        self.row = json.row;
                        self.qty = json.row.stock_quantity === null ? '' : String(json.row.stock_quantity);
                        self.regular = json.row.regular_price;
                        self.sale = json.row.sale_price;
                        self.status = json.row.stock_status;
                        self.state = 'saved';
                        if (json.message && json.message !== 'Saved.') Admin.toast(json.message);
                        self.timer = setTimeout(function () { self.state = ''; }, 1800);
                    }).catch(function (err) {
                        self.state = 'error';
                        Admin.toast(err.message, 'error');
                    });
                },
                saveQty: function () {
                    var value = String(this.qty).trim();
                    var current = this.row.stock_quantity === null ? '' : String(this.row.stock_quantity);
                    if (value === current) return;
                    if (value !== '' && !/^-?\d+$/.test(value)) { Admin.toast('Stock must be a whole number.', 'error'); this.qty = current; return; }
                    this.send(value === '' ? { manage_stock: 0, stock_status: this.row.stock_status } : { stock_quantity: value, manage_stock: 1 });
                },
                saveStatus: function () {
                    if (this.status === this.row.stock_status) return;
                    this.send({ manage_stock: 0, stock_status: this.status });
                },
                savePrice: function (field) {
                    var value = String(field === 'regular_price' ? this.regular : this.sale).trim();
                    if (value === String(this.row[field] || '')) return;
                    var data = {};
                    data[field] = value;
                    this.send(data);
                },
                step: function (delta) {
                    var n = parseInt(this.qty, 10);
                    this.qty = String((isNaN(n) ? 0 : n) + delta);
                    this.saveQty();
                }
            };
        });
    });
})();
