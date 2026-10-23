/*!
 * Pine Commerce – default theme checkout (vanilla JS). Uses the core endpoints every theme uses:
 *   POST /checkout/update  {shipping_method?, billing_email?, shipping_country/postcode/state/city?, billing_*?,
 *                           bill_to_different_address?}  → fragments (summary, shipping_methods, total, needs_payment…)
 *   The address picks the shipping zone and the tax rates, so it is sent whenever those fields change.
 *   POST|DELETE /cart/coupon {coupon_code, context: 'checkout'} → {ok, message, checkout: fragments}
 *   POST /checkout (form)  → {result: success|action|failure, redirect, client_secret, return_url, pay_url, errors, messages}
 *   POST /checkout/order-pay/{order} (order pay form) → same result shape
 * Stripe Payment Element (cards, Apple Pay, Google Pay), PayPal redirect, Google Pay / Apple Pay through PayPal's JS
 * SDK, bank transfer. Without JavaScript the forms post normally.
 *   GET return_url (wallets, AJAX) → {result: success|failure, redirect, messages}
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
            showWalletButton();
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
        if (paySection && data.needs_payment !== undefined) { paySection.hidden = !data.needs_payment; showWalletButton(); }
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
            if (remove) { e.preventDefault(); coupon('DELETE', remove.getAttribute('data-coupon-remove')); return; }
            // loyalty points: use them / keep them (the URL is on the button), then re-render the summary
            var points = e.target.closest('[data-points-apply], [data-points-remove]');
            if (points) {
                e.preventDefault();
                points.disabled = true;
                var use = points.hasAttribute('data-points-apply');
                request(points.getAttribute(use ? 'data-points-apply' : 'data-points-remove'), use ? 'POST' : 'DELETE', { context: 'checkout' }).then(function (data) {
                    if (data.checkout) { applyFragments(data.checkout); }
                    if (data.message) { alerts([data.message], data.ok ? 'success' : 'error'); }
                    points.disabled = false;
                });
            }
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

    /* ------------------------------------------------------------------ Google Pay / Apple Pay (through PayPal) */
    // config.wallets = {methods: {googlepay|applepay: method code}, sandbox, currency, country, storeName}. Each method
    // starts hidden and is shown once PayPal says the account can take it and the browser can pay that way. While one
    // is selected its own button stands in for "Place order": the sheet opens first (inside the click, as Google and
    // Apple require), then the order is placed, confirmed with the wallet's payment data and captured (return URL).
    var wallets = config.wallets && config.wallets.methods ? config.wallets : null;
    var walletBox = null, googleClient = null, googleConfig = null, appleConfig = null;
    var WALLET_NAMES = { googlepay: 'Google Pay', applepay: 'Apple Pay' };
    var WALLET_FAILED = 'Your payment could not be completed. Please try again or choose another payment method.';

    function walletScope() { return form || payForm; }
    function selectedWallet(scope) {
        if (!wallets || !scope) { return null; }
        var paySection = $('[data-payment-section]', scope);
        if (paySection && paySection.hidden) { return null; }
        var checked = $('input[name="payment_method"]:checked', scope);
        var li = checked && checked.closest('[data-wallet]');
        return li && !li.hidden ? li.getAttribute('data-wallet') : null;
    }
    function showWalletButton() {
        var scope = walletScope();
        if (!scope || !walletBox) { return; }
        var wallet = selectedWallet(scope), place = $('[data-place-order]', scope);
        $$('[data-wallet-button]', walletBox).forEach(function (b) { b.hidden = b.getAttribute('data-wallet-button') !== wallet; });
        walletBox.hidden = !wallet;
        if (place) { place.hidden = !!wallet; }
    }
    function addWalletButton(wallet, button) {
        var scope = walletScope(), place = scope && $('[data-place-order]', scope);
        if (!place) { return; }
        if (!walletBox) {
            walletBox = d.createElement('div');
            walletBox.className = 'wallet-buttons';
            walletBox.hidden = true;
            place.parentNode.insertBefore(walletBox, place.nextSibling);
        }
        var wrap = d.createElement('div');
        wrap.className = 'wallet-button wallet-button--' + wallet;
        wrap.setAttribute('data-wallet-button', wallet);
        wrap.hidden = true;
        wrap.appendChild(button);
        walletBox.appendChild(wrap);
        var li = $('[data-gateway="' + wallets.methods[wallet] + '"]', scope);
        if (li) { li.hidden = false; }
        showWalletButton();
    }
    function walletBusy(on) {
        if (!walletBox) { return; }
        walletBox.classList.toggle('is-busy', on);
        walletBox.setAttribute('aria-busy', on ? 'true' : 'false');
    }
    function walletTotal() { return (Math.round(Number(config.total) * 100) / 100).toFixed(2); }
    // the order is placed once the customer has approved in the sheet; field errors come back as for "Place order"
    function walletOrder(scope) { return request(scope.getAttribute('action'), 'POST', new FormData(scope)); }
    function walletFailed(data, scope) {
        walletBusy(false);
        if (data && data.result) { handleResult(data, null, scope); } else { alerts([WALLET_FAILED], 'error'); }
    }
    // capture: the return URL answers JSON over AJAX ({result, redirect, messages})
    function walletCapture(data) { return request(data.return_url, 'GET'); }

    function setUpGooglePay() {
        if (!wallets.methods.googlepay || !window.paypal || !window.paypal.Googlepay || !window.google || !window.google.payments) { return; }
        googleClient = new window.google.payments.api.PaymentsClient({ environment: wallets.sandbox ? 'TEST' : 'PRODUCTION' });
        window.paypal.Googlepay().config().then(function (cfg) {
            if (!cfg || !cfg.isEligible) { return; }
            googleConfig = cfg;
            return googleClient.isReadyToPay({ apiVersion: cfg.apiVersion || 2, apiVersionMinor: cfg.apiVersionMinor || 0, allowedPaymentMethods: cfg.allowedPaymentMethods })
                .then(function (res) {
                    if (res && res.result) {
                        addWalletButton('googlepay', googleClient.createButton({ onClick: googlePay, buttonType: 'pay', buttonColor: 'black', buttonSizeMode: 'fill', buttonRadius: 4, buttonLocale: 'en' }));
                    }
                });
        }).catch(function () {});
    }
    function googlePay() {
        var scope = walletScope();
        alerts([]);
        if (!googleConfig || !validate(scope)) { return; }
        googleClient.loadPaymentData({
            apiVersion: googleConfig.apiVersion || 2,
            apiVersionMinor: googleConfig.apiVersionMinor || 0,
            allowedPaymentMethods: googleConfig.allowedPaymentMethods,
            merchantInfo: googleConfig.merchantInfo,
            transactionInfo: { countryCode: googleConfig.countryCode || wallets.country, currencyCode: wallets.currency, totalPriceStatus: 'FINAL', totalPrice: walletTotal(), totalPriceLabel: 'Total' }
        }).then(function (paymentData) {
            walletBusy(true);
            return walletOrder(scope).then(function (data) {
                if (data.result !== 'action' || !data.paypal_order_id) { walletFailed(data, scope); return; }
                var gp = window.paypal.Googlepay();
                return gp.confirmOrder({ orderId: data.paypal_order_id, paymentMethodData: paymentData.paymentMethodData }).then(function (res) {
                    var status = res && res.status;
                    if (status === 'PAYER_ACTION_REQUIRED') { return gp.initiatePayerAction({ orderId: data.paypal_order_id }).then(function () { return walletCapture(data); }); }
                    if (status === 'APPROVED') { return walletCapture(data); }
                    return { result: 'failure', messages: [WALLET_FAILED] };
                }).then(function (res) {
                    if (res.result === 'success' && res.redirect) { window.location.href = res.redirect; return; }
                    walletFailed({ result: 'failure', messages: res.messages || [WALLET_FAILED] }, scope);
                });
            });
        }).catch(function (err) {
            walletBusy(false);
            if (err && err.statusCode === 'CANCELED') { return; }
            alerts([WALLET_FAILED], 'error');
        });
    }

    function setUpApplePay() {
        if (!wallets.methods.applepay || !window.paypal || !window.paypal.Applepay || !window.ApplePaySession) { return; }
        try { if (!window.ApplePaySession.canMakePayments()) { return; } } catch (e) { return; }
        window.paypal.Applepay().config().then(function (cfg) {
            if (!cfg || !cfg.isEligible) { return; }
            appleConfig = cfg;
            var button = d.createElement('apple-pay-button');
            button.setAttribute('buttonstyle', 'black');
            button.setAttribute('type', 'pay');
            button.setAttribute('locale', 'en-GB');
            button.addEventListener('click', applePay);
            addWalletButton('applepay', button);
        }).catch(function () {});
    }
    function applePay() {
        var scope = walletScope();
        alerts([]);
        if (!appleConfig || !validate(scope)) { return; }
        // created inside the click: Safari refuses a session started later
        var session = new window.ApplePaySession(4, {
            countryCode: appleConfig.countryCode || wallets.country,
            currencyCode: wallets.currency,
            merchantCapabilities: appleConfig.merchantCapabilities,
            supportedNetworks: appleConfig.supportedNetworks,
            requiredBillingContactFields: ['name', 'postalAddress'],
            total: { label: wallets.storeName, type: 'final', amount: walletTotal() }
        });
        var ap = window.paypal.Applepay();
        session.onvalidatemerchant = function (event) {
            ap.validateMerchant({ validationUrl: event.validationURL, displayName: wallets.storeName })
                .then(function (res) { session.completeMerchantValidation(res.merchantSession); })
                .catch(function () { try { session.abort(); } catch (e) {} alerts(['Apple Pay is not available right now. Please choose another payment method.'], 'error'); });
        };
        session.onpaymentauthorized = function (event) {
            walletBusy(true);
            walletOrder(scope).then(function (data) {
                if (data.result !== 'action' || !data.paypal_order_id) { session.completePayment(window.ApplePaySession.STATUS_FAILURE); walletFailed(data, scope); return; }
                return ap.confirmOrder({ orderId: data.paypal_order_id, token: event.payment.token, billingContact: event.payment.billingContact })
                    .then(function () { return walletCapture(data); })
                    .then(function (res) {
                        var ok = res.result === 'success' && res.redirect;
                        session.completePayment(ok ? window.ApplePaySession.STATUS_SUCCESS : window.ApplePaySession.STATUS_FAILURE);
                        if (ok) { window.location.href = res.redirect; return; }
                        walletFailed({ result: 'failure', messages: res.messages || [WALLET_FAILED] }, scope);
                    });
            }).catch(function () {
                try { session.completePayment(window.ApplePaySession.STATUS_FAILURE); } catch (e) {}
                walletFailed(null, scope);
            });
        };
        session.oncancel = function () { walletBusy(false); };
        session.begin();
    }

    function setUpWallets(tries) {
        if (!wallets || !walletScope()) { return; }
        // PayPal's SDK and the wallets' scripts load with the page (defer); wait briefly if one is late
        if ((!window.paypal || (wallets.methods.googlepay && !window.google)) && tries < 20) { setTimeout(function () { setUpWallets(tries + 1); }, 250); return; }
        setUpGooglePay();
        setUpApplePay();
    }

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
        var wallet = selectedWallet(scope);
        if (wallet) { alerts(['Please use the ' + WALLET_NAMES[wallet] + ' button to pay.'], 'info'); return; }
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
    if (initial && initial.closest('[data-wallet][hidden]')) {
        var visible = $$('input[name="payment_method"]').filter(function (i) { return !i.closest('[hidden]'); })[0];
        if (visible) { visible.checked = true; initial = visible; }
    }
    if (initial) { selectChoice(initial); if (initial.value === 'stripe') { mountStripe(); } }
    setUpWallets(0);
    var summaryDetails = $('[data-summary-details]');
    // phones: the summary starts closed – unless the customer has loyalty points to use (the button is inside it)
    if (summaryDetails && window.matchMedia('(max-width: 999px)').matches && !summaryDetails.querySelector('[data-points-apply]')) { summaryDetails.open = false; }
})();
