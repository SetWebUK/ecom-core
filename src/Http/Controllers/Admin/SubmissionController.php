<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Exports\CsvExport;
use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Content\ContentBulkRequest;
use Pine\Commerce\Models\FormSubmission;
use Pine\Commerce\Services\Admin\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Inbox: contact form enquiries (and anything else stored in form_submissions). Opening one marks it read. */
class SubmissionController extends Controller
{
    use AdminIndex;

    public const SORTS = ['created_at', 'name', 'email'];

    public const FORM_LABELS = ['contact' => 'Contact form', 'contact-form' => 'Contact form (old site)'];

    public function index(Request $request): View
    {
        $status = $this->filterValue($request, 'status', ['unread' => 1, 'read' => 1]) ?? 'all';
        $forms = FormSubmission::query()->distinct()->orderBy('form')->pluck('form');
        $form = $this->filterValue($request, 'form', $forms->flip()->all());
        [$sort, $direction] = $this->sorting($request, self::SORTS, 'created_at', 'desc');

        $submissions = $this->filtered($request)
            ->select(['id', 'form', 'name', 'email', 'phone', 'subject', 'message', 'read_at', 'created_at'])
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $counts = FormSubmission::query()->selectRaw('count(*) as total, sum(read_at is null) as unread')->first();

        return view('commerce::admin.submissions.index', [
            'submissions' => $submissions,
            'status' => $status,
            'tabs' => [
                'all' => ['label' => 'All', 'count' => (int) $counts->total],
                'unread' => ['label' => 'Unread', 'count' => (int) $counts->unread],
                'read' => ['label' => 'Read', 'count' => (int) $counts->total - (int) $counts->unread],
            ],
            'formOptions' => $forms->mapWithKeys(fn ($f) => [$f => static::formLabel($f)])->all(),
            'chips' => array_filter(['form' => $form ? 'Form: '.static::formLabel($form) : null]),
            'isFiltered' => $this->searchTerm($request) !== '' || $form || $status !== 'all',
        ]);
    }

    public function show(FormSubmission $submission): View
    {
        if (! $submission->read_at) {
            $submission->forceFill(['read_at' => now()])->save();
        }

        $newer = FormSubmission::where('created_at', '>', $submission->created_at)->orderBy('created_at')->orderBy('id')->value('id');
        $older = FormSubmission::where('created_at', '<', $submission->created_at)->orderByDesc('created_at')->orderByDesc('id')->value('id');
        $fromSender = $submission->email
            ? FormSubmission::where('email', $submission->email)->whereKeyNot($submission->id)->latest()->limit(5)->get(['id', 'subject', 'message', 'created_at'])
            : collect();

        return view('commerce::admin.submissions.show', [
            'submission' => $submission,
            'fields' => static::extraFields($submission),
            'newer' => $newer,
            'older' => $older,
            'fromSender' => $fromSender,
            'customer' => $submission->email ? \Pine\Commerce\Models\User::where('email', $submission->email)->first(['id', 'name', 'first_name', 'last_name', 'role']) : null,
        ]);
    }

    public function unread(FormSubmission $submission): RedirectResponse
    {
        $submission->forceFill(['read_at' => null])->save();

        return redirect()->route('admin.form-submissions.index')->with('success', 'Marked as unread.');
    }

    public function destroy(FormSubmission $submission): RedirectResponse
    {
        $submission->delete();

        return redirect()->route('admin.form-submissions.index')->with('success', 'Message deleted.');
    }

    public function bulk(ContentBulkRequest $request): RedirectResponse
    {
        $query = FormSubmission::whereIn('id', $request->ids());
        $action = $request->input('action');
        $count = match ($action) {
            'read' => $query->whereNull('read_at')->update(['read_at' => now()]),
            'unread' => $query->whereNotNull('read_at')->update(['read_at' => null]),
            'delete' => $query->delete(),
        };
        $verb = ['read' => 'marked as read', 'unread' => 'marked as unread', 'delete' => 'deleted'][$action];

        return back()->with('success', $count.' '.Str::plural('message', $count).' '.$verb.'.');
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->orderBy('id');

        return CsvExport::stream('form-submissions-'.now()->format('Y-m-d').'.csv', ['Date', 'Form', 'Name', 'Email', 'Phone', 'Subject', 'Message', 'Read'], (function () use ($query) {
            foreach ($query->lazyById(500) as $s) {
                yield [$s->created_at ? LocalTime::format($s->created_at, 'Y-m-d H:i') : null, static::formLabel($s->form), $s->name, $s->email, $s->phone, $s->subject, $s->message, $s->read_at ? 'yes' : 'no'];
            }
        })());
    }

    public static function formLabel(?string $form): string
    {
        return self::FORM_LABELS[$form] ?? Str::headline((string) $form);
    }

    /** Extra submitted fields (old WordPress form fields, page, browser) as label => value, without the ones shown already. */
    public static function extraFields(FormSubmission $submission): array
    {
        $data = (array) $submission->data;
        $fields = [];
        foreach ((array) ($data['fields'] ?? []) as $key => $value) {
            if (in_array($key, ['your-name', 'your-email', 'your-message', 'your-phone-number', 'your-subject'], true) || is_array($value)) {
                continue;
            }
            $fields[Str::headline(str_replace('your-', '', (string) $key))] = (string) $value;
        }
        if (! empty($data['page_url'])) {
            $fields['Sent from page'] = (string) $data['page_url'];
        }
        if (! empty($data['form_title'])) {
            $fields['Form'] = (string) $data['form_title'];
        }
        if (! empty($data['user_agent'])) {
            $fields['Browser'] = (string) $data['user_agent'];
        }

        return $fields;
    }

    protected function filtered(Request $request): Builder
    {
        $q = $this->searchTerm($request);
        $status = $this->filterValue($request, 'status', ['unread' => 1, 'read' => 1]);
        $form = is_string($request->query('form')) ? $request->query('form') : null;

        return FormSubmission::query()
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->where('name', 'like', $this->like($q))
                ->orWhere('email', 'like', $this->like($q))
                ->orWhere('subject', 'like', $this->like($q))
                ->orWhere('message', 'like', $this->like($q))))
            ->when($status === 'unread', fn (Builder $query) => $query->whereNull('read_at'))
            ->when($status === 'read', fn (Builder $query) => $query->whereNotNull('read_at'))
            ->when($form, fn (Builder $query) => $query->where('form', $form));
    }
}
