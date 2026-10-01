<?php

use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Models\TransfersTracking;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\WithPagination;

function registerProbe(): Component
{
    return new class extends Component
    {
        use FiltersFinanceRegister;
        use WithPagination;

        protected function registerSorts(): array
        {
            return [
                'scheduled' => ['dir' => 'desc', 'order' => ['transfers_tracking.scheduled_date {dir}', 'transfers_tracking.id desc']],
                'amount' => ['dir' => 'desc', 'order' => ['transfers_tracking.payment_amount {dir}', 'transfers_tracking.id desc']],
            ];
        }

        protected function registerDefaultSort(): string
        {
            return 'scheduled';
        }

        public function ids(): array
        {
            return $this->applyRegisterSort(TransfersTracking::query())->pluck('id')->all();
        }

        public function render(): string
        {
            return '<div>{{ $this->selectedYear() }}|{{ $this->yearOnly() ? "only" : "beside" }}</div>';
        }
    };
}

test('values from the address bar fall back to the defaults', function () {
    Livewire::withQueryParams(['view' => 'grid', 'year' => '20x6', 'figures' => 'decade', 'sortBy' => 'id;drop', 'sortDir' => 'up', 'perPage' => '7'])
        ->test(registerProbe())
        ->assertSet('view', 'table')->assertSet('year', '')->assertSet('figures', 'lifetime')
        ->assertSet('sortBy', '')->assertSet('sortDir', '')->assertSet('perPage', '25');
});

test('year only needs a year', function () {
    Livewire::test(registerProbe())
        ->set('figures', 'year')->assertSet('figures', 'lifetime')
        ->set('year', '2026')->set('figures', 'year')->assertSee('2026|only')
        ->set('year', '')->assertSet('figures', 'lifetime');
});

test('a heading sorts by its default direction and a second click reverses it', function () {
    $small = TransfersTracking::factory()->create(['payment_amount' => '10.00']);
    $large = TransfersTracking::factory()->create(['payment_amount' => '900.00']);

    $page = Livewire::test(registerProbe())->call('sortRegister', 'amount')->assertSet('sortBy', 'amount')->assertSet('sortDir', 'desc');
    expect($page->instance()->ids())->toBe([$large->id, $small->id]);

    $page->call('sortRegister', 'amount')->assertSet('sortDir', 'asc');
    expect($page->instance()->ids())->toBe([$small->id, $large->id]);

    $page->call('sortRegister', 'unknown')->assertSet('sortBy', 'amount');
});

test('year options list every year a tranche is dated in, newest first', function () {
    $this->travelTo('2026-06-15 09:00:00');
    TransfersTracking::factory()->create(['scheduled_date' => '2024-03-01', 'receive_date' => null]);

    expect(Livewire::test(registerProbe())->instance()->yearOptions)->toBe([2026, 2024]);
});
