<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyInvitationNotification extends Notification
{
    public function __construct(public string $company, public string $invitationUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Join '.$this->company.' on Haven Suites')->line('You have been invited to join '.$this->company.'.')->action('Review invitation', $this->invitationUrl)->line('This invitation expires in seven days. Sign in with the invited email address to accept it.');
    }
}
