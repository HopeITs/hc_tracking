{{--
    The Indicators & Targets tab of proposals/show (T13). The checklist and
    the per-center targets grid live in their own component
    (components/proposals/⚡targets) so their reads, writes and query budget
    stay out of the show page. Included by ⚡show.blade.php.
--}}
            <livewire:proposals.targets :proposal-id="$this->proposalId" :key="'proposal-targets-'.$this->proposalId" />
