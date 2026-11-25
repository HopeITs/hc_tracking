<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MonthlyReports;

use App\Actions\MonthlyReports\SaveMonthlyReportRisks;
use App\Models\MonthlyReport;
use App\Support\MonthlyReportRisks;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * The optional "Update risks" line of monthly-reports/show. The checkbox
 * and each "Happened" tick are saved at once through SaveMonthlyReportRisks
 * with the status the page rendered; a refused save reloads the stored
 * state.
 *
 * Requires ReadsMonthlyReport and EditsMonthlyReportActivities.
 *
 * @property-read MonthlyReport $report  #[Computed] report()
 * @property-read list<array{id: int, name: string, proposal: ?string, likelihood: ?int, severity: ?int, score: ?int, happened: bool, answer: ?bool}> $risks  #[Computed] risks()
 */
trait EditsMonthlyReportRisks
{
    public bool $updatesRisks = false;

    /**
     * Risk id => happened, pre-filled from each risk's last answer.
     *
     * @var array<int|string, bool>
     */
    public array $riskHappened = [];

    /**
     * The logframe risks of the report's proposals, as the page lists them.
     *
     * @return list<array{id: int, name: string, proposal: ?string, likelihood: ?int, severity: ?int, score: ?int, happened: bool, answer: ?bool}>
     */
    #[Computed]
    public function risks(): array
    {
        return MonthlyReportRisks::for($this->report)->map(fn (array $row): array => [
            'id' => $row['risk']->id,
            'name' => $row['risk']->localizedName(),
            'proposal' => $row['proposal_name'],
            'likelihood' => $row['risk']->likelihood,
            'severity' => $row['risk']->severity,
            'score' => $row['risk']->heatScore(),
            'happened' => (bool) $row['risk']->happened,
            'answer' => $row['answer'],
        ])->all();
    }

    public function updatedUpdatesRisks(): void
    {
        $this->saveRisks([]);
    }

    public function updatedRiskHappened(mixed $value, ?string $key = null): void
    {
        $riskIds = $key === null ? array_keys($this->riskHappened) : [explode('.', $key)[0]];
        $happened = [];

        foreach ($riskIds as $riskId) {
            $happened[(int) $riskId] = (bool) ($this->riskHappened[$riskId] ?? false);
        }

        $this->saveRisks($happened);
    }

    /**
     * @param  array<int, bool>  $happened
     */
    protected function saveRisks(array $happened): void
    {
        try {
            app(SaveMonthlyReportRisks::class)->handle($this->report, $this->renderedStatus(), $this->updatesRisks, $happened);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: collect($exception->errors())->flatten()->first());
            $this->forgetMonthlyReport();
            $this->seedRisks();

            return;
        } catch (AuthorizationException) {
            $this->refuseNarrativeSave();
            $this->seedRisks();

            return;
        } catch (ModelNotFoundException) {
            Flux::toast(variant: 'danger', text: __('monthly_reports.risk_not_in_report'));
            $this->seedRisks();

            return;
        }

        $this->forgetMonthlyReport();
        unset($this->risks);
    }

    /**
     * Fill the checkbox and the ticks from what is stored.
     */
    protected function seedRisks(): void
    {
        unset($this->risks);
        $this->updatesRisks = (bool) $this->report->updates_risks;
        $this->riskHappened = [];

        foreach ($this->risks as $risk) {
            $this->riskHappened[$risk['id']] = $risk['happened'];
        }
    }
}
