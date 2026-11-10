<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PublicListingCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

#[Signature('haven:import-public-listings {tenant : Company slug} {file : UTF-8 CSV file} {--actor= : Active company administrator user ID} {--dry-run : Validate without saving}')]
#[Description('Import mapped website content as private listing drafts without publishing or downloading photos')]
class ImportPublicListings extends Command
{
    public function handle(PublicListingCatalog $catalog): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->where('active', true)->first();
        if (! $tenant || ! $this->authorizedActor($tenant, false)) {
            $this->error('Choose an active company and provide --actor with an active administrator user ID belonging to it and allowed to manage properties.');

            return self::FAILURE;
        }
        try {
            $rows = $this->readCsv((string) $this->argument('file'));
            $units = $catalog->units($tenant)->where('status', 'active')->keyBy('id');
            $existing = DB::table('public_listings')->where('tenant_id', $tenant->id)->get(['unit_id', 'slug']);
            $existingUnits = array_fill_keys($existing->pluck('unit_id')->all(), true);
            $existingSlugs = array_fill_keys($existing->pluck('slug')->all(), true);
            $seenUnits = [];
            $seenSlugs = [];
            $errors = [];
            $validated = [];
            foreach ($rows as $index => $row) {
                $number = $index + 2;
                $row['amenities'] = array_values(array_filter(array_map('trim', explode(';', $row['amenities'] ?? ''))));
                $validation = Validator::make($row, [
                    'unit_id' => 'required|integer|min:1',
                    'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::notIn(PublicListingCatalog::RESERVED_SLUGS)],
                    'title' => 'required|string|min:3|max:180', 'description' => 'required|string|min:30|max:15000',
                    'city' => 'required|string|max:100', 'neighbourhood' => 'nullable|string|max:150',
                    'max_guests' => 'required|integer|min:1|max:100', 'min_nights' => 'required|integer|min:1|max:365',
                    'starting_price' => ['nullable', 'numeric', 'min:0.01', 'max:9999999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
                    'amenities' => 'array|max:30', 'amenities.*' => ['string', Rule::in(array_keys(PublicListingCatalog::AMENITIES))],
                    'house_rules' => 'nullable|string|max:5000', 'legacy_url' => ['nullable', 'url:http,https', 'max:2048'],
                ]);
                if ($validation->fails()) {
                    foreach ($validation->errors()->all() as $message) {
                        $errors[] = 'CSV row '.$number.': '.$message;
                    }

                    continue;
                }
                $data = $validation->validated();
                $unitId = (int) $data['unit_id'];
                if (! $units->has($unitId)) {
                    $errors[] = 'CSV row '.$number.': unit_id must refer to an active apartment in this company.';
                }
                if (isset($seenUnits[$unitId]) || isset($existingUnits[$unitId])) {
                    $errors[] = 'CSV row '.$number.': the apartment is repeated or already has a listing. Existing listings are never overwritten.';
                }
                if (isset($seenSlugs[$data['slug']]) || isset($existingSlugs[$data['slug']])) {
                    $errors[] = 'CSV row '.$number.': the page address is repeated or already exists in this company.';
                }
                $seenUnits[$unitId] = true;
                $seenSlugs[$data['slug']] = true;
                $validated[] = $data;
            }
            if ($errors !== []) {
                foreach (array_slice($errors, 0, 50) as $error) {
                    $this->error($error);
                }
                if (count($errors) > 50) {
                    $this->error('Additional validation errors: '.(count($errors) - 50).'.');
                }
                $this->error('Validation failed. No listings were saved.');

                return self::FAILURE;
            }
            $this->info('Validated '.count($validated).' listing rows.');
            if ($this->option('dry-run')) {
                $this->info('Dry run complete. No listings, photos or public site settings were changed.');

                return self::SUCCESS;
            }
            DB::transaction(function () use ($tenant, $validated): void {
                if (! $this->authorizedActor($tenant, true) || ! Tenant::whereKey($tenant->id)->where('active', true)->lockForUpdate()->first()) {
                    throw new \InvalidArgumentException('The company or administrator changed. Revalidate before importing.');
                }
                foreach ($validated as $data) {
                    $changes = array_intersect_key($data, array_flip(['unit_id', 'slug', 'title', 'description', 'city', 'neighbourhood', 'max_guests', 'min_nights', 'starting_price', 'house_rules']));
                    $changes += ['neighbourhood' => null, 'starting_price' => null, 'house_rules' => null];
                    $changes['amenities'] = json_encode(array_values(array_unique($data['amenities'])), JSON_THROW_ON_ERROR);
                    $id = DB::table('public_listings')->insertGetId($changes + [
                        'tenant_id' => $tenant->id, 'status' => 'draft', 'featured' => false, 'version' => 1,
                        'check_in_time' => '14:00', 'check_out_time' => '10:00', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('public_listing_audit_events')->insert([
                        'tenant_id' => $tenant->id, 'listing_id' => $id, 'actor_id' => (int) $this->option('actor'),
                        'action' => 'listing_imported', 'before_snapshot' => null,
                        'after_snapshot' => json_encode(['listing' => (array) DB::table('public_listings')->find($id), 'photos' => []], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                }
            });
            $this->info('Imported '.count($validated).' private drafts. Upload apartment photos and review each draft before publishing.');
            foreach ($validated as $data) {
                if (! empty($data['legacy_url'])) {
                    $legacy = parse_url($data['legacy_url']);
                    $this->line('Redirect candidate: '.($legacy['path'] ?? '/').' -> '.route('public-stays.show', ['company' => $tenant->slug, 'slug' => $data['slug']]));
                }
            }

            return self::SUCCESS;
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            Log::warning('Public listing import failed without publishing.', ['tenant_id' => $tenant->id, 'exception_type' => $exception::class]);
            $this->error('Import could not be completed. No imported drafts were saved. Revalidate the file and check the application logs.');

            return self::FAILURE;
        }
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

    private function readCsv(string $file): array
    {
        $path = realpath($file);
        if ($path === false || ! is_file($path) || ! is_readable($path) || filesize($path) > 4 * 1024 * 1024) {
            throw new \InvalidArgumentException('Choose a readable UTF-8 CSV file no larger than 4 MB.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('The CSV file could not be opened.');
        }
        try {
            $headers = fgetcsv($handle, 0, ',', '"', '');
            if (! is_array($headers)) {
                throw new \InvalidArgumentException('The CSV file needs a header row and at least one listing.');
            }
            $headers = array_map(static fn ($value): string => strtolower(trim((string) $value)), $headers);
            $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            $required = ['unit_id', 'slug', 'title', 'description', 'city', 'max_guests', 'min_nights'];
            if (array_diff($required, $headers) !== [] || count($headers) !== count(array_unique($headers))) {
                throw new \InvalidArgumentException('CSV headers must be unique and include: '.implode(', ', $required).'.');
            }
            $rows = [];
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if (count($values) === 1 && trim((string) $values[0]) === '') {
                    continue;
                }
                if (count($values) !== count($headers) || ! mb_check_encoding(implode('', $values), 'UTF-8')) {
                    throw new \InvalidArgumentException('CSV row '.(count($rows) + 2).' has mismatched columns or invalid UTF-8 text.');
                }
                $rows[] = array_combine($headers, array_map(static fn ($value): ?string => trim((string) $value) === '' ? null : trim((string) $value), $values));
                if (count($rows) > 2000) {
                    throw new \InvalidArgumentException('Import at most 2,000 listings at a time.');
                }
            }
            if ($rows === []) {
                throw new \InvalidArgumentException('The CSV file contains no listing rows.');
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
