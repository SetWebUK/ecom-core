{{-- Global confirm dialog used by Admin.confirm(), data-confirm="…" and <x-admin.confirm>. --}}
<div class="modal modal--confirm" x-data x-show="$store.confirm.open" x-cloak role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-message"
     @keydown.escape.window="$store.confirm.open && $store.confirm.answer(false)">
    <div class="modal__backdrop" x-show="$store.confirm.open" x-transition.opacity @click="$store.confirm.answer(false)"></div>
    <div class="modal__panel" x-show="$store.confirm.open" x-transition:enter="enter" x-transition:enter-start="pop-from" x-transition:enter-end="to-visible" x-transition:leave="leave" x-transition:leave-start="to-visible" x-transition:leave-end="fade-from"
         x-trap.noscroll="$store.confirm.open">
        <div class="modal__header"><h2 class="modal__title" id="confirm-title" x-text="$store.confirm.title"></h2></div>
        <div class="modal__body" id="confirm-message" x-show="$store.confirm.message" x-text="$store.confirm.message"></div>
        <div class="modal__footer">
            <button type="button" class="btn" @click="$store.confirm.answer(false)" x-text="$store.confirm.cancelText"></button>
            <button type="button" class="btn" :class="$store.confirm.danger ? 'btn--danger' : 'btn--primary'" @click="$store.confirm.answer(true)" x-text="$store.confirm.confirmText"></button>
        </div>
    </div>
</div>
