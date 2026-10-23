<!doctype html>
<html lang="en">
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #1e293b;">
    <p>Dear {{ $quotation->contact_person ?: $quotation->party_name }},</p>

    @if ($note)
        <p style="white-space: pre-line;">{{ $note }}</p>
    @else
        <p>Thank you for your inquiry. Please find our quotation <strong>{{ $quotation->quotation_number }}</strong> (revision {{ $quotation->revision_no }}) attached.</p>
    @endif

    <p>
        Total: <strong>₹{{ \App\Modules\Shared\Support\Format::number($quotation->grand_total) }}</strong>
        (including GST)<br>
        Valid until: <strong>{{ \App\Modules\Shared\Support\Format::date($quotation->valid_until) }}</strong>
    </p>

    <p>Please reply to this e-mail, or call me, if you would like anything changed.</p>

    <p>
        Regards,<br>
        {{ $senderName }}<br>
        {{ $companyName }}
    </p>
</body>
</html>
