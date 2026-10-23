import Chart from 'chart.js/auto';

const palette = ['#2563eb', '#16a34a', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#ec4899', '#84cc16', '#64748b', '#f97316'];
const inr = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 });

/** ₹1.25 Cr / ₹12.5 L / ₹45,000 — the same short form the server prints on the cards. */
export function money(value) {
    const n = Number(value) || 0;
    const a = Math.abs(n);
    const sign = n < 0 ? '-' : '';
    if (a >= 1e7) return `${sign}₹${(a / 1e7).toFixed(2).replace(/\.?0+$/, '')} Cr`;
    if (a >= 1e5) return `${sign}₹${(a / 1e5).toFixed(2).replace(/\.?0+$/, '')} L`;
    return `${sign}₹${inr.format(a)}`;
}

const grid = { color: 'rgba(148,163,184,.18)' };
const ticks = { color: '#64748b', font: { size: 11 } };

/** Builds a chart from a plain description sent by the server; nothing here decides what a figure is. */
export function mountChart(canvas, spec) {
    const kind = spec.kind;
    const money$ = spec.money !== false;
    const tooltip = { callbacks: { label: (c) => ` ${c.dataset.label ? c.dataset.label + ': ' : ''}${money$ ? money(c.parsed.y ?? c.parsed.x ?? c.parsed) : c.formattedValue}` } };

    if (kind === 'donut') {
        return new Chart(canvas, {
            type: 'doughnut',
            data: { labels: spec.labels, datasets: [{ data: spec.values.map(Number), backgroundColor: spec.labels.map((_, i) => palette[i % palette.length]), borderWidth: 2, borderColor: '#fff' }] },
            options: {
                cutout: '68%', maintainAspectRatio: false, animation: { duration: 400 },
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ` ${c.label}: ${money(c.parsed)}` } } },
                onClick: (_e, els) => { if (els.length && spec.urls?.[els[0].index]) window.Livewire?.navigate ? window.Livewire.navigate(spec.urls[els[0].index]) : (location.href = spec.urls[els[0].index]); },
            },
        });
    }

    if (kind === 'bars') {
        return new Chart(canvas, {
            type: 'bar',
            data: { labels: spec.labels, datasets: [{ data: spec.values.map(Number), backgroundColor: spec.color || '#2563eb', borderRadius: 4, barThickness: 14 }] },
            options: {
                indexAxis: 'y', maintainAspectRatio: false, animation: { duration: 400 },
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ` ${money(c.parsed.x)}` } } },
                scales: { x: { grid, ticks: { ...ticks, callback: (v) => money(v) } }, y: { grid: { display: false }, ticks } },
            },
        });
    }

    // trend: revenue bars, loss line, the previous period dashed
    const sets = spec.series.map((s) => ({
        type: s.type, label: s.label, data: s.data.map(Number), borderColor: s.color, backgroundColor: s.type === 'bar' ? s.color : s.color + '22',
        borderDash: s.dashed ? [5, 4] : [], borderWidth: s.type === 'bar' ? 0 : 2, borderRadius: 4, pointRadius: s.dashed ? 0 : 3, tension: 0.3, order: s.type === 'bar' ? 2 : 1,
    }));

    return new Chart(canvas, {
        data: { labels: spec.labels, datasets: sets },
        options: {
            maintainAspectRatio: false, animation: { duration: 400 }, interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, color: '#475569' } }, tooltip },
            scales: { x: { grid: { display: false }, ticks }, y: { grid, ticks: { ...ticks, callback: (v) => money(v) } } },
        },
    });
}
