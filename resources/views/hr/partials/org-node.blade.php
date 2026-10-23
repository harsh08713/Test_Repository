{{-- One person in the org chart and, indented below, everyone who reports to them. $depth stops a circle that somehow exists from looping the page. --}}
<li wire:key="org-{{ $node->id }}">
    <a href="{{ route('hr.employees.show', $node) }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 hover:border-brand-500">
        <span class="font-medium text-slate-900">{{ $node->name }}</span>
        <span class="text-xs text-slate-500">{{ $node->designation?->name ?? $node->code }}{{ $node->department ? ' · '.$node->department->name : '' }}</span>
        @if ($node->employment_status === 'notice')<span class="badge bg-amber-100 text-amber-800">On notice</span>@endif
    </a>
    @if ($depth < 12 && $children->has($node->id))
        <ul class="ml-6 mt-2 space-y-2 border-l border-slate-200 pl-4">
            @foreach ($children->get($node->id) as $child)
                @include('hr.partials.org-node', ['node' => $child, 'children' => $children, 'depth' => $depth + 1])
            @endforeach
        </ul>
    @endif
</li>
