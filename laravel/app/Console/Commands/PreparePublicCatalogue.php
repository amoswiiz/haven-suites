<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PublicListingCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[Signature('haven:prepare-public-catalogue {tenant : Company slug} {--actor= : Active company administrator user ID} {--dry-run : Count missing drafts without saving}')]
#[Description('Prepare private listing drafts for existing active managed and partner apartments')]
class PreparePublicCatalogue extends Command
{
    public function handle(PublicListingCatalog $catalog): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->where('active', true)->first();
        if (! $tenant || ! $this->authorizedActor($tenant, false)) {
            $this->error('Choose an active company and provide --actor with an active administrator user ID belonging to it and allowed to manage properties.');

            return self::FAILURE;
        }
        try {
            if ($this->option('dry-run')) {
                $missing = $this->missing($catalog, $tenant);
                $this->info('Dry run: '.$missing->count().' missing private drafts ('.$missing->where('kind', 'managed')->count().' managed, '.$missing->where('kind', 'partner')->count().' partner). Nothing was changed.');

                return self::SUCCESS;
            }
            $counts = DB::transaction(function () use ($catalog, $tenant): array {
                $currentTenant = Tenant::whereKey($tenant->id)->where('active', true)->lockForUpdate()->first();
                if (! $currentTenant || ! $this->authorizedActor($currentTenant, true)) {
                    throw new \InvalidArgumentException('The company or administrator changed. Revalidate before preparing drafts.');
                }
                $missing = $this->missing($catalog, $currentTenant);
                $slugs = array_fill_keys(DB::table('public_listings')->where('tenant_id', $tenant->id)->pluck('slug')->all(), true);
                foreach ($missing as $apartment) {
                    $title = trim(implode(' / ', array_filter([$apartment->property_name, $apartment->unit_number], fn ($part): bool => $part !== null && trim($part) !== '')));
                    $title = mb_substr($title !== '' ? $title : ($apartment->kind === 'managed' ? 'Managed apartment' : 'Partner apartment'), 0, 180);
                    $suffix = '-'.($apartment->kind === 'managed' ? 'unit' : 'partner').'-'.$apartment->id;
                    $base = rtrim(substr(Str::slug($title) ?: 'apartment', 0, 140 - strlen($suffix)), '-').$suffix;
                    $slug = $base;
                    for ($number = 2; isset($slugs[$slug]); $number++) {
                        $slug = $base.'-'.$number;
                    }
                    $slugs[$slug] = true;
                    $id = DB::table('public_listings')->insertGetId([
                        'tenant_id' => $tenant->id, 'unit_id' => $apartment->unit_id, 'partner_apartment_id' => $apartment->partner_apartment_id,
                        'slug' => $slug, 'title' => $title, 'description' => '', 'city' => '',
                        'max_guests' => null, 'min_nights' => null, 'starting_price' => null,
                        'check_in_time' => '14:00', 'check_out_time' => '10:00', 'amenities' => json_encode([]),
                        'status' => 'draft', 'featured' => false, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('public_listing_audit_events')->insert([
                        'tenant_id' => $tenant->id, 'listing_id' => $id, 'actor_id' => (int) $this->option('actor'),
                        'action' => 'listing_prepared', 'before_snapshot' => null,
                        'after_snapshot' => json_encode(['listing' => (array) DB::table('public_listings')->find($id), 'photos' => []], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                }

                return ['total' => $missing->count(), 'managed' => $missing->where('kind', 'managed')->count(), 'partner' => $missing->where('kind', 'partner')->count()];
            });
            $this->info('Prepared '.$counts['total'].' private drafts ('.$counts['managed'].' managed, '.$counts['partner'].' partner). Existing listings were unchanged.');
            $this->line('Review each description, destination, guest capacity, minimum stay and photos before publishing. The public site was not enabled.');

            return self::SUCCESS;
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            Log::warning('Public catalogue draft preparation failed.', ['tenant_id' => $tenant->id, 'exception_type' => $exception::class]);
            $this->error('Draft preparation could not be completed. No drafts from this request were saved. Check the application logs.');

            return self::FAILURE;
        }
    }

    private function missing(PublicListingCatalog $catalog, Tenant $tenant): Collection
    {
        $existing = DB::table('public_listings')->where('tenant_id', $tenant->id)->get(['unit_id', 'partner_apartment_id']);
        $references = [];
        foreach ($existing as $listing) {
            if ($listing->unit_id !== null) {
                $references['unit:'.$listing->unit_id] = true;
            }
            if ($listing->partner_apartment_id !== null) {
                $references['partner:'.$listing->partner_apartment_id] = true;
            }
        }

        return $catalog->apartments($tenant)->where('status', 'active')->reject(fn (object $apartment): bool => isset($references[$apartment->reference]))->values();
    }

    private function authorizedActor(Tenant $tenant, bool $lock): bool
    {
        if (($tenant->modules['properties'] ?? true) === false || ! ctype_digit((string) $this->option('actor'))) {
            return false;
        }
        $user = User::whereKey((int) $this->option('actor'))->where('active', true);
        $membership = DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', (int) $this->option('actor'));
        if ($lock) {
            $user->lockForUpdate();
            $membership->lockForUpdate();
        }
        if (! $user->first() || ! ($pivot = $membership->first())) {
            return false;
        }
        foreach (json_decode($pivot->roles, true) ?? [] as $role) {
            foreach (config('haven.roles.'.$role, []) as $permission) {
                if (Str::is($permission, 'properties.manage')) {
                    return true;
                }
            }
        }

        return false;
    }
}
