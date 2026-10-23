<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Org chart</h1>
        <p class="mt-1 text-sm text-slate-500">Who reports to whom ({{ $total }} {{ $total === 1 ? 'person' : 'people' }} you may see; people who have left are not shown).</p>
    </div>

    <div class="card card-body">
        @if ($total === 0)
            <p class="py-8 text-center text-sm text-slate-500">There is nobody to show.</p>
        @else
            <ul class="space-y-2 text-sm">
                @foreach ($children->get(0, collect()) as $root)
                    @include('hr.partials.org-node', ['node' => $root, 'children' => $children, 'depth' => 0])
                @endforeach
            </ul>
        @endif
    </div>
</div>
