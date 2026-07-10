<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InternalAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected readonly array $payload
    ) {}

    public function via(object $notifiable): array
    {
        return config('notifications.admin_channels', ['database']);
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->payload['title'])
            ->greeting("Hello {$notifiable->first_name},")
            ->line($this->payload['message'])
            ->action('Open notification', url($this->payload['action_url']))
            ->line('This is an internal system notification.');
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload);
    }

    public function broadcastType(): string
    {
        return 'internal.admin.notification';
    }
}
