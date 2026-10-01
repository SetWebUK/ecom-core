{{-- Toast stack (Admin.toast(), session flashes). --}}
<div class="toasts" role="status" aria-live="polite" aria-atomic="false" x-data>
    <template x-for="t in $store.toasts.items" :key="t.id">
        <div class="toast" :class="'toast--' + t.type" x-transition:enter="enter" x-transition:enter-start="pop-from" x-transition:enter-end="to-visible" x-transition:leave="leave" x-transition:leave-start="to-visible" x-transition:leave-end="fade-from">
            <span class="toast__icon" x-html="$store.toasts.icon(t.type)"></span>
            <span x-text="t.message"></span>
            <template x-if="t.action"><a class="toast__action" :href="t.action.href" x-text="t.action.label"></a></template>
            <button type="button" class="toast__close" @click="$store.toasts.remove(t.id)" aria-label="Dismiss"><x-admin.icon name="x-mark" size="sm" /></button>
        </div>
    </template>
</div>
