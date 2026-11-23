<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CollectionFrequency;
use App\Enums\DenominatorBasis;
use App\Enums\DonorAttribution;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Enums\LineSchedule;
use App\Enums\MonthlyReportEntryKind;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ReportTemplateStatus;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\PlanIndicator;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\User;
use App\Support\IndicatorCode;
use App\Support\MonthlyReportGenerator;
use App\Support\MonthlyReportLineBuilder;
use App\Support\MonthlyReportMirrors;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fills the Monthly Matrix with fake figures for January to November 2026.
 *
 * 1. Links every target's indicator to an institutional (HC) Indicator
 *    Library entry, one per name and measure. The real linking command
 *    (meal:seed-indicator-library) stops while HR shares a program code
 *    between two programs, so this demo links institutionally instead.
 * 2. Gives every place holding targets a confirmed report template with one
 *    confirmed monthly line per target, joined by the place's proposals.
 * 3. Creates the monthly reports, builds their lines and fills them:
 *    January to September validated, October submitted, November a draft.
 *    The `reports` mirrors are written through MonthlyReportMirrors, as the
 *    monthly-report actions do.
 *
 * Not part of DatabaseSeeder. Run it by hand:
 *
 *     php artisan db:seed --class=MonthlyMatrixDemoSeeder
 *
 * Safe to run again: links, templates, lines and reports are matched first,
 * and a month that already carries figures is left as it is.
 */
class MonthlyMatrixDemoSeeder extends Seeder
{
    private const FIRST_MONTH = '2026-01-01';

    private const LAST_MONTH = '2026-11-01';

    private const LAST_VALIDATED_MONTH = '2026-09-01';

    public function run(): void
    {
        $actorId = User::query()->orderBy('id')->value('id');

        if ($actorId === null) {
            $this->command?->warn('No user to act as: nothing was seeded.');

            return;
        }

        mt_srand(2026);

        Model::unguarded(fn () => Activity::withoutRecording(function () use ($actorId): void {
            $this->linkIndicatorsToLibrary();

            foreach ($this->places() as $place) {
                $template = $this->templateAt($place, $actorId);
                $this->addTemplateLines($template, $place, $actorId);
                $this->fillReports($template, $actorId);
            }

            $this->assignPeople($actorId);
        }));
    }

    /**
     * One institutional library entry per (name, measure) of the targets'
     * unlinked indicators, and each indicator linked to it.
     */
    private function linkIndicatorsToLibrary(): void
    {
        $indicators = Indicator::query()
            ->whereNull('library_indicator_id')
            ->whereIn('id', PlanIndicator::query()->select('indicator_id'))
            ->with('result:id,type')
            ->orderBy('id')
            ->get();

        $indicators->groupBy(fn (Indicator $indicator): string => mb_strtolower(trim($indicator->name)).'|'.$indicator->measure->value)
            ->each(function (Collection $group): void {
                /** @var Indicator $first */
                $first = $group->first();

                DB::transaction(function () use ($first, $group): void {
                    $entry = $this->libraryEntryFor($first);
                    Indicator::query()->whereKey($group->pluck('id'))->update(['library_indicator_id' => $entry->id, 'code' => $entry->code]);
                });
            });
    }

