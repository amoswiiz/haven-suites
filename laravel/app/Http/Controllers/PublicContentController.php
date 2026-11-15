<?php

namespace App\Http\Controllers;

use App\Services\BlogSeoCheck;
use App\Services\ListingPhotoVariants;
use App\Services\PublicAreaGuides;
use App\Services\PublicBlog;
use App\Services\PublicImageEncoder;
use App\Services\PublicListingCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Staff write blog posts and neighbourhood introductions for the public website. */
class PublicContentController extends Controller
{
    public function posts(BlogSeoCheck $seo): View
    {
        $tenant = app('currentTenant');
        $posts = DB::table('public_posts')->where('tenant_id', $tenant->id)->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")
            ->orderByDesc('published_at')->orderByDesc('id')->paginate(25);
        $inbound = $seo->inboundLinks($tenant);
        $checks = $posts->getCollection()->mapWithKeys(fn (object $post): array => [$post->id => $seo->analyse($tenant, $this->seoInput($post), $inbound)]);

        return view('public_listings.blog', [
            'posts' => $posts, 'checks' => $checks, 'site' => app(PublicListingCatalog::class)->settings($tenant),
            'orphans' => DB::table('public_posts')->where('tenant_id', $tenant->id)->where('status', 'published')->get(['slug'])
                ->filter(fn (object $post): bool => empty($inbound[$post->slug]))->count(),
        ]);
    }

    public function create(BlogSeoCheck $seo): View
    {
        return view('public_listings.post_form', ['post' => null, 'seo' => $seo->analyse(app('currentTenant'), [])]);
    }

    public function edit(string $tenant, int $id, BlogSeoCheck $seo): View
    {
        $post = $this->post($id);

        return view('public_listings.post_form', ['post' => $post, 'seo' => $seo->analyse(app('currentTenant'), $this->seoInput($post))]);
    }

    /** Live search checklist for the editor, using the text as typed (nothing is saved). */
    public function check(Request $request, BlogSeoCheck $seo): JsonResponse
    {
        $data = $request->validate([
            'id' => 'nullable|integer', 'title' => 'nullable|string|max:180', 'slug' => 'nullable|string|max:160', 'body' => 'nullable|string|max:60000',
            'excerpt' => 'nullable|string|max:320', 'seo_title' => 'nullable|string|max:180', 'seo_description' => 'nullable|string|max:320',
            'focus_keyword' => 'nullable|string|max:100', 'has_cover' => 'sometimes|boolean',
        ]);
        $result = $seo->analyse(app('currentTenant'), ['has_cover' => $request->boolean('has_cover')] + $data);

        return response()->json(['score' => $result['score'], 'html' => view('public_listings._seo_panel', ['seo' => $result])->render()]);
    }

    /** @return array<string, mixed> */
    private function seoInput(object $post): array
    {
        return ['id' => $post->id, 'title' => $post->title, 'slug' => $post->slug, 'body' => $post->body, 'excerpt' => $post->excerpt,
            'seo_title' => $post->seo_title, 'seo_description' => $post->seo_description, 'focus_keyword' => $post->focus_keyword ?? null,
            'has_cover' => ! empty($post->cover_path)];
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, string $tenant, int $id): RedirectResponse
    {
        return $this->save($request, $id);
    }

    public function destroy(string $tenant, int $id): RedirectResponse
    {
        $post = $this->post($id);
        DB::table('public_posts')->where('id', $post->id)->delete();
        $this->deleteCover($post->cover_path);

        return redirect()->route('public-listings.blog', $tenant)->with('success', 'Post deleted.');
    }

