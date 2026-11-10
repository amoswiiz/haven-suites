<?php

namespace App\Services;

use App\Models\Tenant;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class PublicWebsiteLinks
{
    public const DEFAULT_NAVIGATION = [
        ['label' => 'Home', 'target' => 'home'],
        ['label' => 'Find a stay', 'target' => 'stays'],
        ['label' => 'About us', 'target' => 'about'],
        ['label' => 'FAQ', 'target' => 'faq'],
        ['label' => 'Contact', 'target' => 'contact'],
        ['label' => 'Track my enquiry', 'target' => 'track'],
    ];

    /** Menu destinations and their labels in the Website settings menu editor. */
    public const TARGETS = ['home' => 'Home', 'stays' => 'Find a stay', 'about' => 'About us', 'faq' => 'FAQ', 'contact' => 'Contact', 'track' => 'Track my enquiry'];

    /**
     * WhatsApp link for staff to ask an apartment owner about availability, with the apartment and any stay details.
     *
     * @param  array{name: ?string, whatsapp: ?string}|null  $owner
     * @param  array{check_in?: string, check_out?: string, guests?: int|string}  $stay
     */
    public function ownerWhatsappUrl(?array $owner, Tenant $tenant, string $apartment, array $stay = [], ?string $question = null, ?string $link = null): ?string
    {
        if (empty($owner['whatsapp'])) {
            return null;
        }

        return 'https://wa.me/'.$owner['whatsapp'].'?text='.rawurlencode(implode("\n", [
            $this->ownerGreeting($owner, $tenant, $apartment, $question), ...self::ownerRequestLines($stay, $link),
        ]));
    }

    /** First line of an availability request to an owner or agent. */
    public function ownerGreeting(?array $owner, Tenant $tenant, string $apartment, ?string $question = null): string
    {
        $firstName = trim(explode(' ', trim((string) ($owner['name'] ?? '')))[0] ?? '');

        return 'Hello'.($firstName !== '' ? ' '.$firstName : '').', this is '.$tenant->name.'. '.($question ?? 'Is '.$apartment.' available?');
    }

    /**
     * Dates (or "to be confirmed"), guests and listing link for an availability request.
     *
     * @param  array{check_in?: string, check_out?: string, guests?: int|string}  $stay
     * @return list<string>
     */
    public static function ownerRequestLines(array $stay, ?string $link = null): array
    {
        $checkIn = self::parseDate($stay['check_in'] ?? null);
        $checkOut = self::parseDate($stay['check_out'] ?? null);
        if ($checkIn && $checkOut && ! $checkOut->greaterThan($checkIn)) {
            $checkOut = null;
        }
        $nights = $checkIn && $checkOut ? (int) $checkIn->diffInDays($checkOut) : null;
        $lines = [
            'Check-in: '.($checkIn?->format('D j M Y') ?? 'to be confirmed'),
            'Check-out: '.($checkOut?->format('D j M Y') ?? 'to be confirmed'),
            'Nights: '.($nights ?? 'to be confirmed'),
        ];
        if ((int) ($stay['guests'] ?? 0) > 0) {
            $lines[] = 'Guests: '.(int) $stay['guests'];
        }
        if (filled($link)) {
            $lines[] = 'Listing: '.$link;
        }

        return $lines;
    }

    /** A strict Y-m-d date, or null for anything else (including impossible dates such as 2026-02-31). */
    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $parsed && $parsed->format('Y-m-d') === $value ? $parsed : null;
    }

    /** WhatsApp link for a guest following up on an enquiry, with the reference prefilled, or null without a valid number. */
    public function forEnquiry(Tenant $tenant, ?object $site, string $reference): ?string
    {
        $number = PhoneNumber::normalize($this->text($site->whatsapp_number ?? null));
        if ($number === null || ($site && (int) ($site->tenant_id ?? 0) !== (int) $tenant->id)) {
            return null;
        }

        return 'https://wa.me/'.substr($number, 1).'?text='.rawurlencode('Hello! I am following up on my enquiry '.$reference.' with '.$tenant->name.'.');
    }

    /**
     * Public, enquiry and WhatsApp links for a listing. The WhatsApp message includes the listing code and any chosen
     * dates and guest count; whatsapp_number and whatsapp_base let the page rebuild it as the guest changes dates.
     *
     * @param  array{check_in?: string, check_out?: string, guests?: int|string}  $stay
     * @return array{public_url: string, enquiry_url: string, whatsapp_url: ?string, whatsapp_number: ?string, whatsapp_base: ?string, listing_code: ?string}
     */
    public function forListing(Tenant $tenant, array $listing, ?object $site = null, array $stay = []): array
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
        $normalized = PhoneNumber::normalize($number);
        $base = $this->whatsappMessage($template, $this->text($listing['title'] ?? null), $publicUrl, $listing['slug']);
        $message = implode("\n", [$base, ...self::stayLines($listing['listing_code'] ?? null, $stay)]);

        return ['public_url' => $publicUrl, 'enquiry_url' => $publicUrl.'#enquire',
            'whatsapp_url' => $normalized ? 'https://wa.me/'.substr($normalized, 1).'?text='.rawurlencode($message) : null,
            'whatsapp_number' => $normalized ? substr($normalized, 1) : null, 'whatsapp_base' => $normalized ? $base : null,
            'listing_code' => $listing['listing_code'] ?? null];
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
        $track = $this->canonicalUrl('public-stays.track', ['company' => $tenant->slug]);
        $aboutUrl = $this->canonicalUrl('public-stays.about', ['company' => $tenant->slug]);
        $faqUrl = $this->canonicalUrl('public-stays.faq', ['company' => $tenant->slug]);

        return array_map(static fn (array $link): array => [
            'label' => $link['label'],
            'url' => match ($link['target']) {
                'home' => $base, 'stays' => $base.'#find-stay', 'contact' => $base.'#stay-contact', 'track' => $track, 'about' => $aboutUrl, 'faq' => $faqUrl,
            },
        ], $this->navigationForForm($site));
    }

    /** @return list<array{label: string, target: string}> */
    public function normalizeNavigation(array $input): array
    {
        if (count($input) > 8) {
            throw ValidationException::withMessages(['navigation_links' => 'Keep at most eight menu links.']);
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
            if (! array_key_exists($target, self::TARGETS) || isset($seen[$target])) {
                throw ValidationException::withMessages(['navigation_links.'.$index.'.target' => 'Choose each menu destination once: Home, Find a stay, About us, FAQ, Contact or Track my enquiry.']);
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
        $number = PhoneNumber::normalize($number);

        return $number === null ? null : 'https://wa.me/'.substr($number, 1).'?text='.rawurlencode($this->whatsappMessage($template, $title, $url, $reference));
    }

    private function whatsappMessage(string $template, string $title, string $url, string $reference): string
    {
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

        return $message;
    }

    /**
     * Lines describing the requested stay, appended to a listing's WhatsApp message.
     *
     * @param  array{check_in?: string, check_out?: string, guests?: int|string}  $stay
     * @return list<string>
     */
    public static function stayLines(?string $listingCode, array $stay): array
    {
        $lines = [];
        if (filled($listingCode)) {
            $lines[] = 'Code: '.$listingCode;
        }
        $checkIn = self::parseDate($stay['check_in'] ?? null);
        $checkOut = self::parseDate($stay['check_out'] ?? null);
        if ($checkIn && $checkOut && $checkOut->greaterThan($checkIn)) {
            $nights = (int) $checkIn->diffInDays($checkOut);
            $lines[] = 'Dates: '.$checkIn->format('D j M Y').' – '.$checkOut->format('D j M Y').' ('.$nights.' night'.($nights === 1 ? '' : 's').')';
        } elseif ($checkIn) {
            $lines[] = 'Check-in: '.$checkIn->format('D j M Y');
        }
        if ((int) ($stay['guests'] ?? 0) > 0) {
            $lines[] = 'Guests: '.(int) $stay['guests'];
        }

        return $lines;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
