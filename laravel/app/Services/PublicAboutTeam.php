<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Team members on the About page: name, role, a short introduction and an optional photo. Stored as JSON on the
 * website settings; photos are re-encoded JPEGs (location data removed) under public-sites/{company}/team-*.jpg.
 */
class PublicAboutTeam
{
    public const MAX_MEMBERS = 6;

    /** @return list<array{name: string, role: string, bio: string, photo: ?string}> */
    public static function saved(?object $site): array
    {
        $members = json_decode((string) ($site->about_team ?? ''), true);
        if (! is_array($members)) {
            return [];
        }

        return array_values(array_map(fn (array $member): array => [
            'name' => (string) ($member['name'] ?? ''), 'role' => (string) ($member['role'] ?? ''),
            'bio' => (string) ($member['bio'] ?? ''), 'photo' => is_string($member['photo'] ?? null) ? $member['photo'] : null,
        ], array_filter($members, 'is_array')));
    }

    /** @return list<array{name: string, role: string, bio: string, photo_url: ?string}> Members for the public page. */
    public function forPage(Tenant $tenant, ?object $site): array
    {
        return array_map(fn (array $member): array => $member + [
            'photo_url' => $member['photo'] ? route('public-stays.team-photo', [$tenant->slug, basename($member['photo'])]) : null,
        ], self::saved($site));
    }

    public static function ownsPhoto(Tenant $tenant, ?object $site, string $name): ?string
    {
        foreach (self::saved($site) as $member) {
            if ($member['photo'] && basename($member['photo']) === $name && str_starts_with($member['photo'], 'public-sites/'.$tenant->id.'/team-')) {
                return $member['photo'];
            }
        }

        return null;
    }

    public function storePhoto(Tenant $tenant, UploadedFile $file, string $field): string
    {
        $path = 'public-sites/'.$tenant->id.'/team-'.Str::random(16).'.jpg';
        Storage::disk('local')->put($path, app(PublicImageEncoder::class)->jpeg($file, $field, 600));

        return $path;
    }

    /**
     * Build the saved team from the settings form: rows without a name or marked for removal are dropped; a new photo
     * replaces the old one, otherwise the row keeps its current photo.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, UploadedFile|null>  $photos
     * @return array{json: ?string, new_files: list<string>, unused_files: list<string>}
     */
    public function fromForm(Tenant $tenant, ?object $site, array $rows, array $photos): array
    {
        $current = self::saved($site);
        $members = [];
        $newFiles = [];
        foreach (array_slice($rows, 0, self::MAX_MEMBERS, true) as $index => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' || ! empty($row['remove'])) {
                continue;
            }
            $photo = ! empty($row['remove_photo']) ? null : ($current[$index]['photo'] ?? null);
            if (($photos[$index] ?? null) instanceof UploadedFile) {
                $photo = $newFiles[] = $this->storePhoto($tenant, $photos[$index], 'team_photo.'.$index);
            }
            $members[] = ['name' => $name, 'role' => trim((string) ($row['role'] ?? '')), 'bio' => trim((string) ($row['bio'] ?? '')), 'photo' => $photo];
        }
        $kept = array_filter(array_column($members, 'photo'));
        $unused = array_values(array_filter(array_column($current, 'photo'), fn (?string $photo): bool => $photo && ! in_array($photo, $kept, true)));

        return ['json' => $members === [] ? null : json_encode($members, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'new_files' => $newFiles, 'unused_files' => $unused];
    }
}
