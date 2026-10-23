{{--
    Live chat widget (feature "live_chat"), pushed into every storefront layout's @stack('scripts') by
    Pine\Commerce\Services\LiveChat\WidgetComposer – self-contained (inline CSS + vanilla JS, no theme changes).
    Colours follow --chat-accent / --chat-accent-text, which a theme may set on :root.
    Data: $config (assistant name, greeting, endpoints, aiReady, storeName)
--}}
<style>
    .pc-chat{--chat-accent:var(--color-primary,#111827);--chat-accent-text:#fff;--chat-bg:#fff;--chat-text:#111827;--chat-muted:#6b7280;--chat-line:#e5e7eb;--chat-soft:#f3f4f6;
        position:fixed;right:16px;bottom:16px;z-index:9990;font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--chat-text)}
    .pc-chat *{box-sizing:border-box}
    .pc-chat__launch{display:flex;align-items:center;gap:8px;border:0;border-radius:999px;padding:12px 18px;background:var(--chat-accent);color:var(--chat-accent-text);
        font:600 15px/1 inherit;cursor:pointer;box-shadow:0 6px 24px rgba(0,0,0,.18)}
    .pc-chat__launch svg{width:22px;height:22px}
    .pc-chat__badge{min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:#dc2626;color:#fff;font-size:12px;line-height:20px;text-align:center}
    .pc-chat__panel{position:absolute;right:0;bottom:64px;width:min(380px,calc(100vw - 32px));height:min(560px,calc(100vh - 110px));display:flex;flex-direction:column;
        background:var(--chat-bg);border-radius:14px;box-shadow:0 12px 48px rgba(0,0,0,.22);overflow:hidden}
    .pc-chat__panel[hidden]{display:none}
    .pc-chat__head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:14px 16px;background:var(--chat-accent);color:var(--chat-accent-text)}
    .pc-chat__title{font-weight:700}
    .pc-chat__sub{font-size:13px;opacity:.85}
    .pc-chat__close{border:0;background:transparent;color:inherit;font-size:24px;line-height:1;cursor:pointer;padding:4px 8px}
    .pc-chat__log{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;background:var(--chat-bg)}
    .pc-chat__msg{max-width:85%;padding:9px 12px;border-radius:12px;white-space:pre-wrap;overflow-wrap:anywhere}
    .pc-chat__msg a{color:inherit;text-decoration:underline}
    .pc-chat__msg--customer{align-self:flex-end;background:var(--chat-accent);color:var(--chat-accent-text);border-bottom-right-radius:4px}
    .pc-chat__msg--assistant,.pc-chat__msg--staff{align-self:flex-start;background:var(--chat-soft);border-bottom-left-radius:4px}
    .pc-chat__who{display:block;font-size:12px;font-weight:600;color:var(--chat-muted);margin-bottom:2px}
    .pc-chat__msg--notice{align-self:center;max-width:95%;background:transparent;color:var(--chat-muted);font-size:13px;text-align:center;padding:2px 8px}
    .pc-chat__typing{align-self:flex-start;color:var(--chat-muted);font-size:13px}
    .pc-chat__actions{padding:0 16px 8px;display:flex;gap:8px;flex-wrap:wrap}
    .pc-chat__link{border:0;background:none;padding:0;color:var(--chat-muted);font-size:13px;text-decoration:underline;cursor:pointer}
    .pc-chat__details{padding:12px 16px;border-top:1px solid var(--chat-line);display:grid;gap:8px;background:var(--chat-soft)}
    .pc-chat__details[hidden]{display:none}
    .pc-chat input,.pc-chat textarea{width:100%;border:1px solid var(--chat-line);border-radius:8px;padding:9px 10px;font:inherit;color:inherit;background:#fff}
    .pc-chat__form{display:flex;gap:8px;padding:12px 16px;border-top:1px solid var(--chat-line)}
    .pc-chat__form textarea{resize:none;height:42px;max-height:120px}
    .pc-chat__send,.pc-chat__details button{border:0;border-radius:8px;padding:0 14px;background:var(--chat-accent);color:var(--chat-accent-text);font:600 14px/1 inherit;cursor:pointer;min-height:42px}
    .pc-chat__send:disabled{opacity:.5;cursor:default}
    .pc-chat__err{color:#b91c1c;font-size:13px;padding:0 16px 8px}
    .pc-chat__hp{position:absolute;left:-9999px}
    @media (max-width:480px){.pc-chat__launch span.pc-chat__label{display:none}.pc-chat__panel{position:fixed;inset:auto 8px 80px 8px;width:auto}}
    @media print{.pc-chat{display:none}}
</style>

<div class="pc-chat" data-live-chat>
    <div class="pc-chat__panel" role="dialog" aria-modal="false" aria-labelledby="pc-chat-title" hidden data-chat-panel>
        <div class="pc-chat__head">
            <div>
                <div class="pc-chat__title" id="pc-chat-title">{{ $config['aiReady'] ? $config['assistantName'] : $config['storeName'] }}</div>
                <div class="pc-chat__sub" data-chat-sub>{{ $config['aiReady'] ? 'Ask me anything about our products' : 'We usually reply within a few minutes' }}</div>
            </div>
            <button type="button" class="pc-chat__close" aria-label="Close chat" data-chat-close>&times;</button>
        </div>
        <div class="pc-chat__log" aria-live="polite" data-chat-log></div>
        <div class="pc-chat__actions">
            <button type="button" class="pc-chat__link" hidden data-chat-handoff>Talk to a person</button>
        </div>
        <form class="pc-chat__details" hidden data-chat-details novalidate>
            <input type="text" name="name" placeholder="Your name (optional)" maxlength="120" autocomplete="name" aria-label="Your name">
            <input type="email" name="email" placeholder="Your email" required maxlength="190" autocomplete="email" aria-label="Your email">
            <input type="text" name="company" class="pc-chat__hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button type="submit">Send my details</button>
        </form>
        <div class="pc-chat__err" hidden data-chat-error></div>
        <form class="pc-chat__form" data-chat-form>
            <textarea name="body" rows="1" maxlength="{{ \Pine\Commerce\Services\LiveChat\LiveChat::MAX_MESSAGE_LENGTH }}" placeholder="Type your message…" aria-label="Your message" required></textarea>
            <button type="submit" class="pc-chat__send">Send</button>
        </form>
    </div>
    <button type="button" class="pc-chat__launch" aria-expanded="false" data-chat-launch>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5m-9 6 3.2-3.2A2 2 0 0 1 8.6 16H18a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v14z"/></svg>
        <span class="pc-chat__label">Chat with us</span>
        <span class="pc-chat__badge" hidden data-chat-badge></span>
    </button>
</div>

<script>
(function () {
    var cfg = @json($config);
    var root = document.querySelector('[data-live-chat]');
    if (!root || root.dataset.ready) return;
    root.dataset.ready = '1';
    var $ = function (s) { return root.querySelector(s); };
    var panel = $('[data-chat-panel]'), log = $('[data-chat-log]'), form = $('[data-chat-form]'), input = form.querySelector('textarea'),
        send = form.querySelector('button'), launch = $('[data-chat-launch]'), badge = $('[data-chat-badge]'), handoffBtn = $('[data-chat-handoff]'),
        details = $('[data-chat-details]'), errorBox = $('[data-chat-error]');
    var KEY = 'pc-live-chat', state = {token: null, after: 0, status: null, unread: 0, busy: false}, timer = null, seen = {};
    try { var saved = JSON.parse(localStorage.getItem(KEY) || 'null'); if (saved && saved.token) state.token = saved.token; } catch (e) {}
    function save() { try { localStorage.setItem(KEY, JSON.stringify({token: state.token})); } catch (e) {} }
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
    function linkify(s) {
        return esc(s).replace(/\bhttps?:\/\/[^\s<]+[^\s<.,;:!?)\]'"]/g, function (u) {
            var same = u.indexOf(location.origin) === 0;
            return '<a href="' + u + '"' + (same ? '' : ' target="_blank" rel="noopener nofollow"') + '>' + u + '</a>';
        });
    }
    function bubble(m) {
        if (seen[m.id]) return; seen[m.id] = true;
        var el = document.createElement('div');
        el.className = 'pc-chat__msg pc-chat__msg--' + m.role;
        var who = m.role === 'staff' ? (m.author || 'Team') : (m.role === 'assistant' ? cfg.assistantName : '');
        el.innerHTML = (who ? '<span class="pc-chat__who">' + esc(who) + '</span>' : '') + linkify(m.body);
        log.appendChild(el);
        return el;
    }
    function scroll() { log.scrollTop = log.scrollHeight; }
    function typing(on) {
        var t = log.querySelector('.pc-chat__typing');
        if (on && !t) { t = document.createElement('div'); t.className = 'pc-chat__typing'; t.textContent = (state.status === 'ai' || !state.status) && cfg.aiReady ? cfg.assistantName + ' is typing…' : 'Sending…'; log.appendChild(t); scroll(); }
        if (!on && t) t.remove();
    }
    function error(msg) { errorBox.textContent = msg || ''; errorBox.hidden = !msg; }
    function apply(data, fromPoll) {
        if (!data) return;
        if (data.gone) { state.token = null; save(); return; }
        if (data.token) { state.token = data.token; save(); }
        state.status = data.status;
        (data.messages || []).forEach(function (m) {
            if (seen[m.id]) return;
            bubble(m); state.after = Math.max(state.after, m.id);
            if (fromPoll && panel.hidden && m.role !== 'customer') state.unread++;
        });
        badge.textContent = state.unread; badge.hidden = !state.unread;
        handoffBtn.hidden = !data.can_handoff;
        details.hidden = !data.ask_email;
        scroll(); schedule();
    }
    function post(url, body) {
        body = Object.assign({token: state.token, after: state.after}, body || {});
        return fetch(url, {method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify(body)}).then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) {
                    if (!r.ok) throw new Error(r.status === 419 ? 'Your session expired – please reload the page.' : (j.message || 'Something went wrong – please try again.'));
                    return j;
                });
            });
    }
    function poll() {
        if (!state.token || state.busy || document.hidden) return schedule();
        post(cfg.endpoints.poll).then(function (d) { apply(d, true); }).catch(function () { schedule(); });
    }
    function schedule() {
        clearTimeout(timer);
        if (!state.token) return;
        var waiting = state.status === 'waiting' || state.status === 'human';
        var delay = !panel.hidden ? (waiting ? 4000 : 15000) : (waiting ? 10000 : 0);
        if (delay) timer = setTimeout(poll, delay);
    }
    function open() {
        panel.hidden = false; launch.setAttribute('aria-expanded', 'true'); state.unread = 0; badge.hidden = true;
        if (!log.children.length) bubble({id: 'greeting', role: cfg.aiReady ? 'assistant' : 'notice', body: cfg.greeting});
        if (state.token) poll();
        setTimeout(function () { input.focus(); }, 50);
    }
    function close() { panel.hidden = true; launch.setAttribute('aria-expanded', 'false'); schedule(); launch.focus(); }
    launch.addEventListener('click', function () { panel.hidden ? open() : close(); });
    $('[data-chat-close]').addEventListener('click', close);
    root.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) close(); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', {cancelable: true})); } });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text || state.busy) return;
        state.busy = true; send.disabled = true; error('');
        var pending = bubble({id: 'tmp' + Date.now(), role: 'customer', body: text}); input.value = ''; scroll(); typing(true);
        post(cfg.endpoints.send, {body: text, page_url: location.href}).then(function (d) {
            pending.remove(); // replaced by the stored copy, in server order
            typing(false); apply(d);
        }).catch(function (err) { pending.remove(); typing(false); error(err.message); input.value = text; })
          .finally(function () { state.busy = false; send.disabled = false; input.focus(); });
    });
    handoffBtn.addEventListener('click', function () {
        if (!state.token) { input.value = 'I’d like to talk to a person, please.'; input.focus(); return; }
        handoffBtn.hidden = true;
        post(cfg.endpoints.handoff).then(apply).catch(function (err) { error(err.message); handoffBtn.hidden = false; });
    });
    details.addEventListener('submit', function (e) {
        e.preventDefault();
        var email = details.email.value.trim();
        if (!email || !details.email.checkValidity()) { error('Please enter a valid email address.'); return; }
        error('');
        post(cfg.endpoints.details, {name: details.name.value.trim(), email: email, company: details.company.value})
            .then(apply).catch(function (err) { error(err.message); });
    });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) schedule(); });
    if (state.token) { state.status = 'unknown'; post(cfg.endpoints.poll).then(function (d) { apply(d, false); }).catch(function () {}); }
})();
</script>
