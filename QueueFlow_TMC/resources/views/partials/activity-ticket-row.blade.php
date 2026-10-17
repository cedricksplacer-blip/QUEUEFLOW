{{--
    One Recent-Activity ticket row. $ticket is a QueueTicket with office,
    student, course and service relations loaded. The polling script in
    admin/dashboard renders the same shape from JSON — keep both in sync.
--}}
<div class="qf-act-row">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs font-bold text-slate-900">{{ $ticket->queue_number }}</span>
                <span class="badge badge-{{ $ticket->status === 'no_show' ? 'cancelled' : $ticket->status }}">{{ $ticket->status === 'no_show' ? 'No-Show' : $ticket->status }}</span>
                @if($ticket->isPwd())
                    {{-- The PWD indicator itself opens the proof modal: a real
                         image when the student uploaded one, otherwise a clear
                         "No PWD proof picture available for this student." notice. --}}
                    <button
                        type="button"
                        class="js-pwd-proof badge badge-pwd qf-pwd-badge"
                        title="{{ filled($ticket->priority_evidence) ? 'View PWD verification proof' : 'No PWD proof picture available for this student.' }}"
                        aria-label="View PWD verification proof for queue {{ $ticket->queue_number }}"
                        data-url="{{ filled($ticket->priority_evidence) ? route('admin.queues.evidence', $ticket) : '' }}"
                        data-student="{{ $ticket->student->name ?? 'Walk-in' }}"
                        data-student-id="{{ $ticket->student->student_id ?? '' }}"
                        data-office="{{ $ticket->office->name ?? '' }}"
                        data-queue="{{ $ticket->queue_number }}"
                        data-purpose="{{ $ticket->purpose ?? '' }}"
                        data-status="{{ $ticket->status }}"
                        data-time="{{ optional($ticket->created_at)->format('M d, Y h:i A') ?? '' }}"
                    >PWD</button>
                @endif
            </div>
            <div class="text-xs font-semibold text-slate-700 mt-1 truncate">
                {{ $ticket->student->name ?? 'Walk-in' }}
                @if(!empty($ticket->student?->student_id))
                    <span class="font-normal text-slate-400">({{ $ticket->student->student_id }})</span>
                @endif
            </div>
            <div class="text-[11px] text-slate-500 mt-0.5">
                {{ $ticket->office->name ?? 'N/A' }}
                @if($ticket->course) · {{ $ticket->course->code }}@endif
                · {{ $ticket->purpose ?? 'No purpose stated' }}
            </div>
            <div class="text-[10px] text-slate-400 mt-0.5">
                {{ optional($ticket->created_at)->format('M d, h:i A') ?? '—' }}
            </div>
        </div>
    </div>
</div>
