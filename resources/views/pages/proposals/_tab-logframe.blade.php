{{--
    The Logical Framework tab of proposals/show (T12). The framework lives in
    its own component (components/proposals/⚡logframe) so its reads, forms
    and query budget stay out of the show page. Included by ⚡show.blade.php.
--}}
            <livewire:proposals.logframe :proposal-id="$this->proposalId" :key="'proposal-logframe-'.$this->proposalId" />
