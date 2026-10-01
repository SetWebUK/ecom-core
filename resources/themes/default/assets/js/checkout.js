/*!
 * Pine Commerce – default theme checkout (vanilla JS). Uses the core endpoints every theme uses:
 *   POST /checkout/update  {shipping_method?, billing_email?, shipping_country/postcode/state/city?, billing_*?,
 *                           bill_to_different_address?}  → fragments (summary, shipping_methods, total, needs_payment…)
 *   The address picks the shipping zone and the tax rates, so it is sent whenever those fields change.
 *   POST|DELETE /cart/coupon {coupon_code, context: 'checkout'} → {ok, message, checkout: fragments}
 *   POST /checkout (form)  → {result: success|action|failure, redirect, client_secret, return_url, pay_url, errors, messages}
 *   POST /checkout/order-pay/{order} (order pay form) → same result shape
 * Stripe Payment Element (cards, Apple Pay, Google Pay), PayPal redirect, bank transfer. Without JavaScript the
 * forms post normally.
 */
(function () {
    'use strict';

    var d = document;
    var $ = function (sel, ctx) { return (ctx || d).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || d).querySelectorAll(sel)); };
    var csrf = function () { var m = $('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; };
    var config = {};
    try { config = JSON.parse(($('#checkout-config') || {}).textContent || '{}') || {}; } catch (e) { config = {}; }

    var POSTCODE = /^(GIR ?0AA|BFPO ?\d{1,4}|[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2})$/i;
    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

    function request(url, method, body) {
        var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() };
        var opts = { method: method || 'GET', headers: headers, credentials: 'same-origin' };
        if (body instanceof FormData) { opts.body = body; } else if (body) { headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                data = data || {};
                if (r.status === 419) { data.result = 'failure'; data.messages = ['Your session has expired. Please refresh the page and try again.']; }
                if (r.status === 429) { data.result = 'failure'; data.messages = ['Too many attempts. Please wait a moment and try again.']; }
                return data;
            });
        });
    }

    /* ------------------------------------------------------------------ alerts & field errors */
    var alertBox = $('[data-alerts]');
    function alerts(messages, type) {
        if (!alertBox) { return; }
        alertBox.innerHTML = '';
        messages = (messages || []).filter(Boolean);
        if (!messages.length) { return; }
        var box = d.createElement('div');
        box.className = 'notice notice--' + (type || 'error');
        box.setAttribute('role', type === 'error' || !type ? 'alert' : 'status');
        if (messages.length > 1) {
            var ul = d.createElement('ul');
            messages.forEach(function (m) { var li = d.createElement('li'); li.textContent = m; ul.appendChild(li); });
            box.appendChild(ul);
        } else {
            box.textContent = messages[0];
        }
        alertBox.appendChild(box);
        var top = alertBox.getBoundingClientRect().top + window.pageYOffset - 20;
        if (top < window.pageYOffset) { window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' }); }
    }

    function fieldError(input, message) {
        var field = input.closest('.field');
        if (!field) { return; }
        var existing = $('.field__error', field);
        if (!message) {
            field.classList.remove('has-error');
            input.removeAttribute('aria-invalid');
            if (existing) { existing.remove(); }
            return;
        }
        field.classList.add('has-error');
        input.setAttribute('aria-invalid', 'true');
        if (!existing) {
            existing = d.createElement('p');
            existing.className = 'field__error';
            existing.id = (input.id || input.name) + '_error';
            field.appendChild(existing);
            input.setAttribute('aria-describedby', existing.id);
        }
        existing.textContent = message;
    }

    function labelText(input) {
        var label = input.id ? $('label[for="' + input.id + '"]') : null;
        return label ? label.childNodes[0].textContent.trim() : (input.name || 'This field');
    }

    function validateInput(input) {
        if (input.closest('[hidden]') || input.disabled || input.type === 'hidden' || input.type === 'checkbox' || input.type === 'radio') { return true; }
        var value = String(input.value || '').trim();
        var msg = '';
        if (input.hasAttribute('required') && value === '') { msg = labelText(input) + ' is required.'; }
        else if (value !== '') {
            if (input.type === 'email' && !EMAIL.test(value)) { msg = 'Please enter a valid email address.'; }
            else if (/_postcode$/.test(input.name) && (($('#' + input.name.replace('_postcode', '_country')) || {}).value || 'GB') === 'GB' && !POSTCODE.test(value.replace(/\s+/g, ' '))) { msg = 'Please enter a valid UK postcode.'; }
            else if (/_phone$/.test(input.name) && (/[^\s#0-9_\-+\/().]/.test(value) || value.replace(/\D/g, '').length < 7)) { msg = 'Please enter a valid phone number.'; }
        }
        fieldError(input, msg);
        return msg === '';
    }

    function validate(scope) {
        var ok = true, first = null;
        $$('input, select, textarea', scope).forEach(function (input) {
            if (input.getAttribute('form')) { return; }
            if (!validateInput(input)) { ok = false; first = first || input; }
        });
        var create = $('[data-create-account]', scope), pw = $('#account_password', scope);
        if (create && create.checked && pw) {
            if (String(pw.value).length < 8) { fieldError(pw, 'Please choose a password of at least 8 characters.'); ok = false; first = first || pw; }
            else { fieldError(pw, ''); }
        }
        var messages = [];
        var paySection = $('[data-payment-section]', scope);
        var needsPayment = !paySection || !paySection.hidden;
        if (needsPayment && $('input[name="payment_method"]', scope) && !$('input[name="payment_method"]:checked', scope)) {
            ok = false; messages.push('Please choose a payment method.');
        }
        var terms = $('[data-terms]', scope);
        if (terms && !terms.checked) { ok = false; messages.push('Please read and accept the terms and conditions to continue.'); first = first || terms; }
        if (!ok) { alerts(messages.length ? messages : ['Please check the highlighted fields.'], 'error'); }
        if (first && first.focus) { first.focus(); }
        return ok;
    }

    d.addEventListener('focusout', function (e) {
        var input = e.target;
        if (input.matches && input.matches('[data-checkout] input, [data-checkout] select') && input.closest('.has-error')) { validateInput(input); }
    });

    /* ------------------------------------------------------------------ toggles */
    var form = $('form[data-checkout]');
    var payForm = $('form[data-order-pay]');

    function selectChoice(input) {
        var list = input.closest('.choice-list');
        if (!list) { return; }
        $$('.choice', list).forEach(function (li) {
            var on = !!$('input:checked', li) && $('input', li).name === input.name;
            if ($('input', li).name !== input.name) { return; }
            li.classList.toggle('is-selected', on);
            var panel = $('[data-gateway-panel]', li);
            if (panel) { panel.hidden = !on; }
        });
    }

    /* address → shipping zone + tax rates (debounced) */
    var addressTimer = null;
    function addressPayload() {
        var payload = {};
        ['shipping', 'billing'].forEach(function (p) {
            ['country', 'postcode', 'state', 'city'].forEach(function (f) {
                var el = form && form.elements.namedItem(p + '_' + f);
                if (el && typeof el.value === 'string') { payload[p + '_' + f] = el.value; }
            });
        });
        var toggle = $('[data-billing-toggle]:checked');
        payload.bill_to_different_address = toggle ? toggle.value : 'same_as_shipping';
        return payload;
    }
    function addressChanged() {
        clearTimeout(addressTimer);
        addressTimer = setTimeout(function () { updateCheckout(addressPayload()); }, 250);
    }

    d.addEventListener('change', function (e) {
        var t = e.target;
        if (form && t.name && /^(shipping|billing)_(country|postcode|state|city)$/.test(t.name)) { addressChanged(); }
        if (form && t.matches('[data-billing-toggle]')) { addressChanged(); }
        if (t.matches('input[type="radio"]')) { selectChoice(t); }
        if (t.matches('[data-billing-toggle]')) {
            var box = $('[data-billing-fields]');
            if (box) { box.hidden = t.value !== 'different_from_shipping'; }
        } else if (t.matches('[data-create-account]')) {
            var pw = $('[data-account-password]');
            if (pw) { pw.hidden = !t.checked; if (t.checked) { var i = $('input', pw); if (i) { i.focus(); } } }
        } else if (t.matches('input[name="payment_method"]')) {
            if (t.value === 'stripe') { mountStripe(); }
        } else if (t.matches('input[name="shipping_method"]') && form) {
            updateCheckout({ shipping_method: t.value });
        } else if (t.matches('#billing_email') && form && EMAIL.test(t.value)) {
            updateCheckout({ billing_email: t.value });
        }
    });

    /* ------------------------------------------------------------------ totals refresh & coupons */
    var busy = 0;
    function applyFragments(data) {
        if (!data) { return; }
        if (data.empty && data.redirect) { window.location.href = data.redirect; return; }
        var summary = $('[data-summary]');
        if (summary && typeof data.summary === 'string') { summary.innerHTML = data.summary; }
        var ship = $('[data-shipping-methods]');
        if (ship && typeof data.shipping_methods === 'string') { ship.innerHTML = data.shipping_methods; }
        if (data.mobile_total) { $$('[data-order-total]').forEach(function (el) { el.textContent = data.mobile_total; }); }
        if (data.total !== undefined) { config.total = Number(data.total); }
        var paySection = $('[data-payment-section]');
        if (paySection && data.needs_payment !== undefined) { paySection.hidden = !data.needs_payment; }
        if (stripeElements && data.amount) { try { stripeElements.update({ amount: Math.max(1, data.amount) }); } catch (e) {} }
        if (data.notices && data.notices.length) { alerts(data.notices, 'info'); }
    }

    function updateCheckout(payload) {
        busy++;
        form.classList.add('is-updating');
        return request(form.getAttribute('data-update-url'), 'POST', payload).then(applyFragments).catch(function () {}).then(function () {
            busy--; if (!busy) { form.classList.remove('is-updating'); }
        });
    }

    function coupon(method, code) {
        return request(form.getAttribute('data-coupon-url'), method, { coupon_code: code, context: 'checkout' }).then(function (data) {
            if (data.checkout) { applyFragments(data.checkout); }
            if (data.message) { alerts([data.message], data.ok ? 'success' : 'error'); }
            else if (data.errors && data.errors.coupon_code) { alerts(data.errors.coupon_code, 'error'); }
        });
    }

    if (form) {
        d.addEventListener('click', function (e) {
            var apply = e.target.closest('[data-coupon-apply]');
            if (apply) {
                e.preventDefault();
                var input = $('[data-coupon-input]');
                var code = input ? input.value.trim() : '';
                if (!code) { alerts(['Please enter a discount code.'], 'error'); return; }
                apply.disabled = true;
                coupon('POST', code).then(function () { apply.disabled = false; });
                return;
            }
            var remove = e.target.closest('[data-coupon-remove]');
            if (remove) { e.preventDefault(); coupon('DELETE', remove.getAttribute('data-coupon-remove')); }
        });
        d.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.matches && e.target.matches('[data-coupon-input]')) {
                e.preventDefault();
                var btn = $('[data-coupon-apply]'); if (btn) { btn.click(); }
            }
        });
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({ ecommerce: null });
        window.dataLayer.push({ event: 'begin_checkout', ecommerce: { currency: config.currency || 'GBP', value: Number(config.total) || 0, items: config.items || [] } });
    }

    /* ------------------------------------------------------------------ Stripe Payment Element */
    var stripe = null, stripeElements = null, stripeMounted = false;
    function mountStripe() {
        if (stripeMounted || !config.stripe || !config.stripe.key) { return; }
        var target = $('[data-stripe-element]');
        if (!target) { return; }
        if (typeof window.Stripe !== 'function') { setTimeout(mountStripe, 300); return; }
        stripeMounted = true;
        var styles = getComputedStyle(d.documentElement);
        stripe = window.Stripe(config.stripe.key);
        stripeElements = stripe.elements({
            mode: 'payment',
            amount: Math.max(1, Number(config.stripe.amount) || 1),
            currency: config.stripe.currency || 'gbp',
            appearance: { theme: 'stripe', variables: {
                colorPrimary: styles.getPropertyValue('--c-primary').trim() || '#1d4ed8',
                colorText: styles.getPropertyValue('--c-text').trim() || '#0f172a',
                borderRadius: styles.getPropertyValue('--radius').trim() || '10px',
                fontFamily: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif'
            } }
        });
        var el = stripeElements.create('payment', { layout: { type: 'tabs', defaultCollapsed: false } });
        el.mount(target);
        el.on('change', function () { var err = $('[data-stripe-errors]'); if (err) { err.textContent = ''; } });
    }
    function stripeSelected(scope) { var c = $('input[name="payment_method"]:checked', scope); return c && c.value === 'stripe'; }
    function stripeError(message) { var box = $('[data-stripe-errors]'); if (box) { box.textContent = message; } alerts([message], 'error'); }

    /* ------------------------------------------------------------------ place order / pay */
    function setBusy(btn, on) { if (btn) { btn.disabled = on; btn.classList.toggle('is-busy', on); btn.setAttribute('aria-busy', on ? 'true' : 'false'); } }

    function handleResult(data, btn, scope) {
        if (data.result === 'success' && data.redirect) { window.location.href = data.redirect; return; }
        if (data.result === 'action' && data.client_secret && stripe && stripeElements) {
            stripe.confirmPayment({ elements: stripeElements, clientSecret: data.client_secret, confirmParams: { return_url: data.return_url } })
                .then(function (res) { if (res && res.error) { stripeError(res.error.message || 'Your payment could not be completed. Please try again.'); } setBusy(btn, false); });
            return;
        }
        if (data.result === 'action') { window.location.href = data.pay_url || window.location.href; return; }
        setBusy(btn, false);
        if (data.errors) {
            Object.keys(data.errors).forEach(function (name) {
                var input = scope.querySelector('[name="' + name + '"]');
                if (input) { fieldError(input, data.errors[name][0]); }
            });
            var firstError = $('.has-error input, .has-error select', scope);
            if (firstError) { firstError.focus(); }
        }
        alerts(data.messages || ['Sorry, something went wrong. Please try again.'], 'error');
        if (data.refresh && form) { updateCheckout({}); }
        if (data.redirect && !data.errors && data.result === 'failure' && /basket|order-pay/.test(data.redirect)) {
            setTimeout(function () { window.location.href = data.redirect; }, 1500);
        }
    }

    function submit(scope, e) {
        if (!window.fetch || !window.FormData) { return; }
        e.preventDefault();
        var btn = $('[data-place-order]', scope);
        if (btn && btn.disabled) { return; }
        alerts([]);
        if (!validate(scope)) { return; }
        setBusy(btn, true);
        var useStripe = stripeSelected(scope) && config.stripe;
        var pre = Promise.resolve(true);
        if (useStripe) {
            mountStripe();
            if (!stripeElements) { setBusy(btn, false); alerts(['The card form is still loading – please try again in a moment.'], 'error'); return; }
            pre = stripeElements.submit().then(function (res) { if (res && res.error) { stripeError(res.error.message); return false; } return true; });
        }
        pre.then(function (ok) {
            if (!ok) { setBusy(btn, false); return; }
            return request(scope.getAttribute('action'), 'POST', new FormData(scope)).then(function (data) { handleResult(data, btn, scope); });
        }).catch(function () {
            setBusy(btn, false);
            alerts(['Sorry, we could not reach the server. Please check your connection and try again.'], 'error');
        });
    }

    if (form) { form.addEventListener('submit', function (e) { submit(form, e); }); }
    if (payForm) { payForm.addEventListener('submit', function (e) { submit(payForm, e); }); }

    var initial = $('input[name="payment_method"]:checked');
    if (initial) { selectChoice(initial); if (initial.value === 'stripe') { mountStripe(); } }
    var summaryDetails = $('[data-summary-details]');
    if (summaryDetails && window.matchMedia('(max-width: 999px)').matches) { summaryDetails.open = false; }
})();
