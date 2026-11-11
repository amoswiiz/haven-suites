<?php

namespace App\Services;

use App\Models\Tenant;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One message to many referral agents, for example "units available this weekend" or a special offer.
 *
 * Messages are queued per recipient and sent by `haven:deliver-agent-broadcasts`. Only SMS is sent today;
 * the channel is stored on every row so WhatsApp can be added once the Business API is connected.
 */
class AgentBroadcasts
{
    public const KINDS = ['availability' => 'Units available', 'offer' => 'Special offer', 'general' => 'General update'];

    public const CHANNELS = ['sms' => 'SMS'];

    public const MAX_LENGTH = 900;

    private const GSM_CHARACTERS = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€";

    public function __construct(private RoyceTalkSms $sms) {}

    public function smsAvailable(Tenant $tenant): bool
    {
        return ($tenant->modules['messaging'] ?? true) !== false && (bool) config('services.roycetalk.manual_live') && $this->sms->configured();
    }

    /** Starting text for each kind of broadcast; staff edit it before sending. @return array<string, string> */
    public function templates(Tenant $tenant): array
    {
        $name = $tenant->name;

        return [
            'availability' => "Hi {name}, {$name} has units available:\n{listings}\nSend us your guests' dates to book. Thank you!",
            'offer' => "Hi {name}, special offer from {$name}: [describe the offer, e.g. 15% off 3+ nights] until [end date].\n{listings}\nCall or WhatsApp us to book for your clients.",
            'general' => "Hi {name}, update from {$name}: ",
        ];
    }

    /** SMS parts needed for this text (GSM: 160 / 153 per part, otherwise 70 / 67). */
    public static function segments(string $message): int
    {
        $length = 0;
        $unicode = false;
        foreach (mb_str_split($message) as $character) {
            if (in_array($character, ['^', '{', '}', '\\', '[', '~', ']', '|', '€'], true)) {
                $length += 2;
            } elseif (mb_strpos(self::GSM_CHARACTERS, $character) !== false) {
                $length++;
            } else {
                $unicode = true;
                break;
            }
        }
        if ($unicode) {
            $length = intdiv(strlen(mb_convert_encoding($message, 'UTF-16BE', 'UTF-8')), 2);

            return $length <= 70 ? 1 : (int) ceil($length / 67);
        }

        return $length <= 160 ? 1 : (int) ceil($length / 153);
    }

    /**
     * Lines for chosen published listings, e.g. "- Sea View (2 bedrooms, Nyali) KES 12,000/night app.example.com/l/7".
     *
     * @param  list<int>  $listingIds
     * @param  array<int|string, mixed>  $prices  Price per listing id for this message; blank uses the listing's own price.
     */
    public function listingLines(Tenant $tenant, array $listingIds, array $prices = []): string
    {
        if ($listingIds === []) {
            return '';
        }

        return $this->publishedListings($tenant)->whereIn('id', $listingIds)->map(function (array $listing) use ($prices): string {
            $price = $prices[$listing['id']] ?? null;

            return self::listingLine($listing, is_numeric($price) ? (float) $price : $listing['price']);
        })->implode("\n");
    }

    /** @param array{title: string, summary: string, currency: string, short_url: string} $listing */
    public static function listingLine(array $listing, ?float $price): string
    {
        return '- '.$listing['title'].($listing['summary'] !== '' ? ' ('.$listing['summary'].')' : '')
            .($price !== null && $price > 0 ? ' '.$listing['currency'].' '.number_format($price).'/night' : '').' '.$listing['short_url'];
    }

    /** Short public link for a listing without the https:// prefix, which phones still recognise as a link. */
    public static function shortUrl(int $listingId): string
    {
        return preg_replace('#^https?://#', '', route('public-stays.short', base_convert((string) $listingId, 10, 36)));
    }

    /** @return Collection<int, array{id: int, title: string, summary: string, price: ?float, currency: string, short_url: string}> */
    public function publishedListings(Tenant $tenant): Collection
    {
        $listings = rescue(fn () => app(PublicListingCatalog::class)->published($tenant), collect(), false);

        return collect($listings)->map(function (array $listing) use ($tenant): array {
            $place = $listing['neighbourhood'] ?: $listing['city'];
            $layout = $listing['layout_label'] ?? PublicListingCatalog::layoutLabel($listing['bedrooms'] ?? null, $listing['property_type'] ?? null);

            return ['id' => (int) $listing['id'], 'title' => $listing['title'], 'summary' => implode(', ', array_filter([$layout, $place])),
                'price' => (float) ($listing['starting_price'] ?? 0) > 0 ? (float) $listing['starting_price'] : null,
                'currency' => $listing['currency'] ?? $tenant->currency ?? 'KES', 'short_url' => self::shortUrl((int) $listing['id'])];
        })->values();
    }

    /** The agent's mobile number for SMS: their phone, or their WhatsApp number when the phone is not a mobile. */
    public static function mobile(object $agent): ?string
    {
        return PhoneNumber::normalize($agent->phone ?? null) ?? (($agent->whatsapp ?? null) ?: null);
    }

    /** Personalised text for one agent. */
    public static function personalise(string $message, string $agentName): string
    {
        $firstName = trim(strtok(trim($agentName), ' ') ?: '') ?: 'there';

        return str_replace('{name}', $firstName, $message);
    }

