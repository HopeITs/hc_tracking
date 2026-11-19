{{--
    Add a reviewer to the stage now taking reviewers (ManagesProposalReviewers::addReviewer).
    Included by _sec-history.blade.php for someone who may manage reviewers.
--}}
<form wire:submit="addReviewer" class="pp-add-reviewer" data-add-reviewer>
    <div>
        <x-ui.form-select wire:model="reviewerEmployeeId" :label="__('proposals.reviewer_to_add')" :placeholder="__('proposals.choose_a_reviewer')" employees />
    </div>
    <button type="submit" class="pp-btn pp-btn-sm" wire:loading.attr="disabled" wire:target="addReviewer" data-test="add-reviewer">
        <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />{{ __('proposals.add_reviewer') }}
    </button>
</form>
