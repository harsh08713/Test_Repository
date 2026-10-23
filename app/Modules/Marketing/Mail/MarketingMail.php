<?php

namespace App\Modules\Marketing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Symfony\Component\Mime\Email;

/**
 * One e-mail from a campaign or a workflow. The text the template produced is shown as plain text in a simple HTML wrapper — escaped, line breaks
 * kept, web addresses made into links — so nothing typed into a template or a lead's name can become markup. Marketing mail carries the
 * `List-Unsubscribe` headers so a mail program can offer to opt out.
 */
class MarketingMail extends Mailable
{
    public function __construct(
        private readonly string $mailSubject,
        private readonly string $text,
        private readonly string $senderName,
        private readonly ?string $unsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject, from: $this->senderName === '' ? null : new Address((string) config('mail.from.address'), $this->senderName));
    }

    public function content(): Content
    {
        $html = nl2br(e($this->text), false);
        $html = (string) preg_replace('~(https?://[^\s<]+)~', '<a href="$1">$1</a>', $html);

        return new Content(htmlString: '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1e293b">'.$html.'</div>');
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->unsubscribeUrl === null ? [] : [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
        ]);
    }

    public function build(): static
    {
        return $this->withSymfonyMessage(fn (Email $message) => $message->text($this->text));
    }
}
