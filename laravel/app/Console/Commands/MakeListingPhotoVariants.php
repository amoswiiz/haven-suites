<?php

namespace App\Console\Commands;

use App\Services\ListingPhotoVariants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MakeListingPhotoVariants extends Command
{
    protected $signature = 'haven:listing-photo-variants {--limit=500 : Photos to process in one run}';

    protected $description = 'Create the smaller WebP copies of listing photos that do not have them yet';

    public function handle(ListingPhotoVariants $variants): int
    {
        if (! ListingPhotoVariants::supported()) {
            $this->warn('The PHP GD extension with WebP support is required.');

            return self::FAILURE;
        }
        $created = 0;
        $processed = 0;
        $limit = max(1, (int) $this->option('limit'));
        DB::table('public_listing_photos')->orderBy('id')->select(['id', 'storage_key'])
            ->chunkById(200, function ($photos) use ($variants, &$created, &$processed, $limit): bool {
                foreach ($photos as $photo) {
                    $written = $variants->make($photo->storage_key);
                    $created += $written;
                    if ($written > 0 && ++$processed >= $limit) {
                        return false;
                    }
                }

                return true;
            });
        $this->info('Created '.$created.' smaller copies for '.$processed.' photos.');

        return self::SUCCESS;
    }
}
