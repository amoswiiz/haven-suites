<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the team when the live site hits an unexpected error. Each distinct error is sent at most once per
 * 30 minutes, and nothing is sent unless ERROR_ALERT_EMAIL is set. Alerts never include request data or passwords.
 */
class ErrorAlerts
{
    public static function report(Throwable $exception): void
    {
        $to = config('haven.error_alert_email');
        if (! $to || app()->runningUnitTests() || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            $key = 'error-alert:'.sha1($exception::class.'|'.$exception->getFile().'|'.$exception->getLine());
            if (! Cache::add($key, true, now()->addMinutes(30))) {
                return;
            }
            $request = app()->runningInConsole() ? null : request();
            $body = implode("\n", array_filter([
                'An unexpected error happened on '.config('app.name').' ('.config('app.url').').',
                '',
                'Error: '.$exception::class,
                'Message: '.mb_substr($exception->getMessage(), 0, 500),
                'Where: '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $exception->getFile()).':'.$exception->getLine(),
                $request ? 'Page: '.$request->method().' '.$request->path() : 'Source: scheduled task or command',
                'Time: '.now()->toDateTimeString().' ('.config('app.timezone').')',
                '',
                'Full details are in storage/logs/laravel.log. Repeats of this error are not emailed for 30 minutes.',
            ], fn ($line) => $line !== null));
            Mail::raw($body, fn ($message) => $message->to($to)->subject('['.config('app.name').'] Site error: '.class_basename($exception)));
        } catch (Throwable) {
            // Never let an alert failure hide the original error.
        }
    }
}
