<?php

namespace App\Services;

use App\Models\Tenant;
use App\Support\PhoneNumber;
use Illuminate\Validation\ValidationException;

class PublicWebsiteLinks
{
    public const DEFAULT_NAVIGATION = [
        ['label' => 'Home', 'target' => 'home'],
        ['label' => 'Find a stay', 'target' => 'stays'],
        ['label' => 'Contact', 'target' => 'contact'],
    ];

    /** @return array{public_url: string, enquiry_url: string, whatsapp_url: ?string} */
    public function forListing(Tenant $tenant, array $listing, ?object $site = null): array
    {
        if (isset($listing['tenant_id']) && (int) $listing['tenant_id'] !== (int) $tenant->id) {
            throw new \InvalidArgumentException('Listing and company must match.');
        }
        $site ??= app(PublicListingCatalog::class)->settings($tenant);
        if ($site && (int) ($site->tenant_id ?? 0) !== (int) $tenant->id) {
            $site = null;
        }
        $publicUrl = $this->canonicalUrl('public-stays.show', ['company' => $tenant->slug, 'slug' => $listing['slug']]);
        $number = $this->text($listing['whatsapp_number'] ?? null) ?: $this->text($site->whatsapp_number ?? null);
        $template = $this->text($listing['whatsapp_message'] ?? null) ?: $this->text($site->whatsapp_message ?? null);

        return ['public_url' => $publicUrl, 'enquiry_url' => $publicUrl.'#enquire',
            'whatsapp_url' => $this->whatsappUrl($number, $template, $this->text($listing['title'] ?? null), $publicUrl, $listing['slug'])];
    }

    /** @return array{public_url: string, enquiry_url: string, whatsapp_url: ?string} */
    public function forSite(Tenant $tenant, ?object $site = null): array
    {
        $site ??= app(PublicListingCatalog::class)->settings($tenant);
        if ($site && (int) ($site->tenant_id ?? 0) !== (int) $tenant->id) {
            $site = null;
        }
        $publicUrl = $this->canonicalUrl('public-stays.index', ['company' => $tenant->slug]);

        return ['public_url' => $publicUrl, 'enquiry_url' => $publicUrl.'#find-stay',
            'whatsapp_url' => $this->whatsappUrl($this->text($site->whatsapp_number ?? null), $this->text($site->whatsapp_message ?? null), $tenant->name, $publicUrl, 'website')];
    }

    /** @return list<array{label: string, url: string}> */
    public function menu(Tenant $tenant, ?object $site = null): array
    {
        $site ??= app(PublicListingCatalog::class)->settings($tenant);
        if ($site && (int) ($site->tenant_id ?? 0) !== (int) $tenant->id) {
            $site = null;
        }
        $base = $this->canonicalUrl('public-stays.index', ['company' => $tenant->slug]);

        return array_map(static fn (array $link): array => [
            'label' => $link['label'],
            'url' => $base.match ($link['target']) {
                'home' => '', 'stays' => '#find-stay', 'contact' => '#stay-contact'
            },
        ], $this->navigationForForm($site));
    }

    /** @return list<array{label: string, target: string}> */
    public function normalizeNavigation(array $input): array
    {
        if (count($input) > 6) {
            throw ValidationException::withMessages(['navigation_links' => 'Keep at most six menu links.']);
        }
        $links = [];
        $seen = [];
        foreach ($input as $index => $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['label', 'target']) !== []
                || (($item['label'] ?? null) !== null && ! is_string($item['label']))
                || (($item['target'] ?? null) !== null && ! is_string($item['target']))) {
                throw ValidationException::withMessages(['navigation_links.'.$index => 'Choose a menu label and a supported destination.']);
            }
            $label = $this->text($item['label'] ?? null);
            $target = $this->text($item['target'] ?? null);
            if ($label === '' && $target === '') {
                continue;
            }
            if ($label === '' || ! mb_check_encoding($label, 'UTF-8') || mb_strlen($label) > 60 || preg_match('/[<>\x00-\x1f\x7f]/u', $label)) {
                throw ValidationException::withMessages(['navigation_links.'.$index.'.label' => 'Use a plain text menu label of up to 60 characters.']);
            }
            if (! in_array($target, ['home', 'stays', 'contact'], true) || isset($seen[$target])) {
                throw ValidationException::withMessages(['navigation_links.'.$index.'.target' => 'Choose Home, Find a stay or Contact once each.']);
            }
            $seen[$target] = true;
            $links[] = ['label' => $label, 'target' => $target];
        }

        return $links;
    }

    /** @return list<array{label: string, target: string}> */
    public function navigationForForm(?object $site): array
    {
        $stored = $site->navigation_links ?? null;
        if ($stored === null) {
            return self::DEFAULT_NAVIGATION;
        }
        if (is_string($stored)) {
            try {
                $stored = json_decode($stored, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return self::DEFAULT_NAVIGATION;
            }
        }
        if (! is_array($stored) || ! array_is_list($stored)) {
            return self::DEFAULT_NAVIGATION;
        }
        try {
            return $this->normalizeNavigation($stored);
        } catch (ValidationException) {
            return self::DEFAULT_NAVIGATION;
        }
    }

    private function canonicalUrl(string $routeName, array $parameters): string
    {
        return rtrim(config('app.url'), '/').route($routeName, $parameters, false);
    }

    private function whatsappUrl(string $number, string $template, string $title, string $url, string $reference): ?string
    {
        $number = PhoneNumber::normalize($number) ?? '';
        if ($number === '') {
            return null;
        }
        if ($template === '' || mb_strlen($template) > 1000) {
            $template = 'Hello! I am interested in {listing_title}: {listing_url}';
        }
        $message = strtr($template, ['{listing_title}' => $title, '{listing_url}' => $url, '{listing_reference}' => $reference]);
        if (! str_contains($template, '{listing_title}')) {
            $message .= "\n".$title;
        }
        if (! str_contains($template, '{listing_url}')) {
            $message .= "\n".$url;
        }

        return 'https://wa.me/'.substr($number, 1).'?text='.rawurlencode($message);
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
