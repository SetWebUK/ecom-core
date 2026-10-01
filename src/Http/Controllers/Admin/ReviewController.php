<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Catalogue\ReviewBulkRequest;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductReview;
use Pine\Commerce\Services\Admin\CatalogueTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Product review moderation. Approving, un-approving or deleting a review recalculates the product's
 * average_rating / review_count (only approved reviews count and show in the shop).
 *
 *   GET    admin/reviews                     admin.reviews.index        ?status=pending|approved|all, ?rating=, ?q=
 *   POST   admin/reviews/{review}/approve    admin.reviews.approve
 *   POST   admin/reviews/{review}/unapprove  admin.reviews.unapprove
 *   PUT    admin/reviews/{review}/reply      admin.reviews.reply        public reply from the shop (optional)
 *   DELETE admin/reviews/{review}            admin.reviews.destroy
 *   POST   admin/reviews/bulk                admin.reviews.bulk         approve | unapprove | delete
 */
class ReviewController extends Controller
{
    use AdminIndex;

    public const TABS = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'all' => 'All'];

    public function index(Request $request): View
    {
        $counts = DB::table('product_reviews')->selectRaw('COUNT(*) as c_all, SUM(is_approved = 0) as c_pending, SUM(is_approved = 1) as c_approved')->first();
        $pending = (int) ($counts->c_pending ?? 0);
        $status = $this->filterValue($request, 'status', self::TABS) ?? ($pending > 0 ? 'pending' : 'all');
        $rating = $this->filterValue($request, 'rating', array_combine(range(1, 5), range(1, 5)));
        $q = $this->searchTerm($request);
        [$sort, $direction] = $this->sorting($request, ['created_at', 'rating'], 'created_at', 'desc');

        $reviews = ProductReview::query()
            ->with(['product' => fn ($p) => $p->withTrashed()->select('id', 'name', 'slug', 'primary_category_id', 'status', 'average_rating', 'review_count', 'deleted_at')
                ->with(['images' => fn ($i) => $i->select('id', 'product_id', 'path', 'sort_order')->orderBy('sort_order')->limit(1), 'primaryCategory:id,path', 'categories:id,path'])])
            ->when($status === 'pending', fn (Builder $b) => $b->where('is_approved', false))
            ->when($status === 'approved', fn (Builder $b) => $b->where('is_approved', true))
            ->when($rating, fn (Builder $b) => $b->where('rating', (int) $rating))
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w
                ->where('name', 'like', $this->like($q))
                ->orWhere('email', 'like', $this->like($q))
                ->orWhere('content', 'like', $this->like($q))
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $this->like($q)))))
            ->orderBy($sort, $direction)->orderByDesc('id')
            ->paginate($this->perPage($request))->withQueryString();

        $tabs = [
            'pending' => ['label' => 'Waiting for approval', 'count' => $pending],
            'approved' => ['label' => 'Approved', 'count' => (int) ($counts->c_approved ?? 0)],
            'all' => ['label' => 'All', 'count' => (int) ($counts->c_all ?? 0)],
        ];

        return view('commerce::admin.reviews.index', [
            'reviews' => $reviews,
            'tabs' => $tabs,
            'status' => $status,
            'q' => $q,
            'chips' => array_filter(['rating' => $rating ? $rating.' '.Str::plural('star', (int) $rating) : null]),
            'hasReply' => Schema::hasColumn('product_reviews', 'reply'),
        ]);
    }

    public function approve(ProductReview $review): RedirectResponse
    {
        $review->forceFill(['is_approved' => true])->save();
        CatalogueTools::refreshReviewStats($review->product);

        return back()->with('success', "Review by {$review->name} approved – it now shows on the product page.");
    }

    public function unapprove(ProductReview $review): RedirectResponse
    {
        $review->forceFill(['is_approved' => false])->save();
        CatalogueTools::refreshReviewStats($review->product);

        return back()->with('success', "Review by {$review->name} hidden from the shop.");
    }

    public function reply(Request $request, ProductReview $review): RedirectResponse
    {
        abort_unless(Schema::hasColumn('product_reviews', 'reply'), 404);
        $validated = $request->validateWithBag('reply'.$review->id, [
            'reply' => ['nullable', 'string', 'max:5000'],
        ]);
        $text = trim((string) ($validated['reply'] ?? ''));
        $review->forceFill(['reply' => $text !== '' ? $text : null, 'replied_at' => $text !== '' ? now() : null])->save();

        return back()->with('success', $text !== '' ? 'Reply saved.' : 'Reply removed.');
    }

    public function destroy(ProductReview $review): RedirectResponse
    {
        $product = $review->product;
        $review->delete();
        CatalogueTools::refreshReviewStats($product);

        return back()->with('success', 'Review deleted.');
    }

    public function bulk(ReviewBulkRequest $bulk): RedirectResponse
    {
        $ids = $bulk->ids();
        $query = ProductReview::query()->whereIn('id', $ids);
        $productIds = (clone $query)->distinct()->pluck('product_id')->all();

        $count = match ($bulk->input('action')) {
            'approve' => $query->update(['is_approved' => true, 'updated_at' => now()]),
            'unapprove' => $query->update(['is_approved' => false, 'updated_at' => now()]),
            'delete' => $query->delete(),
        };
        foreach (Product::withTrashed()->whereIn('id', $productIds)->get() as $product) {
            CatalogueTools::refreshReviewStats($product);
        }

        $verb = ['approve' => 'approved', 'unapprove' => 'hidden', 'delete' => 'deleted'][$bulk->input('action')];

        return back()->with('success', "{$count} ".Str::plural('review', $count)." {$verb}.");
    }
}

