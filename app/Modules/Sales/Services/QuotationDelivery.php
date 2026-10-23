<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Sales\Mail\QuotationMail;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Shared\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Turns a quotation into the two things a customer receives — a PDF and an e-mail carrying it. The PDF is rendered from the stored
 * revision only (never from anything typed), with remote resources switched off, and every value is escaped by Blade; the e-mail goes
 * through whatever mail transport the installation is configured with (`MAIL_MAILER`), with the sender's address as Reply-To.
 */
final class QuotationDelivery
{
    /** The binary PDF of a revision, exactly as stored. */
    public function pdf(Quotation $quotation): string
    {
        return Pdf::loadView('sales.quotation-pdf', $this->data($quotation))
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->output();
    }

    /** The file name a download or an attachment carries: QT-AHD-26-27-00001-Rev1.pdf */
    public function filename(Quotation $quotation): string
    {
        return Str::slug(str_replace('/', '-', $quotation->quotation_number), '-', null).'-Rev'.$quotation->revision_no.'.pdf';
    }

    /**
     * Check who it is going to. Input: ['to', 'cc', 'subject', 'message'] — the address defaults to the quotation's own.
     *
     * @param  array<string, mixed>  $input
     * @return array{to: string, cc: string|null, subject: string, message: string|null}
     *
     * @throws ValidationException
     */
    public function validate(array $input, Quotation $quotation): array
    {
        $data = [
            'to' => trim((string) ($input['to'] ?? '')) !== '' ? trim((string) $input['to']) : (string) $quotation->email,
            'cc' => trim((string) ($input['cc'] ?? '')) === '' ? null : trim((string) $input['cc']),
            'subject' => trim((string) ($input['subject'] ?? '')) !== '' ? trim((string) $input['subject']) : $this->defaultSubject($quotation),
            'message' => trim((string) ($input['message'] ?? '')) === '' ? null : trim((string) $input['message']),
        ];

        return Validator::make($data, [
            'to' => ['required', 'email:rfc', 'max:150'],
            'cc' => ['nullable', 'email:rfc', 'max:150'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], ['to.required' => 'Enter the e-mail address to send the quotation to.', 'to.email' => 'Enter a valid e-mail address.', 'cc.email' => 'Enter a valid e-mail address.'])->validate();
    }

    public function defaultSubject(Quotation $quotation): string
    {
        $company = Company::withoutGlobalScopes()->find($quotation->company_id);

        return "Quotation {$quotation->quotation_number} (Rev {$quotation->revision_no})".($company === null ? '' : " from {$company->name}");
    }

    /**
     * Send it. Throws if the transport refuses; QuotationService decides what that means for the quotation.
     *
     * @param  array{to?: string, cc?: string|null, subject?: string, message?: string|null}  $mail
     */
    public function email(Quotation $quotation, string $pdf, array $mail, User $sender): void
    {
        $company = Company::withoutGlobalScopes()->find($quotation->company_id);

        $message = new QuotationMail(
            subjectLine: (string) ($mail['subject'] ?? $this->defaultSubject($quotation)),
            quotation: $quotation, companyName: (string) ($company->name ?? config('app.name')), note: $mail['message'] ?? null,
            senderName: $sender->name, senderEmail: $sender->email, pdf: $pdf, filename: $this->filename($quotation),
        );

        $pending = Mail::to((string) $mail['to']);

        if (! empty($mail['cc'])) {
            $pending->cc((string) $mail['cc']);
        }

        $pending->send($message);
    }

    /** What may be shown or stored about a mail failure: its kind and message with anything that looks like a credential removed. */
    public function safeMessage(Throwable $e): string
    {
        $text = preg_replace('#(://)[^/@\s]+:[^/@\s]+@#', '$1***@', $e->getMessage()) ?? '';
        $text = preg_replace('/(password|passwd|pwd|secret|token|key)\s*[=:]\s*\S+/i', '$1=***', $text) ?? '';

        return Str::limit(class_basename($e).': '.trim($text), 300, '…');
    }

    /** @return array<string, mixed> */
    private function data(Quotation $quotation): array
    {
        $quotation->load(['items.unit:id,code,name', 'location', 'placeOfSupply:id,name,gst_code', 'priceList:id,name', 'lead:id,assigned_to']);

        $owner = $quotation->lead?->assigned_to === null ? null : User::query()->find($quotation->lead->assigned_to);

        return [
            'q' => $quotation,
            'company' => Company::withoutGlobalScopes()->find($quotation->company_id),
            'owner' => $owner,
        ];
    }
}
