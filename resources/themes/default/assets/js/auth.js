/*!
 * Pine Commerce – sign-in / register / password pages (vanilla JS, no build step). Loaded only on those pages.
 * Markup hooks (any theme may reuse them):
 *   button[data-password-toggle="<input id>"]   show/hide the password; rendered `hidden`, shown here (no JS = no button).
 *       Optional child [data-password-toggle-label] gets "Show password" / "Hide password".
 *   [data-password-strength="<input id>"][data-min="8"]   strength meter: child .pw-strength__bar spans are lit by
 *       data-score="0..4" on the container; [data-password-strength-label] gets the wording; li[data-rule="length|case|number"]
 *       get class "is-met".
 *   [data-password-match="<input id>"][data-for="<confirm input id>"]   "Passwords match" / "Passwords do not match yet".
 *   form[data-auth-form]   submit button gets .is-busy + aria-busy while the page posts (stops double submits).
 * Server-side validation stays the source of truth; none of this blocks submitting.
 */
(function () {
    'use strict';

    var d = document;
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || d).querySelectorAll(sel)); };
    var byId = function (id) { return d.getElementById(id); };

    // ---- show / hide password
    $$('[data-password-toggle]').forEach(function (btn) {
        var input = byId(btn.getAttribute('data-password-toggle'));
        if (!input) { return; }
        var label = btn.querySelector('[data-password-toggle-label]');
        btn.hidden = false;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.classList.toggle('is-shown', show);
            if (label) { label.textContent = show ? 'Hide password' : 'Show password'; }
            else { btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); }
            input.focus({ preventScroll: true });
            try { var n = input.value.length; input.setSelectionRange(n, n); } catch (e) { /* email/number inputs */ }
        });
        if (!label && !btn.getAttribute('aria-label')) { btn.setAttribute('aria-label', 'Show password'); }
    });

    // ---- strength meter
    var WORDS = ['Too short', 'Weak', 'Fair', 'Good', 'Strong'];
    function score(value, min) {
        if (value.length < min) { return 0; }
        var s = 1;
        if (value.length >= min + 4) { s++; }
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) { s++; }
        if (/[0-9]/.test(value) || /[^A-Za-z0-9]/.test(value)) { s++; }
        if (/^(.)\1+$/.test(value) || /^(password|12345678|qwerty)/i.test(value)) { s = 1; }
        return Math.min(4, s);
    }
    $$('[data-password-strength]').forEach(function (box) {
        var input = byId(box.getAttribute('data-password-strength'));
        if (!input) { return; }
        var min = parseInt(box.getAttribute('data-min'), 10) || 8;
        var label = box.querySelector('[data-password-strength-label]');
        var rules = {};
        $$('[data-rule]', box).forEach(function (li) { rules[li.getAttribute('data-rule')] = li; });
        var initial = label ? label.textContent : '';
        var last = null;
        function update() {
            var v = input.value;
            var met = {
                length: v.length >= min,
                'case': /[a-z]/.test(v) && /[A-Z]/.test(v),
                number: /[0-9]/.test(v) || /[^A-Za-z0-9]/.test(v)
            };
            Object.keys(rules).forEach(function (k) { rules[k].classList.toggle('is-met', !!met[k]); });
            var s = v === '' ? -1 : score(v, min);
            box.setAttribute('data-score', String(s));
            if (!label) { return; }
            var text = s < 0 ? initial : (s === 0 ? 'Too short – use at least ' + min + ' characters.' : 'Password strength: ' + WORDS[s]);
            if (text !== last) { label.textContent = text; last = text; }
        }
        input.addEventListener('input', update);
        update();
    });

    // ---- confirm password
    $$('[data-password-match]').forEach(function (out) {
        var first = byId(out.getAttribute('data-password-match'));
        var second = byId(out.getAttribute('data-for'));
        if (!first || !second) { return; }
        function update() {
            if (second.value === '') { out.textContent = ''; out.className = out.className.replace(/\s?is-(ok|bad)/g, ''); return; }
            var ok = first.value === second.value;
            out.textContent = ok ? 'Passwords match.' : 'Passwords do not match yet.';
            out.classList.toggle('is-ok', ok);
            out.classList.toggle('is-bad', !ok);
        }
        first.addEventListener('input', update);
        second.addEventListener('input', update);
    });

    // ---- busy state on submit
    $$('form[data-auth-form]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('[type="submit"]');
            if (!btn) { return; }
            window.setTimeout(function () { btn.classList.add('is-busy'); btn.setAttribute('aria-busy', 'true'); btn.disabled = true; }, 0);
        });
    });
    // back/forward cache: undo the busy state
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) { return; }
        $$('form[data-auth-form] [type="submit"]').forEach(function (btn) { btn.classList.remove('is-busy'); btn.removeAttribute('aria-busy'); btn.disabled = false; });
    });
})();
