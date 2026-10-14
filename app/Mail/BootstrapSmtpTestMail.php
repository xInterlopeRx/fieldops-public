<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BootstrapSmtpTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'FieldOps SMTP configuration test');
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>FieldOps successfully sent this SMTP configuration test message. A separate email verification link will follow after the first Admin account is created.</p>',
        );
    }
}
