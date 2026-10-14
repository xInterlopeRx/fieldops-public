<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BusinessAssignmentChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $change,
        public readonly int $affectedUserId,
        public readonly string $affectedUserName,
        public readonly string $actorName,
        public readonly string $recordLabel,
        public readonly string $recordUrl,
        public readonly string $adminRecordUrl,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isAffectedUser = (int) $notifiable->getKey() === $this->affectedUserId;
        $action = $this->change === 'assigned' ? 'assigned to' : 'removed from';
        $subject = $this->change === 'assigned' ? 'Staff assignment added' : 'Staff assignment removed';
        $line = $isAffectedUser
            ? ($this->change === 'assigned'
                ? "You have been assigned to {$this->recordLabel}."
                : "Your assignment to {$this->recordLabel} has been removed.")
            : "{$this->affectedUserName} was {$action} {$this->recordLabel}.";

        $url = $notifiable->can('admin.access') ? $this->adminRecordUrl : $this->recordUrl;

        return (new MailMessage)
            ->subject($subject.': '.$this->recordLabel)
            ->greeting('Hello '.$notifiable->name)
            ->line($line)
            ->line('Changed by '.$this->actorName.'.')
            ->action('Open FieldOps', $url);
    }
}
