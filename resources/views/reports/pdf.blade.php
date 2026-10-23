<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28px 24px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; color: #0f172a; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .meta { color: #475569; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #e2e8f0; text-align: left; padding: 3px 4px; border: 1px solid #cbd5e1; }
        td { padding: 3px 4px; border: 1px solid #e2e8f0; vertical-align: top; }
        .r { text-align: right; }
        .note { margin: 6px 0; padding: 4px 6px; background: #f1f5f9; }
        .sum span { display: inline-block; margin-right: 14px; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">{{ $company }} · {{ $filters }} · made {{ $madeAt }} by {{ $madeBy }}</div>
    @if ($note)<div class="note">{{ $note }}</div>@endif
    @if ($summary !== [])
        <div class="sum">
            @foreach ($summary as $label => $value)<span><strong>{{ $label }}:</strong> {{ $value }}</span>@endforeach
        </div>
    @endif
    <table>
        <thead>
            <tr>@foreach ($columns as $column)<th @class(['r' => $column->isNumeric()])>{{ $column->label }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>@foreach ($columns as $column)<td @class(['r' => $column->isNumeric()])>{{ $column->show($row[$column->key] ?? null) }}</td>@endforeach</tr>
            @endforeach
        </tbody>
    </table>
    @if ($truncated)<p class="note">Only the first {{ $limit }} rows are printed. Use the Excel export for all of them.</p>@endif
</body>
</html>
