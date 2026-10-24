<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;

class RoyceTalkSms
{
    public function configured(): bool
    {
        return filled(config('services.roycetalk.api_key')) && filled(config('services.roycetalk.sender_id'));
    }

    private function client(): PendingRequest
    {
        if (! filled(config('services.roycetalk.api_key'))) {
            throw new LogicException('RoyceTalk API key is not configured.');
        }

        return Http::baseUrl('https://roycetalk.com/api/v1/sms-api')->withToken(config('services.roycetalk.api_key'))
            ->acceptJson()->asJson()->connectTimeout(5)->timeout(20)->withoutRedirecting();
    }

    public function send(string $phone, string $message, string $reference): Response
    {
        if (! $this->configured()) {
            throw new LogicException('RoyceTalk API key and approved sender ID are required.');
        }
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) {
            throw new LogicException('SMS phone number must use international format.');
        }

        return $this->client()->post('/send/', ['phone_number' => $phone, 'sender_id' => config('services.roycetalk.sender_id'), 'text_message' => $message, 'client_ref' => $reference]);
    }

    public function messageStatus(string $reference): Response
    {
        return $this->client()->get('/messages/'.rawurlencode($reference).'/');
    }

    public function balance(): Response
    {
        return $this->client()->get('/balance/');
    }
}
