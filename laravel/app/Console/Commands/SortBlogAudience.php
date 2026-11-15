<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\PublicBlog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('haven:sort-blog-audience {tenant : Company slug} {--dry-run : Show the result without saving}')]
#[Description('Mark each blog post as written for guests or for property owners, from its title and text. Staff can change any post afterwards.')]
class SortBlogAudience extends Command
{
    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->where('active', true)->first();
        if (! $tenant) {
            $this->error('Choose an active company by its slug, for example: haven');

            return self::FAILURE;
        }
        $rows = [];
        $counts = ['guests' => 0, 'owners' => 0];
        foreach (DB::table('public_posts')->where('tenant_id', $tenant->id)->orderBy('title')->get(['id', 'title', 'body', 'audience']) as $post) {
            $audience = PublicBlog::guessAudience($post->title, $post->body);
            $counts[$audience]++;
            $rows[] = [$post->title, PublicBlog::AUDIENCES[$audience], $audience !== $post->audience ? 'changed' : ''];
            if (! $this->option('dry-run') && $audience !== $post->audience) {
                DB::table('public_posts')->where('id', $post->id)->update(['audience' => $audience]);
            }
        }
        $this->table(['Post', 'Written for', ''], $rows);
        $this->info(sprintf('%s %d for guests and %d for property owners.', $this->option('dry-run') ? 'Dry run:' : 'Saved:', $counts['guests'], $counts['owners']));

        return self::SUCCESS;
    }
}