    private function libraryEntryFor(Indicator $indicator): LibraryIndicator
    {
        $isPercentage = $indicator->measure === IndicatorMeasure::Percentage;
        $resultType = $indicator->result?->type;
        $levelRef = match ($resultType) {
            ResultType::Outcome => 'OC1',
            ResultType::Output => 'OP1',
            default => 'G',
        };

        $existing = LibraryIndicator::query()
            ->whereNull('program_id')
            ->where('name_en', $indicator->name)
            ->where('measure', $indicator->measure->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        ['code' => $code, 'serial' => $serial] = IndicatorCode::next(null, $levelRef);

        $entry = new LibraryIndicator([
            'program_id' => null,
            'level_ref' => $levelRef,
            'name_ar' => $indicator->name,
            'name_en' => $indicator->name,
            'measure' => $indicator->measure,
            'unit' => $isPercentage ? null : $indicator->unit,
            'denominator_basis' => $isPercentage ? DenominatorBasis::Entered : null,
            'frequency' => CollectionFrequency::Monthly,
            'suggested_level' => $resultType,
            'is_legacy' => true,
        ]);
        $entry->forceFill(['code' => $code, 'serial' => $serial])->save();

        return $entry;
    }

    /**
     * The places that hold targets (city, program, no center).
     *
     * @return Collection<int, object{city_id: int, program_id: int}>
     */
    private function places(): Collection
    {
        return PlanIndicator::query()
            ->whereNotNull('city_id')
            ->whereNotNull('program_id')
            ->whereNull('center_id')
            ->where('all_centers', false)
            ->distinct()
            ->orderBy('city_id')
            ->orderBy('program_id')
            ->get(['city_id', 'program_id'])
            ->map(fn (PlanIndicator $row): object => (object) ['city_id' => (int) $row->city_id, 'program_id' => (int) $row->program_id]);
    }

    /**
     * @param  object{city_id: int, program_id: int}  $place
     */
    private function templateAt(object $place, int $actorId): ReportTemplate
    {
        $template = ReportTemplate::query()
            ->where('city_id', $place->city_id)
            ->where('program_id', $place->program_id)
            ->whereNull('center_id')
            ->where('city_wide', false)
            ->orderBy('id')
            ->first();

        if ($template === null) {
            $template = new ReportTemplate;
            $template->forceFill(['project_id' => null, 'city_id' => $place->city_id, 'program_id' => $place->program_id, 'center_id' => null, 'city_wide' => false])->save();
        }

        if (! $template->isConfirmed()) {
            $template->forceFill(['status' => ReportTemplateStatus::Confirmed, 'confirmed_at' => '2025-12-15 09:00:00', 'confirmed_by' => $actorId])->save();
        }

        $proposalIds = $this->targetsAt($place)->where('source_type', 'proposal')->pluck('source_id')->unique();
        $joined = $template->proposals()->pluck('proposals.id');
        $template->proposals()->attach(
            $proposalIds->diff($joined)->mapWithKeys(fn (int $id): array => [$id => ['joined_at' => now(), 'joined_by' => $actorId]])->all(),
        );

        return $template;
    }

    /**
     * @param  object{city_id: int, program_id: int}  $place
     * @return Collection<int, PlanIndicator>
     */
    private function targetsAt(object $place): Collection
    {
        return PlanIndicator::query()
            ->where('city_id', $place->city_id)
            ->where('program_id', $place->program_id)
            ->whereNull('center_id')
            ->where('all_centers', false)
            ->orderBy('id')
            ->get(['id', 'source_type', 'source_id']);
    }

    /**
     * @param  object{city_id: int, program_id: int}  $place
     */
    private function addTemplateLines(ReportTemplate $template, object $place, int $actorId): void
    {
        foreach ($this->targetsAt($place) as $target) {
            ReportTemplateLine::query()->firstOrCreate(
                ['report_template_id' => $template->id, 'plan_indicator_id' => $target->id],
                [
                    'status' => ReportTemplateLineStatus::Confirmed,
                    'schedule' => LineSchedule::Monthly,
                    'entered_by' => IndicatorCollector::ProjectTeam,
                    'effective_from' => '2025-11-01',
                    'effective_to' => '2027-03-31',
                    'confirmed_at' => '2025-12-15 09:00:00',
                    'confirmed_by' => $actorId,
                ],
            );
        }
    }

    /**
     * Creates the months, builds their lines and fills them: validated up to
     * September, October submitted, November a draft with no figures yet.
     */
    private function fillReports(ReportTemplate $template, int $actorId): void
    {
        $first = CarbonImmutable::parse(self::FIRST_MONTH);
        $last = CarbonImmutable::parse(self::LAST_MONTH);
        $lastValidated = CarbonImmutable::parse(self::LAST_VALIDATED_MONTH);

        app(MonthlyReportGenerator::class)->generate($template, $first, $last);

        $reports = MonthlyReport::query()
            ->where('report_template_id', $template->id)
            ->whereBetween('period', [$first->toDateString(), $last->toDateString()])
            ->orderBy('period')
            ->get();

        $attribution = MonthlyReportLineBuilder::attributionOf($template);

        foreach ($reports as $report) {
            $period = CarbonImmutable::parse($report->period);

            if ($report->lines()->whereNotNull('value')->exists() || $period->equalTo($last)) {
                continue;
            }

            if ($report->status !== MonthlyReportStatus::Draft) {
                $report->forceFill(['status' => MonthlyReportStatus::Draft, 'submitted_at' => null, 'submitted_by' => null, 'validated_at' => null, 'validated_by' => null])->save();
            }

            app(MonthlyReportLineBuilder::class)->build($report);

            $lines = $report->lines()->with(['libraryIndicator', 'shares.mirror', 'shares.planIndicator.indicator'])->get();

            if ($lines->isEmpty()) {
                continue;
            }

            foreach ($lines as $line) {
                $this->fillLine($line, $attribution, $actorId);
            }

            $mirrors = app(MonthlyReportMirrors::class);
            $lines->each(fn (MonthlyReportLine $line) => $mirrors->sync($report, $line->shares, $actorId, createMissing: true));

            $submittedAt = $period->endOfMonth()->addDays(3)->setTime(10, 0);
            $validated = $period->lessThanOrEqualTo($lastValidated);

            $report->forceFill([
                'status' => $validated ? MonthlyReportStatus::Validated : MonthlyReportStatus::Submitted,
                'prepared_by' => $report->prepared_by ?? $actorId,
                'submitted_at' => $submittedAt,
                'submitted_by' => $actorId,
                'validated_at' => $validated ? $submittedAt->addDays(4) : null,
                'validated_by' => $validated ? $actorId : null,
            ])->save();

            $mirrors->moveTo($report, $submittedAt->startOfDay());
        }
    }

    /**
     * Puts the reports in other hands than the first user's: a team member
     * prepares and submits them and a MEAL user validates them, so the first
     * user (who usually browses the demo) still finds them to validate
     * (nobody validates their own report). Runs on every seed, so reports an
     * earlier run gave the first user are handed over too.
     */
    private function assignPeople(int $actorId): void
    {
        $preparerId = User::query()->whereKeyNot($actorId)->whereHas('roles', fn ($query) => $query->whereIn('name', ['Team Member', 'Managers']))->orderBy('id')->value('id')
            ?? User::query()->whereKeyNot($actorId)->orderBy('id')->value('id');
        $validatorId = User::query()->whereKeyNot($actorId)->whereKeyNot($preparerId)->whereHas('roles', fn ($query) => $query->where('name', 'MEAL'))->orderBy('id')->value('id');

        if ($preparerId === null) {
            return;
        }

        MonthlyReport::query()->where('prepared_by', $actorId)->update(['prepared_by' => $preparerId]);
        MonthlyReport::query()->where('submitted_by', $actorId)->update(['submitted_by' => $preparerId]);

        if ($validatorId !== null) {
            MonthlyReport::query()->where('validated_by', $actorId)->update(['validated_by' => $validatorId]);
        }
    }

    /**
     * Fake figures for one line: each share a month's slice of its target
     * (counts) or a 60–95 % ratio (percentages, averages); the line pools them
     * as SaveMonthlyReportLines does.
     */
    private function fillLine(MonthlyReportLine $line, DonorAttribution $attribution, int $actorId): void
    {
        $library = $line->libraryIndicator;
        $kind = MonthlyReportEntryKind::for($library);
        $multiplier = MonthlyReportEntryKind::multiplierFor($library);

        $figures = function (MonthlyReportLineShare $share) use ($kind, $multiplier): array {
            if ($kind === MonthlyReportEntryKind::Count) {
                $monthly = max(1, (float) $share->planIndicator->target / 12);

                return ['value' => round($monthly * mt_rand(60, 130) / 100), 'numerator' => null, 'denominator' => null, 'not_measured' => false];
            }

            $denominator = (float) mt_rand(20, 60);
            $numerator = round($denominator * mt_rand(60, 95) / 100);

            return ['value' => round($numerator / $denominator * $multiplier, 2), 'numerator' => $numerator, 'denominator' => $denominator, 'not_measured' => false];
        };

        if ($attribution === DonorAttribution::Shared) {
            $lineFigures = $figures($line->shares->first());
            $line->shares->each(fn (MonthlyReportLineShare $share) => $share->fill($lineFigures)->save());
        } else {
            $line->shares->each(fn (MonthlyReportLineShare $share) => $share->fill($figures($share))->save());
            $lineFigures = $kind === MonthlyReportEntryKind::Count
                ? ['value' => $line->shares->sum(fn (MonthlyReportLineShare $share): float => (float) $share->value), 'numerator' => null, 'denominator' => null, 'not_measured' => false]
                : $this->pooled($line->shares, $multiplier);
        }

        $line->fill([...$lineFigures, 'filled_by' => $actorId])->save();
    }

    /**
     * @param  Collection<int, MonthlyReportLineShare>  $shares
     * @return array{value: float, numerator: float, denominator: float, not_measured: bool}
     */
    private function pooled(Collection $shares, int $multiplier): array
    {
        $numerator = $shares->sum(fn (MonthlyReportLineShare $share): float => (float) $share->numerator);
        $denominator = $shares->sum(fn (MonthlyReportLineShare $share): float => (float) $share->denominator);

        return ['value' => round($numerator / $denominator * $multiplier, 2), 'numerator' => $numerator, 'denominator' => $denominator, 'not_measured' => false];
    }
}
