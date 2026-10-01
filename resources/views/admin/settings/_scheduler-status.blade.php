{{-- Settings › Scheduled tasks: cron heartbeat + every core task (Pine\Commerce\Scheduling\Scheduler::status()). Read-only. --}}
@php
    use Pine\Commerce\Scheduling\Scheduler;
    $beat = Scheduler::lastHeartbeat();
    $running = Scheduler::cronRunning();
    $fallback = (bool) config('commerce.scheduler.web_fallback', false);
    $cronLine = '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1';
    $tasks = Scheduler::status();
@endphp
@if ($running)
    <x-admin.callout type="success" title="Cron is running">Last check-in <x-admin.time :value="$beat" format="relative" />. Every task below runs on its own.</x-admin.callout>
@else
    <x-admin.callout type="warning" :title="$beat ? 'Cron has stopped' : 'Cron is not set up'">
        @if ($beat)Last check-in <x-admin.time :value="$beat" format="relative" />. @endif
        Without it, unpaid orders are still cancelled when someone visits the checkout{{ $fallback ? ', and the other tasks run in the background after shop pages load (at most every 5 minutes).' : '; the other tasks wait until cron is added.' }}
        Ask your developer or host to add this cron job (cPanel › Cron Jobs › “Once Per Minute”):
        <div class="copy-field mt-2" x-data>
            <span class="mono text-xs">{{ $cronLine }}</span>
            <button type="button" class="btn btn--sm" @click="navigator.clipboard.writeText(@js($cronLine)).then(() => Admin.toast('Copied'))"><x-admin.icon name="clipboard-document" /><span>Copy</span></button>
        </div>
    </x-admin.callout>
@endif

<x-admin.table class="mt-4">
    <x-slot:head>
        <x-admin.th>Task</x-admin.th>
        <x-admin.th>Last run</x-admin.th>
        <x-admin.th>Next run</x-admin.th>
    </x-slot:head>
    @foreach ($tasks as $task)
        <tr data-task="{{ $task['key'] }}">
            <td style="max-width:360px">
                <div class="fw-600">{{ $task['label'] }}
                    @unless ($task['core'])<x-admin.badge size="sm" color="gray">Custom</x-admin.badge>@endunless
                    @if (! $task['configured'])<x-admin.badge size="sm">Off</x-admin.badge>
                    @elseif ($task['skip'])<x-admin.badge size="sm" color="gray">Idle</x-admin.badge>
                    @else<x-admin.badge size="sm" color="success">On</x-admin.badge>@endif
                </div>
                <div class="cell-sub">{{ $task['skip'] ?: $task['description'] }}</div>
            </td>
            <td class="nowrap">
                @if ($task['last'])
                    <x-admin.time :value="$task['last']['at']" format="relative" />
                    <div class="cell-sub">
                        @if ($task['last']['status'] === 'failed')<span class="text-danger">Failed: {{ $task['last']['summary'] }}</span>@else{{ $task['last']['summary'] }}@endif
                    </div>
                @else
                    <span class="text-muted">Never</span>
                @endif
            </td>
            <td class="nowrap">@if ($task['next'] && $running)<x-admin.time :value="$task['next']" format="relative" />@elseif ($task['next'])<span class="text-muted">Needs cron</span>@else<span class="text-muted">–</span>@endif</td>
        </tr>
    @endforeach
</x-admin.table>
<p class="text-xs text-muted mt-2">Developers: <span class="mono">php artisan commerce:schedule:status</span> shows the same table; <span class="mono">php artisan commerce:schedule:task {task}</span> runs one now.</p>