    /** Image dropped or pasted into the blog editor; returns its public address for the post body. */
    public function uploadImage(Request $request): JsonResponse
    {
        $tenant = app('currentTenant');
        $request->validate(['image' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120']);
        $name = Str::random(32);
        Storage::disk('local')->put('public-posts/'.$tenant->id.'/inline/'.$name.'.jpg', app(PublicImageEncoder::class)->jpeg($request->file('image'), 'image', 1600));
        app(ListingPhotoVariants::class)->make('public-posts/'.$tenant->id.'/inline/'.$name.'.jpg');

        return response()->json(['url' => route('public-stays.blog.image', [$tenant->slug, $name])]);
    }

    public function areas(PublicListingCatalog $catalog, PublicAreaGuides $guides): View
    {
        $tenant = app('currentTenant');

        return view('public_listings.areas', ['destinations' => $catalog->destinations($tenant), 'guides' => $guides->all($tenant), 'areaGuides' => $guides]);
    }

    public function saveArea(Request $request, PublicListingCatalog $catalog, PublicAreaGuides $guides): RedirectResponse
    {
        $tenant = app('currentTenant');
        $data = $request->validate(['city' => 'required|string|max:100', 'area' => 'nullable|string|max:150',
            'intro' => 'nullable|string|max:4000', 'highlights' => 'nullable|string|max:2000']);
        $known = collect($catalog->destinations($tenant))->contains(fn (array $destination): bool => $destination['city'] === $data['city']
            && (empty($data['area']) || collect($destination['areas'])->contains('name', $data['area'])));
        if (! $known) {
            throw ValidationException::withMessages(['city' => 'Choose a city or area that has published stays.']);
        }
        $guides->save($tenant, $data['city'], ($data['area'] ?? '') ?: null, $data['intro'] ?? null, $data['highlights'] ?? null);

        return redirect()->route('public-listings.areas', $tenant->slug)->with('success', 'Area page saved for '.(($data['area'] ?? '') ?: $data['city']).'.');
    }

    private function save(Request $request, ?int $id = null): RedirectResponse
    {
        $tenant = app('currentTenant');
        $before = $id ? $this->post($id) : null;
        if (! $request->filled('slug') && is_string($request->input('title'))) {
            $request->merge(['slug' => Str::slug($request->input('title'))]);
        }
        $data = $request->validate([
            'version' => $id ? 'required|integer|min:1' : 'nullable',
            'title' => 'required|string|min:5|max:180', 'excerpt' => 'nullable|string|max:320',
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('public_posts')->where('tenant_id', $tenant->id)->ignore($id)],
            'body' => 'required|string|min:50|max:60000', 'status' => 'required|in:draft,published',
            'published_at' => 'nullable|date', 'cover' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cover_caption' => 'nullable|string|max:180', 'remove_cover' => 'sometimes|boolean',
            'seo_title' => 'nullable|string|max:180|not_regex:/[<>]/', 'seo_description' => 'nullable|string|max:320|not_regex:/[<>]/',
            'focus_keyword' => 'nullable|string|max:100|not_regex:/[<>]/',
            'audience' => ['nullable', Rule::in(array_keys(PublicBlog::AUDIENCES))],
        ], ['slug.regex' => 'Use lowercase letters, numbers and hyphens for the web address.', 'body.min' => 'Write at least a short paragraph (50 characters).']);
        $newCover = null;
        if ($request->hasFile('cover')) {
            $newCover = 'public-posts/'.$tenant->id.'/'.Str::random(32).'.jpg';
            Storage::disk('local')->put($newCover, app(PublicImageEncoder::class)->jpeg($request->file('cover'), 'cover'));
        }
        $changes = array_intersect_key($data, array_flip(['title', 'excerpt', 'slug', 'body', 'status', 'cover_caption', 'seo_title', 'seo_description', 'focus_keyword']))
            + ['excerpt' => null, 'cover_caption' => null, 'seo_title' => null, 'seo_description' => null, 'focus_keyword' => null];
        $changes['audience'] = $data['audience'] ?? $before?->audience ?? PublicBlog::guessAudience($data['title'], $data['body']);
        $changes['published_at'] = $data['status'] === 'published' ? ($data['published_at'] ?? $before?->published_at ?? now()) : ($data['published_at'] ?? null);
        $oldCover = null;
        if ($newCover !== null) {
            $changes['cover_path'] = $newCover;
            $oldCover = $before?->cover_path;
        } elseif ($request->boolean('remove_cover')) {
            $changes['cover_path'] = null;
            $oldCover = $before?->cover_path;
        }
        try {
            $postId = DB::transaction(function () use ($tenant, $before, $data, $changes): int {
                if ($before) {
                    $latest = DB::table('public_posts')->where('id', $before->id)->lockForUpdate()->first();
                    if ((int) $latest->version !== (int) $data['version']) {
                        throw ValidationException::withMessages(['post' => 'This post changed. Reload the page before saving.']);
                    }
                    DB::table('public_posts')->where('id', $before->id)->update($changes + ['version' => (int) $latest->version + 1, 'updated_at' => now()]);

                    return (int) $before->id;
                }

                return DB::table('public_posts')->insertGetId($changes + ['tenant_id' => $tenant->id, 'author_id' => auth()->id(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            });
        } catch (\Throwable $exception) {
            $this->deleteCover($newCover);
            throw $exception;
        }
        $this->deleteCover($oldCover);
        if ($newCover !== null) {
            app(ListingPhotoVariants::class)->make($newCover);
        }

        return redirect()->route('public-listings.blog.edit', [$tenant->slug, $postId])
            ->with('success', $data['status'] === 'published' ? 'Post saved and published.' : 'Draft saved. It is not visible on the website yet.');
    }

    private function post(int $id): object
    {
        return DB::table('public_posts')->where('tenant_id', app('currentTenant')->id)->where('id', $id)->firstOrFail();
    }

    private function deleteCover(?string $path): void
    {
        if ($path && str_starts_with($path, 'public-posts/'.app('currentTenant')->id.'/')) {
            Storage::disk('local')->delete($path);
            app(ListingPhotoVariants::class)->delete($path);
        }
    }
}
