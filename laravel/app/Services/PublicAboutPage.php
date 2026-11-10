<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * Content for the public About us page: the company's own text from Website settings, or default wording that
 * describes how the service works without inventing facts such as years in business or unit counts.
 */
class PublicAboutPage
{
    public const DEFAULT_MAIN_LOCATIONS = 'Nairobi, Mombasa';

    public function defaultIntro(Tenant $tenant): string
    {
        return $tenant->name.' offers comfortable serviced apartments and short-stay homes for holidays, business trips and longer stays.'
            ."\n\nWe look after our own managed apartments and work with trusted partner homeowners, so we can match you with a place that suits your plans, group size and budget. A real team answers your enquiry and stays reachable from booking to check-out.";
    }

    public function defaultHow(): string
    {
        return implode("\n", [
            'Tell us what you need: Browse our stays or share your dates, number of guests and preferred area. You can enquire online or on WhatsApp.',
            'We confirm the details: Our team checks availability, confirms the final price and answers your questions before you commit.',
            'Secure your stay: Pay the agreed deposit to confirm your booking. You receive a confirmation and an enquiry reference you can track.',
            'Enjoy your stay: We share check-in details before you arrive and stay reachable throughout your visit.',
        ]);
    }

    public function defaultOtherAreas(): string
    {
        return 'We also arrange stays in other parts of Kenya, including Diani, Watamu, Malindi, Kikambala, Nanyuki and Naivasha, through our trusted partner homes. Tell us where you are headed and we will help you find a place.';
    }

    /**
     * @param  list<array{city: string, count: int, areas: list<array{name: string, count: int}>}>  $destinations
     * @return array{intro: list<string>, steps: list<array{title: string, text: string}>, main_locations: list<array{name: string, count: int}>, other_areas: string, other_destinations: list<array{city: string, count: int}>}
     */
    public function content(Tenant $tenant, ?object $site, array $destinations): array
    {
        $intro = $this->text($site->about_intro ?? null) ?: $this->defaultIntro($tenant);
        $steps = [];
        foreach (preg_split('/\R/u', $this->text($site->about_how ?? null) ?: $this->defaultHow()) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$title, $text] = str_contains($line, ':') ? array_map('trim', explode(':', $line, 2)) : [$line, ''];
            $steps[] = ['title' => $title, 'text' => $text];
        }
        $mainNames = array_values(array_filter(array_map('trim', explode(',', $this->text($site->about_main_locations ?? null) ?: self::DEFAULT_MAIN_LOCATIONS))));
        $byCity = collect($destinations)->keyBy(fn (array $destination): string => mb_strtolower($destination['city']));
        $main = array_map(fn (string $name): array => ['name' => $name, 'count' => (int) ($byCity->get(mb_strtolower($name))['count'] ?? 0)], array_slice($mainNames, 0, 6));
        $mainKeys = array_map('mb_strtolower', $mainNames);

        return [
            'intro' => array_values(array_filter(array_map('trim', preg_split('/\R{2,}/u', $intro) ?: []))),
            'steps' => array_slice($steps, 0, 8),
            'main_locations' => $main,
            'other_areas' => $this->text($site->about_other_areas ?? null) ?: $this->defaultOtherAreas(),
            'other_destinations' => collect($destinations)->reject(fn (array $destination): bool => in_array(mb_strtolower($destination['city']), $mainKeys, true))
                ->map(fn (array $destination): array => ['city' => $destination['city'], 'count' => $destination['count']])->values()->all(),
        ];
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
