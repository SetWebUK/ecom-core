{{--
    Activity timeline (order notes, history).
    <x-admin.timeline>
        <x-admin.timeline-item icon="chat-bubble-left" :time="$note->created_at" :author="$note->user?->full_name" bubble>{!! NoteFormatter::html($note->note) !!}</x-admin.timeline-item>
    </x-admin.timeline>
--}}
<ol {{ $attributes->class(['timeline']) }}>
    {{ $slot }}
</ol>
