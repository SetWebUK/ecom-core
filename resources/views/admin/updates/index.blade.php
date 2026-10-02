{{-- Admin › Updates (administrators, feature "updater"): Pine\Commerce\Http\Controllers\Admin\UpdateController@index --}}
@extends('commerce::admin.layouts.app')

@section('title', 'Updates')

@php
    $md = fn (?string $text) => \Illuminate\Support\Str::markdown((string) $text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    $statusColor = ['checked' => 'gray', 'approved' => 'info', 'running' => 'attention', 'succeeded' => 'success', 'failed' => 'danger',
        'planned' => 'gray', 'applied' => 'success'];
    $typeLabel = ['check' => 'Update check', 'core' => 'pine/commerce update', 'skeleton' => 'Skeleton files'];
    $available = $status['available'];
    $planFiles = (array) ($plan?->result['files'] ?? []);
    $safeFiles = array_keys(array_filter($planFiles, fn ($e) => ! empty($e['safe'])));
@endphp

@section('content')
    <x-admin.page-header title="Updates" subtitle="Platform updates for this shop. Updates are found automatically but are only installed after an administrator approves them." />

    <div class="stack">
        @if ($open)
            <x-admin.callout type="warning" icon="arrow-path" :title="'Update to pine/commerce '.$open->to_version.' is '.$open->status">
                <a href="{{ route('admin.updates.show', $open) }}">Follow its progress</a>.
            </x-admin.callout>
        @endif

        <x-admin.card title="pine/commerce" subtitle="The shop platform: storefront, back office, themes and importer.">
            <x-slot:actions>
                <form method="POST" action="{{ route('admin.updates.check') }}">
                    @csrf
                    <x-admin.button type="submit" size="sm" icon="arrow-path">Check now</x-admin.button>
                </form>
            </x-slot:actions>

            <dl class="kv">
                <dt>Installed</dt><dd><strong>{{ $installedPretty ?? 'unknown' }}</strong></dd>
                <dt>Allowed versions</dt><dd><code>{{ $constraint ?? '—' }}</code> <span class="text-xs text-muted">(composer.json – a developer changes this for a new major version)</span></dd>
                <dt>Source</dt><dd class="break">{{ $repository['url'] ?? '—' }} <span class="text-xs text-muted">({{ $repository['type'] }}, from {{ $repository['source'] }})</span></dd>
                <dt>Last checked</dt>
                <dd>
                    @if ($check)
                        <x-admin.time :value="$check->created_at" /> · {{ $check->actor() }}
                        @if ($result['via'] ?? null)<span class="text-xs text-muted">via {{ $result['via'] }}</span>@endif
                    @else
                        Never – press “Check now”.
                    @endif
                </dd>
            </dl>

            @if ($check && ($result['error'] ?? null))
                <x-admin.callout type="danger" class="mt-4" title="The last check failed">{{ $result['error'] }}</x-admin.callout>
            @elseif ($check && $available)
                <x-admin.callout type="warning" class="mt-4" icon="arrow-up-circle" :title="'Version '.$status['latest'].' is available'">
                    Read the changes below. Installing takes a few minutes: the database is backed up, the shop shows a
                    maintenance page meanwhile, and everything is rolled back automatically if a step fails.
                </x-admin.callout>
            @elseif ($check)
                <x-admin.callout type="success" class="mt-4" title="You are up to date">No newer release within the allowed versions.</x-admin.callout>
            @endif

            @if (! empty($result['blocked']))
                <x-admin.callout type="neutral" class="mt-4" icon="wrench-screwdriver" :title="'Version '.$result['blocked']['version'].' requires a developer'">
                    {{ $result['blocked']['reason'] }}
                </x-admin.callout>
            @endif

            @if ($check && $available && ! $open)
                <x-slot:footer>
                    <span class="text-sm text-muted">You will be asked for your password.</span>
                    <span class="flex-1"></span>
                    <x-admin.button variant="primary" icon="arrow-down-tray" x-data x-on:click="$dispatch('open-modal', 'approve-update')">
                        Approve &amp; install {{ $status['latest'] }}
                    </x-admin.button>
                </x-slot:footer>
            @endif
        </x-admin.card>

        @if ($check && ! empty($result['changelog']) && ($available || ! empty($result['blocked'])))
            <x-admin.card title="What changes" subtitle="From the release notes (CHANGELOG.md) of every version after the installed one.">
                <div class="stack">
                    @foreach ($result['changelog'] as $section)
                        <section class="changelog" data-version="{{ $section['version'] }}">
                            <h3 class="changelog__title">
                                {{ $section['version'] }}
                                @if ($section['date'])<span class="text-sm text-muted fw-400">{{ $section['date'] }}</span>@endif
                                @if ($section['actions_required'])<x-admin.badge color="warning" size="sm">Client actions required</x-admin.badge>@endif
                            </h3>
                            @if ($section['client_actions'] !== null)
                                <x-admin.callout :type="$section['actions_required'] ? 'warning' : 'neutral'" title="Client actions required">
                                    <div class="changelog__body">{!! $md($section['client_actions']) !!}</div>
                                </x-admin.callout>
                            @endif
                            <details class="mt-2">
                                <summary class="link">Full release notes</summary>
                                <div class="changelog__body">{!! $md($section['body']) !!}</div>
                            </details>
                        </section>
                    @endforeach
                </div>
            </x-admin.card>
        @elseif ($check && ($result['changelog_error'] ?? null))
            <x-admin.callout type="neutral" title="Release notes not available">{{ $result['changelog_error'] }}</x-admin.callout>
        @endif

        {{-- skeleton files --}}
        <x-admin.card title="Project files from the skeleton" subtitle="Files this project got from the base system (ecom-skeleton): configuration stubs, bootstrap, tests. Only files nobody changed here can be updated from the admin." id="skeleton">
            <dl class="kv">
                <dt>Baseline</dt>
                <dd>
                    @if ($baseline)
                        <code>{{ $baseline['ref'] }}</code>
                        @unless ($baseline['updates'])<x-admin.badge color="gray" size="sm">Skeleton file updates off</x-admin.badge>@endunless
                    @else
                        Not recorded
                    @endif
                </dd>
                @if ($baseline && $baseline['note'])
                    <dt>Note</dt><dd>{{ $baseline['note'] }}</dd>
                @endif
                @if ($result['skeleton']['latest'] ?? null)
                    <dt>Newest for this core</dt><dd><code>{{ $result['skeleton']['latest'] }}</code></dd>
                @endif
            </dl>
            @if (! $baseline)
                <p class="text-sm text-muted mt-2">A developer records it once with <code>php artisan commerce:skeleton:baseline &lt;tag&gt;</code> (or <code>--detect</code>).</p>
            @elseif ($baseline['updates'])
                <x-slot:footer>
                    <span class="text-sm text-muted">Compares the project with the newest skeleton release – nothing is changed.</span>
                    <span class="flex-1"></span>
                    <form method="POST" action="{{ route('admin.updates.skeleton.compare') }}">
                        @csrf
                        <x-admin.button type="submit" size="sm" icon="document-magnifying-glass">Compare skeleton files</x-admin.button>
                    </form>
                </x-slot:footer>
            @endif
        </x-admin.card>

        @if ($plan && $plan->status === 'planned' && ($baseline['updates'] ?? false))
            <x-admin.card flush :title="'Skeleton '.$plan->from_version.' → '.$plan->to_version" :subtitle="'Compared '.$plan->created_at->diffForHumans().'. Tick the files to update; files changed here are listed for a developer and are never overwritten.'">
                @if (! $planFiles)
                    <div class="card__body"><x-admin.empty icon="check-circle" title="Nothing to update" description="The skeleton files did not change between these releases." size="sm" /></div>
                @else
                    <form method="POST" action="{{ route('admin.updates.skeleton.apply', $plan) }}" id="skeleton-form" x-data="{ all: false }">
                        @csrf
                        <x-admin.table compact stack>
                            <x-slot:head>
                                <th scope="col" class="table__check"><span class="sr-only">Apply</span></th>
                                <th scope="col">File</th><th scope="col">Status</th><th scope="col" class="num">Upstream</th><th scope="col" class="num">Here</th>
                            </x-slot:head>
                            @foreach ($planFiles as $path => $entry)
                                <tr data-file="{{ $path }}">
                                    <td class="table__check">
                                        @if ($entry['safe'])
                                            <input type="checkbox" class="checkbox" name="files[]" value="{{ $path }}" aria-label="Update {{ $path }}" x-bind:checked="all">
                                        @endif
                                    </td>
                                    <td class="stack-title">
                                        <code class="break">{{ $path }}</code>
                                        @if ($entry['diff'])
                                            <details><summary class="link text-xs">Upstream changes</summary><pre class="diff">@foreach (explode("\n", $entry['diff']) as $line)<span @class(['diff__add' => str_starts_with($line, '+'), 'diff__del' => str_starts_with($line, '-'), 'diff__hunk' => str_starts_with($line, '@@')])>{{ $line }}</span>
@endforeach</pre></details>
                                        @endif
                                    </td>
                                    <td data-label="Status">
                                        <x-admin.badge size="sm" :color="$entry['safe'] ? 'success' : (in_array($entry['status'], ['current', 'protected'], true) ? 'gray' : 'warning')">{{ \Pine\Commerce\Updater\Skeleton\SkeletonComparer::LABELS[$entry['status']] ?? $entry['status'] }}</x-admin.badge>
                                    </td>
                                    <td class="num nowrap" data-label="Upstream">@if ($entry['upstream'])+{{ $entry['upstream']['added'] }} −{{ $entry['upstream']['removed'] }}@endif</td>
                                    <td class="num nowrap" data-label="Here">@if ($entry['local'])+{{ $entry['local']['added'] }} −{{ $entry['local']['removed'] }}@endif</td>
                                </tr>
                            @endforeach
                        </x-admin.table>
                        @if ($safeFiles)
                            <div class="card__footer">
                                <label class="check"><input type="checkbox" class="checkbox" x-model="all"> Select all safe files ({{ count($safeFiles) }})</label>
                                <span class="flex-1"></span>
                                <x-admin.button variant="primary" x-on:click="$dispatch('open-modal', 'apply-skeleton')">Update selected files…</x-admin.button>
                            </div>
                        @endif
                    </form>
                @endif
            </x-admin.card>
        @endif

        <x-admin.card flush title="History" subtitle="Every check, approval and update, with who did it.">
            @if ($history->isEmpty())
                <div class="card__body"><x-admin.empty icon="clock" title="No updates yet" size="sm" /></div>
            @else
                <x-admin.table compact stack>
                    <x-slot:head>
                        <th scope="col">When</th><th scope="col">What</th><th scope="col">Versions</th><th scope="col">Who</th><th scope="col">Result</th>
                    </x-slot:head>
                    @foreach ($history as $row)
                        <tr>
                            <td class="nowrap" data-label="When"><x-admin.time :value="$row->created_at" /></td>
                            <td class="stack-title">
                                @if ($row->type === 'core')
                                    <a class="row-link" href="{{ route('admin.updates.show', $row) }}">{{ $typeLabel[$row->type] }} #{{ $row->id }}</a>
                                @else
                                    {{ $typeLabel[$row->type] ?? $row->type }}
                                @endif
                                @if ($row->type === 'skeleton' && $row->files)<span class="text-xs text-muted">· {{ count($row->files) }} file(s)</span>@endif
                            </td>
                            <td data-label="Versions" class="nowrap">{{ $row->from_version ?? '—' }} @if ($row->to_version)→ {{ $row->to_version }}@endif</td>
                            <td data-label="Who" class="break">{{ $row->actor() }}</td>
                            <td data-label="Result">
                                <x-admin.badge size="sm" :color="$statusColor[$row->status] ?? 'gray'">{{ ucfirst($row->status) }}</x-admin.badge>
                                @if ($row->error)<span class="text-xs text-muted truncate" title="{{ $row->error }}" style="display:block;max-width:280px">{{ $row->error }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$history" />
            @endif
        </x-admin.card>
    </div>

    @if ($check && $available && ! $open)
        <x-admin.modal name="approve-update" :title="'Install pine/commerce '.$status['latest'].'?'" :open="$errors->hasAny(['password', 'confirm'])">
            <form method="POST" action="{{ route('admin.updates.install') }}" id="approve-update-form" class="stack">
                @csrf
                <input type="hidden" name="check" value="{{ $check->id }}">
                <p class="text-sm">This updates the shop platform from <strong>{{ $status['installed'] }}</strong> to <strong>{{ $status['latest'] }}</strong>:</p>
                <ol class="text-sm" style="margin:0;padding-left:20px">
                    <li>the database is backed up;</li>
                    <li>the shop shows a maintenance page for a few minutes (you can keep using it);</li>
                    <li>the new version is installed, the database updated and the caches rebuilt;</li>
                    <li>a health check runs, then the shop comes back. If any step fails, the previous version is restored.</li>
                </ol>
                @if ($result['actions_required'] ?? false)
                    <x-admin.callout type="warning" title="The release notes list actions for this site">A developer may need to do something after the update – see “Client actions required” above.</x-admin.callout>
                @endif
                <x-admin.input name="password" type="password" label="Your password" required autocomplete="current-password" />
                <x-admin.checkbox name="confirm" :unchecked="null" label="I have read the changes and approve this update" />
            </form>
            <x-slot:footer>
                <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
                <x-admin.button variant="primary" type="submit" form="approve-update-form">Approve &amp; install</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    @if ($plan && $plan->status === 'planned' && $safeFiles)
        <x-admin.modal name="apply-skeleton" title="Update the selected skeleton files?" size="sm" :open="$errors->hasAny(['files', 'password', 'confirm'])">
            <div class="stack" x-data>
                <p class="text-sm">The selected files are replaced with the skeleton’s version {{ $plan->to_version }}. A copy of every file that is replaced or removed is kept on the server.</p>
                @error('files')<p class="field__error">{{ $message }}</p>@enderror
                <x-admin.input name="password" type="password" label="Your password" required autocomplete="current-password" form="skeleton-form" />
                <x-admin.checkbox name="confirm" :unchecked="null" label="Update these files" form="skeleton-form" />
            </div>
            <x-slot:footer>
                <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
                <x-admin.button variant="primary" type="submit" form="skeleton-form">Update files</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
@endsection