    /**
     * Queue the broadcast. Agents without a mobile number or who opted out are recorded as skipped.
     *
     * @param  list<int>  $agentIds
     * @param  list<int>  $listingIds
     */
    public function queue(Tenant $tenant, ?int $userId, string $kind, string $message, array $agentIds, array $listingIds): int
    {
        $agents = DB::table('sales_agents')->where('tenant_id', $tenant->id)->whereIn('id', $agentIds)->orderBy('name')->get();
        $message = str_replace("\r\n", "\n", $message);

        return DB::transaction(function () use ($tenant, $userId, $kind, $message, $agents, $listingIds): int {
            $broadcastId = DB::table('agent_broadcasts')->insertGetId([
                'tenant_id' => $tenant->id, 'kind' => $kind, 'channel' => 'sms', 'message' => $message,
                'listing_ids' => json_encode(array_values($listingIds), JSON_THROW_ON_ERROR), 'recipient_count' => $agents->count(),
                'segments' => self::segments(self::personalise($message, 'Agent')), 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $rows = $agents->map(function (object $agent) use ($tenant, $broadcastId): array {
                $phone = self::mobile($agent);
                $skip = ! $agent->active ? 'Agent is inactive.' : ($agent->broadcast_opt_out ? 'Agent asked not to receive broadcasts.' : ($phone === null ? 'No mobile number saved for this agent.' : null));

                return ['tenant_id' => $tenant->id, 'broadcast_id' => $broadcastId, 'agent_id' => $agent->id, 'agent_name' => mb_substr($agent->name, 0, 150),
                    'channel' => 'sms', 'phone' => $phone, 'status' => $skip ? 'skipped' : 'pending', 'last_error' => $skip,
                    'created_at' => now(), 'updated_at' => now()];
            })->all();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('agent_broadcast_recipients')->insert($chunk);
            }

            return $broadcastId;
        });
    }

    /** Send one queued recipient message. Safe to call repeatedly: only pending or retryable rows are sent. */
    public function deliver(int $recipientId): void
    {
        $recipient = DB::table('agent_broadcast_recipients')->where('id', $recipientId)->first();
        if (! $recipient || ! in_array($recipient->status, ['pending', 'failed'], true) || $recipient->attempts >= 5) {
            return;
        }
        if ($recipient->next_attempt_at && $recipient->next_attempt_at > now()->toDateTimeString()) {
            return;
        }
        $tenant = Tenant::find($recipient->tenant_id);
        $broadcast = DB::table('agent_broadcasts')->where('id', $recipient->broadcast_id)->first();
        if (! $tenant || ! $broadcast || ! $tenant->active || ($tenant->modules['agents'] ?? true) === false) {
            $this->status($recipientId, 'blocked', 'Company or referral agents module is not active.');

            return;
        }
        if (! $this->smsAvailable($tenant)) {
            $this->status($recipientId, 'blocked', 'SMS sending is not switched on for this company.');

            return;
        }
        if (! DB::table('agent_broadcast_recipients')->where('id', $recipientId)->where('status', $recipient->status)
            ->update(['status' => 'processing', 'attempts' => $recipient->attempts + 1, 'sms_provider' => 'roycetalk', 'updated_at' => now()])) {
            return;
        }
        try {
            $response = $this->sms->send($recipient->phone, self::personalise($broadcast->message, $recipient->agent_name), 'agentcast-'.$tenant->id.'-'.$recipient->id);
        } catch (\Throwable) {
            $this->status($recipientId, 'unknown', 'SMS provider could not be reached. Check provider logs before retrying.');

            return;
        }
        if ($response->status() === 429) {
            DB::table('agent_broadcast_recipients')->where('id', $recipientId)->update(['status' => 'failed', 'last_error' => 'SMS rate limit; retry scheduled.', 'next_attempt_at' => now()->addMinutes(5), 'updated_at' => now()]);

            return;
        }
        if (in_array($response->status(), [400, 401, 403, 404, 422], true)) {
            $this->status($recipientId, 'rejected', 'SMS provider rejected the message (HTTP '.$response->status().'). Check sender ID, number and SMS balance.');

            return;
        }
        $reference = $response->json('data.message_id');
        if (! $response->successful() || $response->json('success') !== true || ! is_string($reference) || ! preg_match('/^[a-zA-Z0-9-]{1,100}$/', $reference)) {
            $this->status($recipientId, 'unknown', 'SMS acceptance is uncertain. Check provider logs before retrying.');

            return;
        }
        DB::table('agent_broadcast_recipients')->where('id', $recipientId)->update(['status' => 'submitted', 'provider_reference' => $reference, 'last_error' => null, 'sent_at' => now(), 'updated_at' => now()]);
    }

    /** Counts by status for each broadcast. @param list<int> $broadcastIds @return array<int, array<string, int>> */
    public function statusCounts(array $broadcastIds): array
    {
        $counts = [];
        DB::table('agent_broadcast_recipients')->whereIn('broadcast_id', $broadcastIds)->selectRaw('broadcast_id, status, count(*) as total')
            ->groupBy('broadcast_id', 'status')->get()->each(function (object $row) use (&$counts): void {
                $counts[(int) $row->broadcast_id][$row->status] = (int) $row->total;
            });

        return $counts;
    }

    private function status(int $id, string $status, string $error): void
    {
        DB::table('agent_broadcast_recipients')->where('id', $id)->update(['status' => $status, 'last_error' => $error, 'updated_at' => now()]);
    }
}
