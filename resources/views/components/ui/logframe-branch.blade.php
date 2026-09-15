@props(['nodes', 'depth'])

<ul class="relative ms-4 pt-3">
    <span aria-hidden="true" class="absolute start-0 top-0 bottom-6 w-px bg-secondary-light"></span>

    @foreach ($nodes as $node)
        <li class="relative ps-5 not-first:mt-2.5" wire:key="logframe-node-{{ $node->id }}">
            <span aria-hidden="true" class="absolute start-0 top-6 h-px w-5 bg-secondary-light"></span>
            <x-ui.logframe-node :result="$node" :depth="$depth" />
        </li>
    @endforeach
</ul>
