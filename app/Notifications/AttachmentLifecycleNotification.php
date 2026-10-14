<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AttachmentLifecycleNotification extends Notification
{
    public function __construct(
        public readonly string $event,
        public readonly string $recordLabel,
        public readonly string $actorName,
        public readonly string $operation = 'upload',
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array{event: string, message: string, record: string, actor: string} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'message' => $this->message(),
            'record' => $this->recordLabel,
            'actor' => $this->actorName,
        ];
    }

    /** @return array{event: string, message: string, record: string, actor: string} */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = match ($this->event) {
            'attachment_uploaded' => 'Attachment uploaded',
            'attachment_deleted' => 'Attachment deleted',
            default => 'Attachment operation failed',
        };

        return (new MailMessage)
            ->subject($subject.': '.$this->recordLabel)
            ->greeting('Hello '.$notifiable->name)
            ->line($this->message())
            ->line('Changed by '.$this->actorName.'.');
    }

    private function message(): string
    {
        return match ($this->event) {
            'attachment_uploaded' => "A new attachment was uploaded for {$this->recordLabel}.",
            'attachment_deleted' => "An attachment was permanently deleted from {$this->recordLabel}.",
            default => $this->operation === 'delete'
                ? "An attachment could not be deleted from {$this->recordLabel}."
                : "An attachment could not be uploaded for {$this->recordLabel}.",
        };
    }
}
