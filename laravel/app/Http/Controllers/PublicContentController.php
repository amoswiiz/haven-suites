<?php

namespace App\Http\Controllers;

use App\Services\PublicAreaGuides;
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
    public function posts(): View
    {
        $tenant = app('currentTenant');

        return view('public_listings.blog', [
            'posts' => DB::table('public_posts')->where('tenant_id', $tenant->id)->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")
                ->orderByDesc('published_at')->orderByDesc('id')->paginate(25),
            'site' => app(PublicListingCatalog::class)->settings($tenant),
        ]);
    }

    public function create(): View
    {
        return view('public_listings.post_form', ['post' => null]);
    }

    public function edit(string $tenant, int $id): View
    {
        return view('public_listings.post_form', ['post' => $this->post($id)]);
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
        ], ['slug.regex' => 'Use lowercase letters, numbers and hyphens for the web address.', 'body.min' => 'Write at least a short paragraph (50 characters).']);
        $newCover = null;
        if ($request->hasFile('cover')) {
            $newCover = 'public-posts/'.$tenant->id.'/'.Str::random(32).'.jpg';
            Storage::disk('local')->put($newCover, app(PublicImageEncoder::class)->jpeg($request->file('cover'), 'cover'));
        }
        $changes = array_intersect_key($data, array_flip(['title', 'excerpt', 'slug', 'body', 'status', 'cover_caption', 'seo_title', 'seo_description'])) + ['excerpt' => null, 'cover_caption' => null, 'seo_title' => null, 'seo_description' => null];
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
        }
    }
}
