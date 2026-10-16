{{--
    The plan's read-only results chain (T16): the program framework measured
    by this plan's targets, with the activities whose linked milestones are
    planned in the plan year. Included by ⚡show.blade.php.
--}}
<div class="@container/detail">
    <x-ui.logframe
        :chain="$this->chain"
        :heading="__('results_framework.chain_heading')"
        :subheading="__('results_framework.chain_plan_subheading')"
        heading-id="annual-plan-chain-heading"
    >
        @can('results.create')
            <x-slot:start>
                <x-ui.button tone="primary" icon="plus" :href="route('results.index')" wire:navigate>{{ __('results_framework.chain_start') }}</x-ui.button>
            </x-slot:start>
        @endcan
    </x-ui.logframe>
</div>
