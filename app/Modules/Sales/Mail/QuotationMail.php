<?php

namespace App\Modules\Sales\Mail;

use App\Modules\Sales\Models\Quotation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The e-mail that carries a quotation's PDF to the customer. Sent at once (not queued): the person asked for it and is told if it fails. */
class QuotationMail extends Mailable
{
    public function __construct(
        public readonly string $subjectLine,
        public readonly Quotation $quotation,
        public readonly string $companyName,
        public readonly ?string $note,
        public readonly string $senderName,
        public readonly string $senderEmail,
        private readonly string $pdf,
        private readonly string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine, replyTo: [new Address($this->senderEmail, $this->senderName)]);
    }

    public function content(): Content
    {
        return new Content(view: 'sales.mail.quotation');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}
