<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * Guest-facing policies, frequently asked questions and the host introduction for the public website. Each value is
 * the company's own text from Website settings, or default wording that explains the process without inventing
 * commitments such as refund percentages.
 */
class PublicGuestInfo
{
    public const DEFAULT_LANGUAGES = 'English, Swahili';

    public function defaultCancellation(): string
    {
        return 'Cancellation terms are shown on your booking quote before you pay, so you know exactly what applies to your dates. If your plans change, contact our team as early as possible and we will do our best to help, including moving your stay to new dates where the apartment is available.';
    }

    public function defaultPayment(): string
    {
        return 'Your booking is confirmed once the agreed deposit is received. You can pay by M-Pesa or bank transfer using the payment details on your official quote. Only pay to the account named on your quote, and contact us if anything looks different.';
    }

    public function defaultHostIntro(Tenant $tenant): string
    {
        return 'A real '.$tenant->name.' team member handles your enquiry from the first message to check-out. We know each apartment and its neighbourhood, and we stay reachable during your stay if you need anything.';
    }

    public function defaultReplyTime(?object $site): string
    {
        $hours = max(1, (int) ($site->response_hours ?? 24));

        return $hours <= 1 ? 'Usually replies within an hour' : 'Usually replies within '.$hours.' hours';
    }

    public function defaultFaq(Tenant $tenant): string
    {
        return implode("\n\n", [
            "How do I book a stay?\nChoose an apartment, pick your dates and send an enquiry, or message us on WhatsApp. We confirm availability and send you a quote. Your booking is confirmed once the deposit is paid.",
            "Is the price shown the final price?\nThe nightly price is a starting price. Your quote confirms the total for your dates, including any cleaning fee and long-stay discount.",
            "What do I need at check-in?\nPlease bring a valid national ID or passport for each adult guest. Building security may ask to record your details.",
            "Can I check in early or check out late?\nTell us your arrival and departure times. Early check-in and late check-out depend on the apartment's bookings that day, and we confirm them before your stay.",
            "Do you offer discounts for long stays?\nYes, many apartments have weekly or monthly rates. Ask us for a long-stay quote.",
            "Can you arrange airport pickup?\nWe can help arrange airport or SGR pickup at an extra cost. Share your flight or train details when you enquire.",
            "Are visitors and events allowed?\nEach apartment has its own house rules, shown on its page. Parties and events are not allowed unless "
                .$tenant->name.' agrees in writing.',
            "How do I track my enquiry?\nUse the reference in your SMS or email on the Track my enquiry page, or reply to our message.",
        ]);
    }

    /**
     * @return array{cancellation: list<string>, payment: list<string>, host_intro: string, reply_time: string, languages: string, faq: list<array{question: string, answer: string}>}
     */
    public function content(Tenant $tenant, ?object $site): array
    {
        return [
            'cancellation' => $this->paragraphs($this->text($site->cancellation_policy ?? null) ?: $this->defaultCancellation()),
            'payment' => $this->paragraphs($this->text($site->payment_policy ?? null) ?: $this->defaultPayment()),
            'host_intro' => $this->text($site->host_intro ?? null) ?: $this->defaultHostIntro($tenant),
            'reply_time' => $this->text($site->host_reply_time ?? null) ?: $this->defaultReplyTime($site),
            'languages' => $this->text($site->host_languages ?? null) ?: self::DEFAULT_LANGUAGES,
            'faq' => $this->faq($this->text($site->faq ?? null) ?: $this->defaultFaq($tenant)),
        ];
    }

    /**
     * Questions separated by a blank line: the first line is the question and the following lines are the answer.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function faq(string $text): array
    {
        $items = [];
        foreach (preg_split('/\R\s*\R/u', trim($text)) ?: [] as $block) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $block) ?: []), fn (string $line): bool => $line !== ''));
            if (count($lines) < 2) {
                continue;
            }
            $question = preg_replace('/^(?:Q\s*[:.)-]\s*)/iu', '', array_shift($lines));
            $answer = preg_replace('/^(?:A\s*[:.)-]\s*)/iu', '', implode(' ', $lines));
            $items[] = ['question' => (string) $question, 'answer' => (string) $answer];
        }

        return array_slice($items, 0, 30);
    }

    /** @return list<string> */
    private function paragraphs(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R{2,}/u', $text) ?: [])));
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
