{{-- Admin › Updates › one pine/commerce update run: live log while it runs (polls admin.updates.status). --}}
@extends('commerce::admin.layouts.app')

@section('title', 'Update #'.$update->id.' · Updates')

@section('content')
    <x-admin.page-header :title="'pine/commerce '.$update->from_version.' → '.$update->to_version" :back="route('admin.updates.index')" back-label="Updates"
                         :subtitle="'Approved by '.$update->actor().($update->approved_at ? ' on '.\Pine\Commerce\Services\Admin\LocalTime::format($update->approved_at, 'j M Y, H:i') : '').'.'" />

    <div class="layout" x-data="updateRun(@js($payload), @js(route('admin.updates.status', $update)))">
        <div class="layout__main stack">
            <template x-if="status === 'approved'">
                <x-admin.callout type="info" title="Waiting to start">
                    The update has been approved and should start within a few seconds.
                    <span x-show="waitedLong">It has not started yet – a developer can start it on the server with
                        <code>php artisan commerce:update:run {{ $update->id }}</code>.</span>
                </x-admin.callout>
            </template>
            <template x-if="status === 'running'">
                <x-admin.callout type="warning" icon="arrow-path" title="Updating – keep this page open if you like">
                    Step: <strong x-text="stepLabel || 'starting'"></strong>. The shop shows a maintenance page until it finishes.
                </x-admin.callout>
            </template>
            <template x-if="status === 'succeeded'">
                <x-admin.callout type="success" :title="'Updated to pine/commerce '.$update->to_version">The shop is back online.</x-admin.callout>
            </template>
            <template x-if="status === 'failed'">
                <x-admin.callout type="danger" title="The update failed – the previous version was restored">
                    <span x-text="error"></span>
                    @if ($restore)
                        <span style="display:block" class="mt-2">The database was not restored automatically. If the shop misbehaves, a developer can restore the backup taken before the update:
                            <code class="break">{{ $restore }}</code></span>
                    @endif
                </x-admin.callout>
            </template>

            <x-admin.card flush title="Log" subtitle="Every step and the output of each command.">
                <pre class="update-log" x-ref="log" aria-live="polite"><template x-for="(line, i) in lines" :key="i"><span x-text="line + '\n'"></span></template></pre>
            </x-admin.card>
        </div>

        <div class="layout__aside stack">
            <x-admin.card title="Steps">
                <ol class="update-steps">
                    @foreach ($steps as $key => $label)
                        <li x-bind:class="stepClass(@js($key))">{{ $label }}</li>
                    @endforeach
                </ol>
            </x-admin.card>
            @if ($bypass)
                <x-admin.card title="Maintenance bypass" subtitle="While the shop shows its maintenance page, this link lets you (and anyone you send it to) see the site. It stops working when the update ends.">
                    <code class="break">{{ $bypass }}</code>
                </x-admin.card>
            @endif
            @if ($update->meta('backup'))
                <x-admin.card title="Database backup">
                    <p class="text-sm" style="margin:0"><code class="break">{{ basename((string) $update->meta('backup')) }}</code></p>
                    <p class="text-xs text-muted" style="margin:8px 0 0">Kept on the server outside the website folder.</p>
                </x-admin.card>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('updateRun', (initial, url) => ({
                status: initial.status, step: initial.step, stepLabel: initial.step_label, error: initial.error,
                lines: initial.lines, next: initial.next, finished: initial.finished, waited: 0, waitedLong: false,
                order: @js(array_keys($steps)),
                init() { this.scroll(); if (! this.finished) { this.poll(); } },
                async poll() {
                    try {
                        const data = await Admin.fetch(url, { data: { from: this.next } });
                        this.status = data.status; this.step = data.step; this.stepLabel = data.step_label; this.error = data.error;
                        if (data.lines.length) { this.lines.push(...data.lines); this.scroll(); }
                        this.next = data.next; this.finished = data.finished;
                    } catch (e) {
                        // the site is briefly unavailable while composer replaces files – keep polling
                    }
                    this.waited += 2; this.waitedLong = this.status === 'approved' && this.waited > 30;
                    if (! this.finished) { setTimeout(() => this.poll(), 2000); }
                },
                scroll() { this.$nextTick(() => { const el = this.$refs.log; if (el) { el.scrollTop = el.scrollHeight; } }); },
                stepClass(key) {
                    const at = this.order.indexOf(this.step), me = this.order.indexOf(key);
                    if (this.status === 'succeeded') { return 'is-done'; }
                    if (me < at) { return 'is-done'; }
                    if (me === at) { return this.status === 'failed' ? 'is-failed' : (this.status === 'running' ? 'is-current' : ''); }
                    return '';
                },
            }));
        });
    </script>
@endpush
