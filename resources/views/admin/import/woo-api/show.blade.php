{{-- Admin › Import › WooCommerce API › one run: live progress (polls admin.import.woo.status), cancel / resume, log. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@section('title', 'Import #'.$run->id.' · Import')

@section('content')
    <x-admin.page-header :title="($run->dry_run ? 'Dry run' : 'Import').' #'.$run->id.' from '.$run->site_url" :back="route('admin.import.woo.index')" back-label="Import"
                         :subtitle="'Started by '.$run->actor().' '.$run->created_at->diffForHumans().' · '.($run->mode === 'store' ? 'public Store API' : 'REST API').($run->source === null ? ' · same site as the database import' : '')" />

    <div class="layout" x-data="wooImport(@js($payload), @js(route('admin.import.woo.status', $run)))">
        <div class="layout__main stack">
            <template x-if="status === 'pending'">
                <x-admin.callout type="info" title="Waiting to start">
                    The import should start within a few seconds.
                    <span x-show="waited > 30">It has not started yet – a developer can start it on the server with <code>php artisan commerce:import-woo-api {{ $run->id }}</code>.</span>
                </x-admin.callout>
            </template>
            <template x-if="status === 'running' || status === 'cancelling'">
                <x-admin.callout type="warning" icon="arrow-path" title="Importing – you can leave this page">
                    <span x-text="status === 'cancelling' ? 'Cancelling after the current page…' : (current ? 'Now: ' + label(current) : 'Starting…')"></span>
                </x-admin.callout>
            </template>
            <template x-if="status === 'completed'">
                <x-admin.callout type="success" :title="$run->dry_run ? 'Dry run finished – nothing was written' : 'Import finished'">
                    <span x-text="warnings + ' warnings, ' + errors + ' errors.'"></span>
                </x-admin.callout>
            </template>
            <template x-if="status === 'failed' || status === 'interrupted'">
                <x-admin.callout type="danger" title="The import stopped">
                    <span x-text="error || 'It stopped before the end.'"></span>
                    <span x-show="resumable"> Everything up to the last complete page is saved – resume to continue from there.</span>
                </x-admin.callout>
            </template>
            <template x-if="status === 'cancelled'">
                <x-admin.callout type="neutral" title="Cancelled">Everything up to the last complete page is saved. Resume to continue.</x-admin.callout>
            </template>

            <x-admin.card flush title="Progress" :subtitle="$run->dry_run ? 'Counts are what would be created / updated.' : 'Per entity: read from the shop, created, updated, skipped (left alone), failed.'">
                <x-admin.table compact stack>
                    <x-slot:head>
                        <th scope="col">What</th><th scope="col">Status</th><th scope="col" style="width:22%">Read</th>
                        <th scope="col" class="num">{{ $run->dry_run ? 'New' : 'Created' }}</th><th scope="col" class="num">Updated</th>
                        <th scope="col" class="num">Skipped</th><th scope="col" class="num">Failed</th><th scope="col">Also</th>
                    </x-slot:head>
                    <template x-for="e in entities" :key="e.key">
                        <tr>
                            <td class="stack-title" x-text="e.label"></td>
                            <td data-label="Status"><span class="badge badge--sm" :class="badge(e.status)" x-text="e.status"></span></td>
                            <td data-label="Read">
                                <div class="progress" :class="{ 'progress--success': e.status === 'done' }" x-show="e.total"><div class="progress__bar" :style="'width:' + percent(e) + '%'"></div></div>
                                <span class="text-xs text-muted" x-text="e.fetched + (e.total ? ' / ' + e.total : '')"></span>
                            </td>
                            <td class="num" data-label="Created" x-text="e.created"></td>
                            <td class="num" data-label="Updated" x-text="e.updated"></td>
                            <td class="num" data-label="Skipped" x-text="e.skipped"></td>
                            <td class="num" data-label="Failed" :class="{ 'text-danger': e.failed > 0 }" x-text="e.failed"></td>
                            <td class="text-xs text-muted" data-label="Also" x-text="extras(e)"></td>
                        </tr>
                    </template>
                </x-admin.table>
            </x-admin.card>

            <x-admin.card flush title="Warnings and errors" subtitle="With the shop's id of the item, newest first (the full list is in the log).">
                <div class="card__body" x-show="! issues.length"><x-admin.empty icon="check-circle" title="None so far" size="sm" /></div>
                <x-admin.table compact stack x-show="issues.length">
                    <x-slot:head><th scope="col">When</th><th scope="col">Level</th><th scope="col">What</th><th scope="col">Shop id</th><th scope="col">Message</th></x-slot:head>
                    <template x-for="(i, n) in issues" :key="n">
                        <tr>
                            <td class="nowrap text-xs" data-label="When" x-text="i.at"></td>
                            <td data-label="Level"><span class="badge badge--sm" :class="i.level === 'error' ? 'badge--danger' : 'badge--warning'" x-text="i.level"></span></td>
                            <td data-label="What" x-text="label(i.entity)"></td>
                            <td class="nowrap" data-label="Shop id" x-text="i.id ?? '—'"></td>
                            <td class="break text-sm" data-label="Message" x-text="i.message"></td>
                        </tr>
                    </template>
                </x-admin.table>
            </x-admin.card>

            <x-admin.card flush title="Log">
                <x-slot:actions><x-admin.button size="sm" variant="plain" icon="arrow-down-tray" :href="route('admin.import.woo.log', $run)">Download log</x-admin.button></x-slot:actions>
                <pre class="update-log" x-ref="log" aria-live="polite"><template x-for="(line, i) in lines" :key="i"><span x-text="line + '\n'"></span></template></pre>
            </x-admin.card>
        </div>

        <div class="layout__aside stack">
            <x-admin.card title="Options">
                <dl class="kv kv--stacked">
                    <dt>Importing</dt><dd>{{ collect($run->option('entities', []))->map(fn ($e) => $choices[$e] ?? $e)->implode(', ') }}</dd>
                    <dt>Images</dt><dd>{{ $run->option('images', true) ? 'downloaded' : 'not downloaded' }}</dd>
                    <dt>Items imported before</dt><dd>{{ $run->option('existing') === 'skip' ? 'left alone' : 'updated' }}</dd>
                    @if ($run->option('orders_after'))<dt>Orders from</dt><dd>{{ $run->option('orders_after') }}</dd>@endif
                    @if ($run->option('since'))<dt>Changed since</dt><dd>{{ $run->option('since') }}</dd>@endif
                    <dt>Order notes</dt><dd>{{ $run->option('notes', true) ? 'yes' : 'no' }}</dd>
                </dl>
            </x-admin.card>
            <x-admin.card title="Actions">
                <div class="stack">
                    <form method="POST" action="{{ route('admin.import.woo.cancel', $run) }}" x-show="status === 'pending' || status === 'running'">
                        @csrf
                        <x-admin.button type="submit" variant="ghost-danger" icon="stop" block data-confirm-title="Cancel this import?"
                                        data-confirm="It stops after the page it is working on. What was imported so far stays; you can resume later." data-confirm-button="Cancel import">Cancel</x-admin.button>
                    </form>
                    <form method="POST" action="{{ route('admin.import.woo.resume', $run) }}" x-show="resumable">
                        @csrf
                        <x-admin.button type="submit" variant="primary" icon="play" block>Resume</x-admin.button>
                    </form>
                    <x-admin.button :href="route('admin.import.woo.index')" icon="arrow-uturn-left" block>New import</x-admin.button>
                </div>
            </x-admin.card>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('wooImport', (initial, url) => ({
                status: initial.status, entities: initial.entities, issues: initial.issues, lines: initial.lines, next: initial.next,
                finished: initial.finished, resumable: initial.resumable, warnings: initial.warnings, errors: initial.errors,
                error: initial.error, current: initial.current, waited: 0,
                labels: @js($labels),
                init() { this.scroll(); if (! this.finished) { this.poll(); } },
                async poll() {
                    try {
                        const data = await Admin.fetch(url, { data: { from: this.next } });
                        Object.assign(this, { status: data.status, entities: data.entities, issues: data.issues, finished: data.finished,
                            resumable: data.resumable, warnings: data.warnings, errors: data.errors, error: data.error, current: data.current, next: data.next });
                        if (data.lines.length) { this.lines.push(...data.lines); this.scroll(); }
                    } catch (e) { /* keep polling */ }
                    this.waited += 2;
                    if (! this.finished) { setTimeout(() => this.poll(), 2000); }
                },
                label(key) { return this.labels[key] || key; },
                percent(e) { return e.total ? Math.min(100, Math.round(e.fetched / e.total * 100)) : 0; },
                badge(s) { return { done: 'badge--success', running: 'badge--attention', skipped: 'badge--gray', waiting: 'badge--gray' }[s] || 'badge--gray'; },
                extras(e) {
                    const parts = [];
                    for (const k of ['variations', 'images', 'refunds', 'notes']) { if (e[k]) { parts.push(e[k] + ' ' + k); } }
                    return parts.join(', ');
                },
                scroll() { this.$nextTick(() => { const el = this.$refs.log; if (el) { el.scrollTop = el.scrollHeight; } }); },
            }));
        });
    </script>
@endpush
