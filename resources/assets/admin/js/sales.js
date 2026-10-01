/*!
 * Back office – Sales area behaviour (orders, customers, abandoned checkouts, analytics). No build step.
 * Loaded on Sales pages with @push('vendor') (deferred, before admin.js and Alpine). Uses window.Admin at run time.
 * Documented in docs/ADMIN_UI.md › "Sales additions".
 *
 *   <tr data-href="…">                 whole row opens the link (Ctrl/⌘/middle click = new tab)
 *   Sales.print(printUrl, [ids])       open invoices / packing slips (route admin.print) for orders in a new tab
 *   Sales.copy(text, 'Address')        copy to clipboard + toast
 *   Alpine: orderForm, refundForm, reportChart
 */
(function () {
    'use strict';

    function admin() { return window.Admin; }

    function toNumber(value) {
        var n = parseFloat(String(value === undefined || value === null ? '' : value).replace(/[£,\s]/g, ''));
        return isNaN(n) ? 0 : n;
    }

    function round2(n) { return Math.round((n + Number.EPSILON) * 100) / 100; }

    var Sales = {
        print: function (url, ids) {
            ids = (ids || []).filter(Boolean);
            if (!ids.length) { admin().toast('Select at least one order first.', 'warning'); return; }
            if (ids.length > 200) { admin().toast('You can print up to 200 orders at a time.', 'warning'); return; }
            var win = window.open(url + (url.indexOf('?') === -1 ? '?' : '&') + 'orders=' + ids.join(','), '_blank');
            if (!win) admin().toast('Your browser blocked the new tab – allow pop-ups for this site.', 'warning');
        },
        copy: function (text, label) {
            var done = function () { admin().toast((label || 'Text') + ' copied'); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done, function () { admin().toast('Couldn’t copy – select the text and copy it yourself.', 'error'); });
                return;
            }
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); done(); } catch (e) { admin().toast('Couldn’t copy.', 'error'); }
            document.body.removeChild(area);
        },
        money: function (n) { return admin() ? admin().money(n) : '£' + Number(n || 0).toFixed(2); }
    };
    window.Sales = Sales;

    // Whole-row links ----------------------------------------------------------------------------------
    function rowLink(e) {
        var row = e.target.closest && e.target.closest('tr[data-href]');
        if (!row) return;
        if (e.target.closest('a, button, input, select, textarea, label, summary, .table__check, [data-no-row-link]')) return;
        if (window.getSelection && String(window.getSelection()).length > 0) return; // selecting text, not clicking
        var href = row.getAttribute('data-href');
        if (e.button === 1 || e.metaKey || e.ctrlKey) {
            window.open(href, '_blank');
        } else if (e.button === 0) {
            window.location.href = href;
        }
    }
    document.addEventListener('click', rowLink);
    document.addEventListener('auxclick', function (e) { if (e.button === 1) rowLink(e); });

    document.addEventListener('alpine:init', function () {
        var Alpine = window.Alpine;

        /*
         * Create / edit order. initial: server state (see OrderController::formState); config: {quoteUrl, productsUrl,
         * customersUrl, customerUrl ("…/__ID__"), editableItems, orderId}
         */
        Alpine.data('orderForm', function (initial, config) {
            var searchController = null, customerController = null, quoteController = null, keySeq = 0;
            var fieldNames = ['email', 'phone', 'shipping_phone', 'coupon_code', 'manual_discount', 'shipping_method', 'shipping_cost',
                'customer_note', 'status', 'payment_method', 'transaction_id', 'private_note'];
            var addressFields = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'county', 'postcode', 'country'];

            function line(raw) {
                raw = raw || {};
                return {
                    key: 'l' + (++keySeq),
                    product_id: raw.product_id ? String(raw.product_id) : '',
                    variation_id: raw.variation_id ? String(raw.variation_id) : '',
                    order_item_id: raw.order_item_id ? String(raw.order_item_id) : '',
                    name: raw.name || '',
                    sku: raw.sku || '',
                    image: raw.image || null,
                    quantity: raw.quantity ? String(raw.quantity) : '1',
                    unit_price: raw.unit_price !== undefined && raw.unit_price !== null ? String(raw.unit_price) : '',
                    list_price: raw.price !== undefined ? raw.price : (raw.list_price !== undefined ? raw.list_price : null),
                    variations: raw.variations || [],
                    is_variable: !!(raw.is_variable || (raw.variations && raw.variations.length)),
                    custom: !raw.product_id,
                    warning: null,
                    total: null,
                    options: raw.options || null,
                    loading: !!raw.product_id && !raw.name
                };
            }

            var f = {};
            fieldNames.forEach(function (k) { f[k] = initial[k] !== undefined && initial[k] !== null ? String(initial[k]) : ''; });
            ['billing_', 'shipping_'].forEach(function (p) {
                addressFields.forEach(function (k) { f[p + k] = initial[p + k] !== undefined && initial[p + k] !== null ? String(initial[p + k]) : ''; });
            });

            return {
                f: f,
                sameAsBilling: !!initial.sameAsBilling,
                reduceStock: !!initial.reduce_stock,
                sendInvoice: !!initial.send_invoice,
                saveAddresses: !!initial.save_addresses,
                customer: initial.customer || null,
                lines: (initial.lines || []).map(line),
                editable: config.editableItems !== false,
                quote: null,
                quoting: false,
                // product search
                q: '', results: [], searching: false, open: false, active: 0,
                // customer search
                cq: '', cresults: [], csearching: false, copen: false, cactive: 0,

                init: function () {
                    var self = this;
                    this.requoteSoon = admin().debounce(function () { self.requote(); }, 350);
                    this.$watch('q', admin().debounce(function () { self.searchProducts(); }, 220));
                    this.$watch('cq', admin().debounce(function () { self.searchCustomers(); }, 220));
                    // The first price check fills in option lists etc. – that isn't an unsaved change
                    if (this.lines.length) this.requote(true);
                },

                get userId() { return this.customer ? String(this.customer.id) : ''; },
                get itemCount() { return this.lines.reduce(function (n, l) { return n + (parseInt(l.quantity, 10) || 0); }, 0); },

                // Product search ---------------------------------------------------------------------
                searchProducts: function () {
                    var self = this, q = this.q.trim();
                    this.active = 0;
                    if (!q) { this.results = []; this.open = false; return; }
                    if (searchController) searchController.abort();
                    searchController = new AbortController();
                    this.searching = true;
                    this.open = true;
                    admin().fetch(config.productsUrl, { data: { q: q }, signal: searchController.signal }).then(function (json) {
                        self.results = json.data || [];
                        self.searching = false;
                    }).catch(function (err) {
                        if (err.name !== 'AbortError') { self.searching = false; admin().toast(err.message, 'error'); }
                    });
                },
                onSearchKey: function (e) {
                    if (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.active = Math.min(this.active + 1, this.results.length - 1); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); this.active = Math.max(this.active - 1, 0); }
                    else if (e.key === 'Enter') { e.preventDefault(); if (this.open && this.results[this.active]) this.addProduct(this.results[this.active]); }
                    else if (e.key === 'Escape') { if (this.open) { e.stopPropagation(); this.open = false; } }
                },
                addProduct: function (product) {
                    var existing = this.lines.find(function (l) { return String(l.product_id) === String(product.id) && !l.order_item_id && !(product.variations && product.variations.length); });
                    if (existing) {
                        existing.quantity = String((parseInt(existing.quantity, 10) || 0) + 1);
                    } else {
                        var l = line({ product_id: product.id, name: product.name, sku: product.sku, image: product.image, price: product.price, variations: product.variations, is_variable: product.type === 'variable' });
                        l.loading = false;
                        this.lines.push(l);
                    }
                    this.q = '';
                    this.results = [];
                    this.open = false;
                    this.changed();
                    var self = this;
                    this.$nextTick(function () { self.$refs.productSearch && self.$refs.productSearch.focus(); });
                },
                addCustom: function () {
                    this.lines.push(line({ name: '', unit_price: '' }));
                    var self = this;
                    this.$nextTick(function () {
                        var inputs = self.$root.querySelectorAll('[data-custom-name]');
                        if (inputs.length) inputs[inputs.length - 1].focus();
                    });
                    this.changed();
                },
                removeLine: function (index) {
                    this.lines.splice(index, 1);
                    this.changed();
                },
                variationChanged: function (l) {
                    var v = (l.variations || []).find(function (x) { return String(x.id) === String(l.variation_id); });
                    if (v) { l.list_price = v.price; if (v.sku) l.sku = v.sku; }
                    this.changed();
                },
                lineTotal: function (l) {
                    if (l.total !== null && l.total !== undefined) return l.total;
                    var unit = l.unit_price !== '' ? toNumber(l.unit_price) : toNumber(l.list_price);
                    return round2(unit * (parseInt(l.quantity, 10) || 0));
                },

                // Customer search -------------------------------------------------------------------
                searchCustomers: function () {
                    var self = this, q = this.cq.trim();
                    this.cactive = 0;
                    if (!q) { this.cresults = []; this.copen = false; return; }
                    if (customerController) customerController.abort();
                    customerController = new AbortController();
                    this.csearching = true;
                    this.copen = true;
                    admin().fetch(config.customersUrl, { data: { q: q }, signal: customerController.signal }).then(function (json) {
                        self.cresults = json.data || [];
                        self.csearching = false;
                    }).catch(function (err) {
                        if (err.name !== 'AbortError') { self.csearching = false; admin().toast(err.message, 'error'); }
                    });
                },
                onCustomerKey: function (e) {
                    if (e.key === 'ArrowDown') { e.preventDefault(); this.copen = true; this.cactive = Math.min(this.cactive + 1, this.cresults.length - 1); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); this.cactive = Math.max(this.cactive - 1, 0); }
                    else if (e.key === 'Enter') { e.preventDefault(); if (this.copen && this.cresults[this.cactive]) this.chooseCustomer(this.cresults[this.cactive]); }
                    else if (e.key === 'Escape') { if (this.copen) { e.stopPropagation(); this.copen = false; } }
                },
                chooseCustomer: function (option) {
                    var self = this;
                    this.cq = '';
                    this.cresults = [];
                    this.copen = false;
                    admin().fetch(config.customerUrl.replace('__ID__', option.id)).then(function (c) {
                        self.customer = { id: c.id, name: c.name, email: c.email };
                        self.f.email = c.email || '';
                        self.f.phone = c.phone || (c.billing && c.billing.phone) || self.f.phone;
                        var billing = c.billing || { first_name: c.first_name, last_name: c.last_name };
                        addressFields.forEach(function (k) {
                            var v = billing[k] !== undefined && billing[k] !== null ? String(billing[k]) : '';
                            self.f['billing_' + k] = k === 'country' ? (v || 'GB') : v;
                        });
                        if (!self.f.billing_first_name) self.f.billing_first_name = c.first_name || '';
                        if (!self.f.billing_last_name) self.f.billing_last_name = c.last_name || '';
                        var shipping = c.shipping;
                        var same = !shipping || addressFields.every(function (k) { return String(shipping[k] || '') === String(billing[k] || '') || (k === 'country' && !shipping[k]); });
                        self.sameAsBilling = same;
                        if (!same) {
                            addressFields.forEach(function (k) {
                                var v = shipping[k] !== undefined && shipping[k] !== null ? String(shipping[k]) : '';
                                self.f['shipping_' + k] = k === 'country' ? (v || 'GB') : v;
                            });
                            self.f.shipping_phone = shipping.phone || '';
                        }
                        self.changed();
                    }).catch(function (err) { admin().toast(err.message, 'error'); });
                },
                clearCustomer: function () {
                    this.customer = null;
                    this.saveAddresses = false;
                    this.changed();
                    var self = this;
                    this.$nextTick(function () { self.$refs.customerSearch && self.$refs.customerSearch.focus(); });
                },

                // Totals ---------------------------------------------------------------------------------
                changed: function () {
                    this.requoteSoon();
                    var form = this.$root.closest('form');
                    if (form) this.$nextTick(function () { admin().markDirty(form); });
                },
                payload: function () {
                    return {
                        lines: this.lines.map(function (l) {
                            return {
                                product_id: l.product_id || null, variation_id: l.variation_id || null, order_item_id: l.order_item_id || null,
                                name: l.custom ? (l.name || 'Custom item') : null, sku: l.custom ? (l.sku || null) : null,
                                quantity: Math.max(1, parseInt(l.quantity, 10) || 1), unit_price: l.unit_price === '' ? null : String(l.unit_price)
                            };
                        }),
                        coupon_code: this.f.coupon_code || null,
                        manual_discount: this.f.manual_discount || null,
                        shipping_method: this.f.shipping_method || null,
                        shipping_cost: this.f.shipping_cost || null,
                        email: this.f.email || null,
                        user_id: this.customer ? this.customer.id : null,
                        order_id: config.orderId || null,
                        // tax rates / zones follow the order's address
                        shipping_same_as_billing: this.sameAsBilling ? 1 : 0,
                        billing_country: this.f.billing_country || null, billing_postcode: this.f.billing_postcode || null,
                        billing_county: this.f.billing_county || null, billing_city: this.f.billing_city || null,
                        shipping_country: this.f.shipping_country || null, shipping_postcode: this.f.shipping_postcode || null,
                        shipping_county: this.f.shipping_county || null, shipping_city: this.f.shipping_city || null
                    };
                },
                requote: function (initial) {
                    var self = this;
                    if (!this.editable) return;
                    if (quoteController) quoteController.abort();
                    quoteController = new AbortController();
                    this.quoting = true;
                    admin().fetch(config.quoteUrl, { method: 'POST', data: this.payload(), signal: quoteController.signal }).then(function (quote) {
                        self.quote = quote;
                        self.quoting = false;
                        (quote.lines || []).forEach(function (q, i) {
                            var l = self.lines[i];
                            if (!l) return;
                            l.total = q.total;
                            l.warning = q.warning;
                            l.list_price = q.list_price;
                            l.loading = false;
                            if (!l.custom) { l.name = q.name; l.image = q.image; l.sku = q.sku || ''; l.options = q.options; }
                            if (q.variations) { l.variations = q.variations; l.is_variable = true; }
                        });
                        if (initial === true) {
                            var form = self.$root.closest('form');
                            if (form) self.$nextTick(function () { setTimeout(function () { form.dispatchEvent(new Event('dirty-reset')); }, 80); });
                        }
                    }).catch(function (err) {
                        if (err.name === 'AbortError') return;
                        self.quoting = false;
                        admin().toast(err.message, 'error');
                    });
                },
                money: function (n) { return Sales.money(n); }
            };
        });

        /*
         * Refund modal. config: {lines: [{id, unit, remaining}], shippingMax, refundable, old: {lines, shipping, amount}}
         */
        Alpine.data('refundForm', function (config) {
            var qty = {};
            (config.lines || []).forEach(function (l) {
                var old = config.old && config.old.lines ? config.old.lines[l.id] : undefined;
                qty[l.id] = old !== undefined && old !== null ? String(old) : '0';
            });
            var hasOld = !!(config.old && config.old.amount);
            return {
                qty: qty,
                shipping: hasOld && config.old.shipping ? String(config.old.shipping) : '',
                amount: hasOld ? String(config.old.amount) : '',
                custom: hasOld,
                restock: true,
                get calculated() {
                    var sum = 0;
                    (config.lines || []).forEach(function (l) {
                        var n = Math.min(Math.max(parseInt(this.qty[l.id], 10) || 0, 0), l.remaining);
                        sum += round2(n * l.unit);
                    }, this);
                    sum += Math.min(toNumber(this.shipping), config.shippingMax || 0);
                    return Math.min(round2(sum), config.refundable);
                },
                get amountValue() { return toNumber(this.amount); },
                get tooMuch() { return this.amountValue > config.refundable + 0.001; },
                get anyItems() {
                    var self = this;
                    return (config.lines || []).some(function (l) { return (parseInt(self.qty[l.id], 10) || 0) > 0; });
                },
                init: function () { if (!this.custom) this.sync(); },
                sync: function () { if (!this.custom) this.amount = this.calculated > 0 ? this.calculated.toFixed(2) : ''; },
                edited: function () { this.custom = this.amount !== '' && Math.abs(this.amountValue - this.calculated) > 0.001; },
                reset: function () { this.custom = false; this.sync(); },
                everything: function () {
                    var self = this;
                    (config.lines || []).forEach(function (l) { self.qty[l.id] = String(l.remaining); });
                    this.shipping = config.shippingMax > 0 ? config.shippingMax.toFixed(2) : '';
                    this.custom = false;
                    this.amount = config.refundable.toFixed(2);
                },
                lineAmount: function (l) {
                    var n = Math.min(Math.max(parseInt(this.qty[l.id], 10) || 0, 0), l.remaining);
                    return round2(n * l.unit);
                },
                money: function (n) { return Sales.money(n); }
            };
        });

        /*
         * Analytics chart. data: {labels, net, orders, aov, compareNet, compareOrders, compareAov, compareLabels}, hasCompare
         */
        Alpine.data('reportChart', function (dataId, hasCompare) {
            var chart = null;
            var data = JSON.parse(document.getElementById(dataId).textContent);
            return {
                metric: 'net',
                init: function () {
                    if (!window.Chart) return;
                    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
                    Chart.defaults.color = '#616161';
                    this.render();
                },
                show: function (metric) { this.metric = metric; this.render(); },
                render: function () {
                    var metric = this.metric;
                    var isMoney = metric !== 'orders';
                    var key = { net: ['net', 'compareNet'], orders: ['orders', 'compareOrders'], aov: ['aov', 'compareAov'] }[metric];
                    var format = function (v) { return isMoney ? Sales.money(v) : (v + (v === 1 ? ' order' : ' orders')); };
                    var compact = new Intl.NumberFormat('en-GB', { notation: 'compact', maximumFractionDigits: 1 });
                    var datasets = [{
                        label: 'This period', data: data[key[0]], borderColor: '#1976d2', backgroundColor: 'rgba(25,118,210,0.08)',
                        fill: 'origin', borderWidth: 2, pointRadius: data.labels.length > 45 ? 0 : 2, pointHoverRadius: 4, pointHitRadius: 12, tension: 0.3
                    }];
                    if (hasCompare) {
                        datasets.push({
                            label: 'Compared period', data: data[key[1]], borderColor: '#9a9a9a', borderDash: [4, 3], borderWidth: 1.5,
                            pointRadius: 0, pointHoverRadius: 3, pointHitRadius: 12, fill: false, tension: 0.3
                        });
                    }
                    if (chart) chart.destroy();
                    chart = new Chart(this.$refs.canvas, {
                        type: 'line',
                        data: { labels: data.labels, datasets: datasets },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: { duration: 250 },
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        title: function (items) { return items.length ? items[0].label : ''; },
                                        label: function (ctx) {
                                            var label = ctx.datasetIndex === 1 ? (data.compareLabels[ctx.dataIndex] || 'Compared period') : 'This period';
                                            return ' ' + label + ': ' + format(ctx.parsed.y || 0);
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                                y: {
                                    beginAtZero: true, border: { display: false }, grid: { color: '#eeeeee' },
                                    ticks: { precision: 0, callback: function (v) { return isMoney ? '£' + compact.format(v) : v; } }
                                }
                            }
                        }
                    });
                }
            };
        });
    });
})();
