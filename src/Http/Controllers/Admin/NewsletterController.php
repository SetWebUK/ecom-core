<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Exports\NewsletterCsvExport;
use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Content\ContentBulkRequest;
use Pine\Commerce\Models\NewsletterSubscriber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Newsletter sign-ups from the footer form: list, unsubscribe/resubscribe, delete, CSV export for a mailing tool. */
class NewsletterController extends Controller
{
    use AdminIndex;

    public const SORTS = ['email', 'created_at', 'unsubscribed_at'];

    public function index(Request $request): View
    {
        $status = $this->filterValue($request, 'status', ['subscribed' => 1, 'unsubscribed' => 1]) ?? 'all';
        $sources = NewsletterSubscriber::query()->whereNotNull('source')->distinct()->orderBy('source')->pluck('source');
        $source = $this->filterValue($request, 'source', $sources->flip()->all());
        [$sort, $direction] = $this->sorting($request, self::SORTS, 'created_at', 'desc');

        $subscribers = $this->filtered($request)
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $counts = NewsletterSubscriber::query()->selectRaw('count(*) as total, sum(unsubscribed_at is null) as subscribed')->first();

        return view('commerce::admin.newsletter.index', [
            'subscribers' => $subscribers,
            'status' => $status,
            'tabs' => [
                'all' => ['label' => 'All', 'count' => (int) $counts->total],
                'subscribed' => ['label' => 'Subscribed', 'count' => (int) $counts->subscribed],
                'unsubscribed' => ['label' => 'Unsubscribed', 'count' => (int) $counts->total - (int) $counts->subscribed],
            ],
            'sourceOptions' => $sources->mapWithKeys(fn ($s) => [$s => Str::headline($s)])->all(),
            'chips' => array_filter(['source' => $source ? 'Signed up via: '.Str::headline($source) : null]),
            'isFiltered' => $this->searchTerm($request) !== '' || $source || $status !== 'all',
        ]);
    }

    public function toggle(NewsletterSubscriber $subscriber): RedirectResponse
    {
        $subscriber->forceFill(['unsubscribed_at' => $subscriber->unsubscribed_at ? null : now()])->save();

        return back()->with('success', $subscriber->unsubscribed_at ? "{$subscriber->email} unsubscribed." : "{$subscriber->email} subscribed again.");
    }

    public function destroy(NewsletterSubscriber $subscriber): RedirectResponse
    {
        $subscriber->delete();

        return back()->with('success', "{$subscriber->email} removed from the list.");
    }

    public function bulk(ContentBulkRequest $request): RedirectResponse
    {
        $query = NewsletterSubscriber::whereIn('id', $request->ids());
        $action = $request->input('action');
        $count = match ($action) {
            'unsubscribe' => $query->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now(), 'updated_at' => now()]),
            'resubscribe' => $query->whereNotNull('unsubscribed_at')->update(['unsubscribed_at' => null, 'updated_at' => now()]),
            'delete' => $query->delete(),
        };
        $verb = ['unsubscribe' => 'unsubscribed', 'resubscribe' => 'subscribed again', 'delete' => 'removed'][$action];

        return back()->with('success', $count.' '.Str::plural('subscriber', $count).' '.$verb.'.');
    }

    public function export(Request $request): StreamedResponse
    {
        return NewsletterCsvExport::download($this->filtered($request));
    }

    protected function filtered(Request $request): Builder
    {
        $q = $this->searchTerm($request);
        $status = $this->filterValue($request, 'status', ['subscribed' => 1, 'unsubscribed' => 1]);
        $source = is_string($request->query('source')) ? $request->query('source') : null;

        return NewsletterSubscriber::query()
            ->when($q !== '', fn (Builder $query) => $query->where('email', 'like', $this->like($q)))
            ->when($status === 'subscribed', fn (Builder $query) => $query->whereNull('unsubscribed_at'))
            ->when($status === 'unsubscribed', fn (Builder $query) => $query->whereNotNull('unsubscribed_at'))
            ->when($source, fn (Builder $query) => $query->where('source', $source));
    }
}
