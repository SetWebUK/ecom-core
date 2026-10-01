/*!
 * Pine Commerce – default theme storefront script (vanilla JS, no build step).
 *  drawers (side basket, mobile menu, catalogue filters) · header search suggestions · dropdown menus ·
 *  add to basket / basket line edits (core /cart/* JSON endpoints) · wishlist · quick view ·
 *  AJAX catalogue filters (core answers the header from commerce.catalog.ajax_header with JSON fragments) ·
 *  product gallery + variations · newsletter · cookie consent.
 * Everything degrades to plain links and forms without JavaScript.
 *
 * Public API: window.Store.cart.open() / .refresh() / .update(data); event "store:cart-updated" (detail.count).
 */
(function () {
    'use strict';

    var d = document;
    var $ = function (sel, ctx) { return (ctx || d).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || d).querySelectorAll(sel)); };
    var config = {};
    try { config = JSON.parse(($('#store-config') || {}).textContent || '{}') || {}; } catch (e) { config = {}; }
    var csrf = function () { var m = $('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; };

    function request(url, method, body, headers) {
        var h = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() };
        Object.keys(headers || {}).forEach(function (k) { h[k] = headers[k]; });
        var opts = { method: method || 'GET', headers: h, credentials: 'same-origin' };
        if (body) { opts.body = body; }
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                data = data || {};
                data._status = r.status;
                if (r.status === 419) { data.message = 'Your session has expired – please refresh the page.'; }
                return data;
            });
        });
    }

    function toast(message, type, link) {
        var box = $('[data-toasts]');
        if (!box || !message) { return; }
        var el = d.createElement('div');
        el.className = 'toast' + (type === 'error' ? ' toast--error' : '');
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        var span = d.createElement('span');
        span.textContent = message;
        el.appendChild(span);
        if (link) {
            var a = d.createElement('a');
            a.href = link.href; a.textContent = link.label;
            if (link.onClick) { a.addEventListener('click', link.onClick); }
            el.appendChild(a);
        }
        box.appendChild(el);
        setTimeout(function () { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; }, 4200);
        setTimeout(function () { el.remove(); }, 4600);
    }

    /* ------------------------------------------------------------------ drawers */
    var overlay = $('[data-drawer-overlay]');
    var openDrawer = null;
    var lastFocus = null;
    var desktop = window.matchMedia('(min-width: 1000px)');

    function drawerOpen(el) {
        if (!el) { return; }
        if (el.classList.contains('drawer--static') && desktop.matches) { return; }
        if (openDrawer && openDrawer !== el) { drawerClose(true); }
        lastFocus = d.activeElement;
        openDrawer = el;
        el.classList.add('is-open');
        el.setAttribute('aria-hidden', 'false');
        if (!overlay) {
            overlay = d.createElement('div');
            overlay.className = 'drawer-overlay';
            overlay.setAttribute('data-drawer-overlay', '');
            d.body.appendChild(overlay);
        }
        overlay.hidden = false;
        d.body.classList.add('has-drawer');
        $$('[aria-controls="' + el.id + '"]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
        setTimeout(function () { var f = $('button, a[href], input, select', el); (f || el).focus(); }, 60);
    }

    function drawerClose(keepOverlay) {
        if (!openDrawer) { return; }
        var el = openDrawer;
        openDrawer = null;
        el.classList.remove('is-open');
        if (!el.classList.contains('drawer--static') || !desktop.matches) { el.setAttribute('aria-hidden', 'true'); }
        $$('[aria-controls="' + el.id + '"]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
        if (!keepOverlay && overlay) { overlay.hidden = true; }
        d.body.classList.remove('has-drawer');
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }

    d.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-drawer-open]');
        if (opener) { e.preventDefault(); drawerOpen(d.getElementById(opener.getAttribute('data-drawer-open'))); return; }
        if (e.target.closest('[data-drawer-close]') || e.target.closest('[data-drawer-overlay]')) { drawerClose(); }
    });
    d.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (openDrawer) { drawerClose(); }
            $$('.main-nav__item.is-open').forEach(closeDropdown);
            closeSuggest();
            var lb = $('.lightbox'); if (lb) { lb.remove(); }
        }
        if (e.key === 'Tab' && openDrawer) { // keep focus inside the open drawer
            var f = $$('button:not([disabled]), a[href], input:not([type=hidden]), select, textarea, [tabindex]:not([tabindex="-1"])', openDrawer).filter(function (x) { return x.offsetParent !== null; });
            if (!f.length) { return; }
            if (e.shiftKey && d.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
            else if (!e.shiftKey && d.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
        }
    });
    var filtersDrawer = $('#catalog-filters');
    function syncStatic() { if (filtersDrawer) { filtersDrawer.setAttribute('aria-hidden', desktop.matches ? 'false' : (filtersDrawer.classList.contains('is-open') ? 'false' : 'true')); } }
    if (desktop.addEventListener) { desktop.addEventListener('change', syncStatic); }
    syncStatic();

    /* ------------------------------------------------------------------ header */
    var header = $('[data-site-header]');
    if (header) {
        var onScroll = function () { header.classList.toggle('is-scrolled', window.scrollY > 4); };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }
    d.addEventListener('click', function (e) {
        var t = e.target.closest('[data-search-toggle]');
        if (t && header) {
            header.classList.toggle('is-searching');
            if (header.classList.contains('is-searching')) { var i = $('[data-search-input]', header); if (i) { i.focus(); } }
        }
    });

    function closeDropdown(li) {
        li.classList.remove('is-open');
        var b = $('[data-dropdown-toggle]', li); if (b) { b.setAttribute('aria-expanded', 'false'); }
    }
    d.addEventListener('click', function (e) {
        var toggle = e.target.closest('[data-dropdown-toggle]');
        $$('.main-nav__item.is-open').forEach(function (li) { if (!toggle || !li.contains(toggle)) { closeDropdown(li); } });
        if (toggle) {
            var li = toggle.closest('[data-dropdown]');
            var open = !li.classList.contains('is-open');
            li.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    });

    /* search suggestions */
    var searchInput = $('[data-search-input]');
    var suggestBox = $('[data-search-results]');
    var searchTimer = null;
    var searchSeq = 0;
    function closeSuggest() { if (suggestBox) { suggestBox.hidden = true; } if (searchInput) { searchInput.setAttribute('aria-expanded', 'false'); } }
    function esc(s) { var x = d.createElement('div'); x.textContent = s == null ? '' : String(s); return x.innerHTML; }
    if (searchInput && suggestBox && config.searchUrl && window.fetch) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var q = searchInput.value.trim();
            if (q.length < 2) { closeSuggest(); return; }
            searchTimer = setTimeout(function () {
                var seq = ++searchSeq;
                request(config.searchUrl + '?q=' + encodeURIComponent(q)).then(function (data) {
                    if (seq !== searchSeq) { return; }
                    var html = '';
                    (data.categories || []).forEach(function (c) { html += '<a class="search-suggest__item" role="option" href="' + esc(c.url) + '"><span class="search-suggest__name">' + esc(c.name) + '</span><span class="muted small">Category</span></a>'; });
                    (data.products || []).forEach(function (p) {
                        html += '<a class="search-suggest__item" role="option" href="' + esc(p.url) + '">' + (p.image ? '<img src="' + esc(p.image) + '" alt="" loading="lazy">' : '') +
                            '<span class="search-suggest__name">' + esc(p.name) + '</span><span class="search-suggest__price">' + esc(p.price_formatted || '') + '</span></a>';
                    });
                    html = html ? html + '<a class="search-suggest__all" href="' + esc(searchInput.form.action) + '?s=' + encodeURIComponent(q) + '&post_type=product">See all results for “' + esc(q) + '”</a>'
                        : '<p class="search-suggest__empty">No matches for “' + esc(q) + '”.</p>';
                    suggestBox.innerHTML = html;
                    suggestBox.hidden = false;
                    searchInput.setAttribute('aria-expanded', 'true');
                });
            }, 220);
        });
        searchInput.addEventListener('keydown', function (e) {
            if (suggestBox.hidden || (e.key !== 'ArrowDown' && e.key !== 'ArrowUp')) { return; }
            e.preventDefault();
            var items = $$('a', suggestBox);
            var i = items.indexOf(d.activeElement);
            var next = e.key === 'ArrowDown' ? items[Math.min(items.length - 1, i + 1)] : items[i - 1];
            if (next) { next.focus(); } else { searchInput.focus(); }
        });
        suggestBox.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') { return; }
            e.preventDefault();
            var items = $$('a', suggestBox);
            var i = items.indexOf(d.activeElement);
            var next = e.key === 'ArrowDown' ? items[Math.min(items.length - 1, i + 1)] : items[i - 1];
            (next || searchInput).focus();
        });
        d.addEventListener('click', function (e) { if (!e.target.closest('[data-search]')) { closeSuggest(); } });
    }

    /* ------------------------------------------------------------------ basket */
    var sideCart = $('[data-side-cart]');
    var cartLoaded = false;

    function setCount(count) {
        $$('[data-cart-count]').forEach(function (el) {
            el.textContent = count;
            el.hidden = !(count > 0);
            var btn = el.closest('.cart-btn');
            if (btn) {
                btn.setAttribute('aria-label', 'Basket, ' + count + (count === 1 ? ' item' : ' items'));
                btn.classList.remove('is-bumped'); void btn.offsetWidth; btn.classList.add('is-bumped');
            }
        });
        d.dispatchEvent(new CustomEvent('store:cart-updated', { detail: { count: count } }));
    }

    function applyCart(data) {
        if (!data) { return; }
        if (typeof data.count === 'number') { setCount(data.count); }
        var body = sideCart ? $('[data-side-cart-content]', sideCart) : null;
        if (body && typeof data.html === 'string') { body.innerHTML = data.html; cartLoaded = true; }
    }

    function refreshCart() {
        if (!config.fragmentUrl || !window.fetch) { return Promise.resolve(); }
        return request(config.fragmentUrl).then(applyCart);
    }

    function openCart() {
        if (!sideCart) { window.location.href = '/basket/'; return; }
        drawerOpen(sideCart);
        if (!cartLoaded) { refreshCart(); }
    }

    window.Store = { cart: { open: openCart, refresh: refreshCart, update: applyCart }, toast: toast };

    d.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-cart-open]');
        if (btn && sideCart) { e.preventDefault(); openCart(); }
    });

    function addToCart(body, button) {
        if (button) { button.disabled = true; button.classList.add('is-busy'); }
        return request(config.cartUrl, 'POST', body).then(function (data) {
            if (button) { button.disabled = false; button.classList.remove('is-busy'); }
            applyCart(data);
            if (data.ok) {
                if (data.open !== false) { openCart(); }
                if (!sideCart) { toast(data.message); }
                var modal = $('#quick-view'); if (modal && modal.open) { modal.close(); }
            } else {
                toast(data.message || 'Sorry, that could not be added to your basket.', 'error');
            }
            return data;
        }).catch(function () {
            if (button) { button.disabled = false; button.classList.remove('is-busy'); }
            toast('Sorry, we could not reach the shop. Please try again.', 'error');
        });
    }

    d.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-add-to-cart]');
        if (!btn || !window.fetch || !config.cartUrl) { return; }
        e.preventDefault();
        var body = new FormData();
        body.append('product_id', btn.getAttribute('data-add-to-cart'));
        body.append('quantity', '1');
        addToCart(body, btn);
    });

    d.addEventListener('submit', function (e) {
        var form = e.target.closest('[data-product-form]');
        if (!form || !window.fetch || !window.FormData || !config.cartUrl) { return; }
        e.preventDefault();
        var variationInput = $('[data-variation-id]', form);
        if (variationInput && variationInput.value === '0') { toast('Please choose your options first.', 'error'); return; }
        addToCart(new FormData(form), $('[data-add-button]', form));
    });

    /* basket lines (side cart) */
    function lineRequest(url, itemId, qty, row) {
        var body = new FormData();
        body.append('item_id', itemId);
        if (qty !== undefined) { body.append('quantity', String(qty)); }
        if (row) { row.classList.add('is-busy'); }
        return request(url, 'POST', body).then(function (data) {
            applyCart(data);
            if (!data.ok && data.message) { toast(data.message, 'error'); }
        });
    }
    d.addEventListener('click', function (e) {
        var step = e.target.closest('[data-line-qty]');
        var remove = e.target.closest('[data-line-remove]');
        if (!step && !remove) { return; }
        var row = e.target.closest('[data-item-id]');
        if (!row) { return; }
        e.preventDefault();
        var id = row.getAttribute('data-item-id');
        if (remove) { lineRequest(config.cartRemoveUrl, id, undefined, row); return; }
        var qty = parseInt(row.getAttribute('data-qty'), 10) + parseInt(step.getAttribute('data-line-qty'), 10);
        var max = parseInt(row.getAttribute('data-max'), 10) || 999;
        if (qty > max) { return; }
        lineRequest(qty < 1 ? config.cartRemoveUrl : config.cartUpdateUrl, id, qty < 1 ? undefined : qty, row);
    });

    // a non-AJAX add to basket (no-JS fallback or ?add-to-cart=) leaves a short-lived cookie: open the basket
    if (config.openCartCookie && new RegExp('(?:^|; )' + config.openCartCookie + '=1').test(d.cookie)) {
        d.cookie = config.openCartCookie + '=; Max-Age=0; path=/';
        setTimeout(openCart, 200);
    }

    /* ------------------------------------------------------------------ wishlist */
    d.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-wishlist]');
        if (!btn || !config.wishlistUrl || !window.fetch) { return; }
        e.preventDefault();
        var body = new FormData();
        body.append('product_id', btn.getAttribute('data-wishlist'));
        request(config.wishlistUrl, 'POST', body).then(function (data) {
            if (data._status === 401 && data.login) {
                toast(data.message || 'Please sign in to save products.', 'info', { href: data.login, label: 'Sign in' });
                return;
            }
            if (data.ok) {
                $$('[data-wishlist="' + btn.getAttribute('data-wishlist') + '"]').forEach(function (b) { b.setAttribute('aria-pressed', data.in_wishlist ? 'true' : 'false'); });
                toast(data.message);
            } else if (data.message) {
                toast(data.message, 'error');
            }
        });
    });

    /* ------------------------------------------------------------------ quick view */
    d.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-quick-view]');
        var modal = $('#quick-view');
        if (!btn || !modal || typeof modal.showModal !== 'function' || !window.fetch) { return; }
        e.preventDefault();
        var content = $('[data-quick-view-content]', modal);
        content.innerHTML = '<div class="side-cart__loading"><span class="spinner"></span></div>';
        modal.showModal();
        fetch(btn.getAttribute('data-quick-view'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) { content.innerHTML = html; })
            .catch(function () { modal.close(); window.location.href = btn.closest('.product-card').querySelector('a').href; });
    });
    d.addEventListener('click', function (e) {
        var modal = e.target.closest('dialog.modal');
        if (e.target.closest('[data-modal-close]') || (modal && e.target === modal)) { if (modal) { modal.close(); } }
    });

    /* ------------------------------------------------------------------ catalogue (AJAX filters, sort, pagination) */
    var catalog = $('[data-catalog]');
    if (catalog && window.fetch && window.history && history.pushState) {
        var ajaxHeader = catalog.getAttribute('data-ajax-header') || config.catalogHeader;

        var buildUrl = function (form, extra) {
            var params = new URLSearchParams();
            var multi = {};
            new FormData(form).forEach(function (value, key) {
                if (value === '' || value === null) { return; }
                if (/\[\]$/.test(key)) { key = key.slice(0, -2); (multi[key] = multi[key] || []).push(value); return; }
                params.set(key, value);
            });
            Object.keys(multi).forEach(function (k) { params.set(k, multi[k].join(',')); });
            Object.keys(extra || {}).forEach(function (k) { if (extra[k] === null || extra[k] === '') { params.delete(k); } else { params.set(k, extra[k]); } });
            var qs = params.toString();
            return form.getAttribute('action') + (qs ? '?' + qs : '');
        };

        var load = function (url, push) {
            catalog.classList.add('is-loading');
            var h = {}; h[ajaxHeader] = '1';
            return request(url, 'GET', null, h).then(function (data) {
                catalog.classList.remove('is-loading');
                if (typeof data.results !== 'string') { window.location.href = url; return; }
                var sidebar = $('[data-sidebar]', catalog);
                var wrap = $('[data-results-wrap]', catalog);
                if (sidebar && typeof data.sidebar === 'string') { sidebar.innerHTML = data.sidebar; }
                if (wrap) { wrap.innerHTML = data.results; }
                if (data.title) { d.title = data.title; }
                if (push) { history.pushState({ catalog: true }, '', url); }
                var top = catalog.getBoundingClientRect().top + window.pageYOffset - 120;
                if (window.pageYOffset > top) { window.scrollTo({ top: top, behavior: 'smooth' }); }
            }).catch(function () { window.location.href = url; });
        };

        catalog.addEventListener('change', function (e) {
            var t = e.target;
            if (t.matches('[data-filter-input]')) {
                load(buildUrl(t.form, { orderby: new URLSearchParams(location.search).get('orderby') }), true);
            } else if (t.matches('[data-sort]')) {
                var filters = $('[data-filters]', catalog);
                load(buildUrl(filters || t.form, { orderby: t.value }), true);
            }
        });
        catalog.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form.matches('[data-filters], [data-sort-form]')) { return; }
            e.preventDefault();
            load(buildUrl(form, form.matches('[data-filters]') ? { orderby: new URLSearchParams(location.search).get('orderby') } : null), true);
        });
        catalog.addEventListener('click', function (e) {
            var a = e.target.closest('[data-filter-link], .pagination a');
            if (!a || e.metaKey || e.ctrlKey || e.shiftKey) { return; }
            e.preventDefault();
            load(a.href, true);
        });
        window.addEventListener('popstate', function () { load(location.href, false); });
    }

    /* ------------------------------------------------------------------ product page */
    var gallery = $('[data-gallery]');
    if (gallery) {
        var track = $('[data-gallery-track]', gallery);
        var thumbs = $$('[data-gallery-thumb]', gallery);
        var setActive = function (i) {
            thumbs.forEach(function (t, j) { t.classList.toggle('is-active', j === i); t.setAttribute('aria-selected', j === i ? 'true' : 'false'); });
        };
        thumbs.forEach(function (t, i) {
            t.addEventListener('click', function () { track.scrollTo({ left: track.clientWidth * i, behavior: 'smooth' }); setActive(i); });
        });
        track.addEventListener('scroll', function () {
            var i = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
            setActive(i);
        }, { passive: true });
        gallery.addEventListener('click', function (e) {
            var a = e.target.closest('[data-gallery-zoom]');
            if (!a) { return; }
            e.preventDefault();
            var lb = d.createElement('div');
            lb.className = 'lightbox';
            lb.setAttribute('role', 'dialog');
            lb.setAttribute('aria-label', 'Image');
            lb.innerHTML = '<img src="' + esc(a.getAttribute('href')) + '" alt="">';
            lb.addEventListener('click', function () { lb.remove(); });
            d.body.appendChild(lb);
        });
    }

    var productForm = $('[data-product-form]');
    if (productForm) {
        var variationsEl = $('[data-variations]', productForm);
        var variations = [];
        try { variations = variationsEl ? JSON.parse(variationsEl.textContent) : []; } catch (e) { variations = []; }
        var selects = $$('[data-variation-select]', productForm);
        var priceEl = $('[data-price]');
        var basePrice = priceEl ? priceEl.innerHTML : '';
        var stockEl = $('[data-stock]');
        var baseStock = stockEl ? stockEl.innerHTML : '';
        var idInput = $('[data-variation-id]', productForm);
        var addButton = $('[data-add-button]', productForm);
        var message = $('[data-variation-message]', productForm);
        var qtyInput = $('[data-qty-input]', productForm);

        var matches = function (v, chosen) {
            return Object.keys(v.attributes || {}).every(function (key) {
                var slug = key.replace(/^(attribute_)?(pa_)?/, '');
                var want = chosen[slug];
                var val = v.attributes[key];
                return val === '' || val === null || (want !== undefined && want !== '' && String(want).toLowerCase() === String(val).toLowerCase());
            });
        };
        var update = function () {
            var chosen = {};
            var complete = true;
            selects.forEach(function (s) { chosen[s.getAttribute('data-variation-select')] = s.value; if (!s.value) { complete = false; } });
            // grey out options that no available variation offers with the other choices
            selects.forEach(function (s) {
                var slug = s.getAttribute('data-variation-select');
                $$('option', s).forEach(function (o) {
                    if (!o.value) { return; }
                    var test = Object.assign({}, chosen); test[slug] = o.value;
                    Object.keys(test).forEach(function (k) { if (!test[k]) { delete test[k]; } });
                    var possible = variations.some(function (v) {
                        return Object.keys(test).every(function (k) {
                            var key = Object.keys(v.attributes || {}).filter(function (a) { return a.replace(/^(attribute_)?(pa_)?/, '') === k; })[0];
                            var val = key ? v.attributes[key] : '';
                            return !val || String(val).toLowerCase() === String(test[k]).toLowerCase();
                        });
                    });
                    o.disabled = !possible;
                });
            });
            var match = complete ? variations.filter(function (v) { return matches(v, chosen); })[0] : null;
            if (idInput) { idInput.value = match ? match.variation_id : '0'; }
            var notify = $('[data-notify-variation]'); if (notify) { notify.value = match ? match.variation_id : ''; }
            if (priceEl) { priceEl.innerHTML = match && match.price_html ? match.price_html : basePrice; }
            if (!complete) {
                if (message) { message.textContent = ''; }
                if (addButton) { addButton.disabled = true; }
                if (stockEl) { stockEl.innerHTML = baseStock; }
                return;
            }
            if (!match) {
                if (message) { message.textContent = 'Sorry, that combination is not available. Please choose another.'; }
                if (addButton) { addButton.disabled = true; }
                return;
            }
            if (message) { message.textContent = match.sku ? 'SKU: ' + match.sku : ''; }
            if (addButton) { addButton.disabled = !match.is_in_stock; }
            if (stockEl) {
                stockEl.className = 'stock stock--' + (match.is_in_stock ? 'in' : 'out');
                stockEl.innerHTML = '<span class="stock__dot"></span>' + (match.is_in_stock ? (match.max_qty && match.max_qty <= 3 ? 'Only ' + match.max_qty + ' left' : 'In stock') : 'Out of stock');
            }
            if (qtyInput && match.max_qty) { qtyInput.max = match.max_qty; if (+qtyInput.value > match.max_qty) { qtyInput.value = match.max_qty; } }
            if (match.image) {
                var img = $('[data-gallery-image]');
                if (img) { var pic = img.closest('picture'); if (pic) { pic.querySelectorAll('source').forEach(function (s) { s.remove(); }); } img.src = match.image; img.removeAttribute('srcset'); img.removeAttribute('sizes'); var t = $('[data-gallery-track]'); if (t) { t.scrollTo({ left: 0 }); } }
            }
        };
        selects.forEach(function (s) { s.addEventListener('change', update); });
        if (selects.length) { update(); }

        productForm.addEventListener('click', function (e) {
            var step = e.target.closest('[data-qty-step]');
            if (!step || !qtyInput) { return; }
            var v = (parseInt(qtyInput.value, 10) || 1) + parseInt(step.getAttribute('data-qty-step'), 10);
            var max = parseInt(qtyInput.max, 10) || 99;
            qtyInput.value = Math.max(1, Math.min(max, v));
        });

        var sticky = $('[data-sticky-buy]');
        if (sticky && 'IntersectionObserver' in window) {
            new IntersectionObserver(function (entries) {
                sticky.hidden = entries[0].isIntersecting || entries[0].boundingClientRect.top > 0;
            }).observe(productForm);
        }
    }

    /* ------------------------------------------------------------------ newsletter */
    d.addEventListener('submit', function (e) {
        var form = e.target.closest('[data-newsletter]');
        if (!form || !window.fetch) { return; }
        e.preventDefault();
        var status = $('[data-newsletter-status]', form);
        var btn = $('button', form);
        btn.disabled = true;
        request(form.action, 'POST', new FormData(form)).then(function (data) {
            btn.disabled = false;
            if (status) { status.textContent = data.message || (data.ok ? 'Thanks for subscribing!' : 'Please check your email address.'); }
            if (data.ok) { form.reset(); }
        }).catch(function () { btn.disabled = false; form.submit(); });
    });

    /* ------------------------------------------------------------------ cookie consent */
    var banner = $('[data-cookie-banner]');
    if (banner && !/(?:^|; )store_consent=/.test(d.cookie)) {
        banner.hidden = false;
        banner.addEventListener('click', function (e) {
            var b = e.target.closest('[data-consent]');
            if (!b) { return; }
            var all = b.getAttribute('data-consent') === 'all';
            var value = all ? 'necessary,preferences,statistics,marketing' : 'necessary';
            d.cookie = 'store_consent=' + encodeURIComponent(value) + '; Max-Age=' + (60 * 60 * 24 * 180) + '; path=/; SameSite=Lax';
            if (typeof window.gtag === 'function') {
                var g = all ? 'granted' : 'denied';
                window.gtag('consent', 'update', { analytics_storage: g, ad_storage: g, ad_user_data: g, ad_personalization: g, personalization_storage: g });
            }
            banner.hidden = true;
        });
    }
})();
