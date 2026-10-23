@php
    use App\Modules\Shared\Support\Format;

    $inter = $q->isInterState();
    $unit = fn ($line) => $line->unit?->code ?? '';
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') === '' ? '0' : rtrim(rtrim((string) $v, '0'), '.');
    // a rate keeps the decimals it has (up to 4) but never fewer than 2
    $rate = function ($v) {
        $t = rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        $d = str_contains($t, '.') ? strlen(substr($t, strpos($t, '.') + 1)) : 0;

        return Format::number($t === '' ? '0' : $t, max(2, $d));
    };
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Quotation {{ $q->displayNumber() }}</title>
    <style>
        @page { margin: 28px 30px 40px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1e293b; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .center { text-align: center; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .box { border: 1px solid #cbd5e1; padding: 6px 8px; }
        .lines th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 4px 3px; font-size: 8px; text-transform: uppercase; }
        .lines td { border: 1px solid #e2e8f0; padding: 4px 3px; vertical-align: top; }
        .totals td { padding: 2px 4px; }
        .grand td { border-top: 1px solid #1e293b; font-weight: bold; font-size: 11px; padding-top: 4px; }
        .terms { margin-top: 12px; white-space: pre-line; }
        .tag { border: 1px solid #94a3b8; padding: 1px 5px; font-size: 8px; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width: 58%">
                <h1>{{ $company?->legal_name ?: $company?->name }}</h1>
                <div class="muted">
                    @foreach (array_filter([$company?->address_line1, $company?->address_line2, trim(($company?->city ?? '').' '.($company?->pin_code ?? '')), $company?->state]) as $line){{ $line }}<br>@endforeach
                    @if ($q->supplier_gstin)GSTIN: <span class="mono">{{ $q->supplier_gstin }}</span>@endif
                </div>
            </td>
            <td class="right">
                <h1>QUOTATION</h1>
                <div><span class="mono">{{ $q->quotation_number }}</span> &middot; Revision {{ $q->revision_no }}</div>
                <div>Date: {{ Format::date($q->quotation_date) }}</div>
                <div><strong>Valid until: {{ Format::date($q->valid_until) }}</strong></div>
                @if ($q->priceList)<div class="muted">Price list: {{ $q->priceList->name }}</div>@endif
                @if (! $q->isApproved())<div style="margin-top:4px"><span class="tag">DRAFT &mdash; NOT APPROVED</span></div>@endif
            </td>
        </tr>
    </table>

    <table style="margin-top: 10px">
        <tr>
            <td style="width: 50%; padding-right: 6px;">
                <div class="box">
                    <div class="muted">To</div>
                    <strong>{{ $q->party_name }}</strong><br>
                    @foreach ($q->addressLines() as $line){{ $line }}<br>@endforeach
                    @if ($q->contact_person)Attn: {{ $q->contact_person }}<br>@endif
                    @if ($q->mobile)Mobile: {{ $q->mobile }}<br>@endif
                    @if ($q->email){{ $q->email }}<br>@endif
                    @if ($q->customer_gstin)GSTIN: <span class="mono">{{ $q->customer_gstin }}</span>@endif
                </div>
            </td>
            <td style="width: 50%; padding-left: 6px;">
                <div class="box">
                    <div class="muted">Place of supply</div>
                    <strong>{{ $q->placeOfSupply?->name }}</strong> ({{ $q->placeOfSupply?->gst_code }})<br>
                    {{ $inter ? 'Inter-state supply: IGST applies.' : 'Intra-state supply: CGST and SGST apply.' }}
                    @if ($owner)<br><span class="muted">Prepared by</span> {{ $owner->name }}@endif
                    @if ($q->location)<br><span class="muted">Branch</span> {{ $q->location->name }}@endif
                </div>
            </td>
        </tr>
    </table>

    <table class="lines" style="margin-top: 10px">
        <thead>
            <tr>
                <th style="width: 4%">#</th>
                <th>Item</th>
                <th style="width: 9%">Qty</th>
                <th style="width: 11%" class="right">Rate</th>
                <th style="width: 6%" class="right">Disc %</th>
                <th style="width: 13%" class="right">Taxable</th>
                <th style="width: 6%" class="right">GST %</th>
                <th style="width: 13%" class="right">Tax</th>
                <th style="width: 14%" class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($q->items as $line)
                <tr>
                    <td class="center">{{ $line->line_no }}</td>
                    <td>{{ $line->item_name }}@if ($line->hsn_code)<br><span class="muted">HSN {{ $line->hsn_code }}</span>@endif</td>
                    <td>{{ $qty($line->quantity) }} {{ $unit($line) }}</td>
                    <td class="right">{{ $rate($line->rate) }}</td>
                    <td class="right">{{ bccomp((string) $line->discount_percent, '0', 2) === 0 ? '–' : $qty($line->discount_percent) }}</td>
                    <td class="right">{{ Format::number($line->taxable_amount) }}</td>
                    <td class="right">{{ $qty($line->gst_rate) }}</td>
                    <td class="right">{{ Format::number(bcadd(bcadd((string) $line->cgst_amount, (string) $line->sgst_amount, 2), (string) $line->igst_amount, 2)) }}</td>
                    <td class="right">{{ Format::number($line->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 8px">
        <tr>
            <td style="width: 55%"></td>
            <td>
                <table class="totals">
                    <tr><td>Total before discount</td><td class="right">{{ Format::number($q->gross_total) }}</td></tr>
                    @if (bccomp((string) $q->discount_total, '0', 2) > 0)<tr><td>Discount</td><td class="right">– {{ Format::number($q->discount_total) }}</td></tr>@endif
                    <tr><td>Taxable value</td><td class="right">{{ Format::number($q->taxable_total) }}</td></tr>
                    @if ($inter)
                        <tr><td>IGST</td><td class="right">{{ Format::number($q->igst_total) }}</td></tr>
                    @else
                        <tr><td>CGST</td><td class="right">{{ Format::number($q->cgst_total) }}</td></tr>
                        <tr><td>SGST</td><td class="right">{{ Format::number($q->sgst_total) }}</td></tr>
                    @endif
                    @if (bccomp((string) $q->round_off, '0', 2) !== 0)<tr><td>Round off</td><td class="right">{{ bccomp((string) $q->round_off, '0', 2) > 0 ? '+' : '–' }} {{ Format::number(ltrim((string) $q->round_off, '-')) }}</td></tr>@endif
                    <tr class="grand"><td>Total (₹)</td><td class="right">{{ Format::number($q->grand_total) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($q->terms)
        <div class="terms"><strong>Terms and conditions</strong><br>{{ $q->terms }}</div>
    @endif

    <p class="muted" style="margin-top: 14px">This quotation is valid until {{ Format::date($q->valid_until) }}. Prices are exclusive of GST unless stated; GST is shown above.</p>
</body>
</html>
