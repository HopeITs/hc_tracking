<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\HrProgram;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Traits\Localizable;

/**
 * How a monthly report is named (Y-03, D-76): by its template's place and
 * its proposals, never by a project. One place for the index, the show page,
 * the desk matrix, the alerts and the stored activity label, so two reports
 * of one place and month are always told apart the same way: by the
 * proposals each one covers.
 *
 * The place reads "City · Program · Center"; a city-wide place ends in
 * "Field work" (D-88) and a place whose center is not set yet in "Center not
 * set" (D-82).
 */
final class MonthlyReportNames
{
    use Localizable;

    /**
     * Names of many templates in a fixed number of queries: the proposals
     * (unless already loaded) and the HR cities. Programs and centers come
     * from the remembered HR lookups.
     *
     * @param  iterable<ReportTemplate>  $templates
     * @return array<int, array{place: string, proposals: list<array{id: int, slug: string, name: string, number: string|null}>, proposalsText: string}>
     */
    public function forTemplates(iterable $templates): array
    {
        $templates = new Collection(collect($templates)->unique('id')->values()->all());

        if ($templates->isEmpty()) {
            return [];
        }

        $templates->loadMissing(['proposals' => fn ($query) => $query->select(['proposals.id', 'proposals.slug', 'proposals.name', 'proposals.internal_project_number'])]);
        $cities = $this->cityNames($templates);
        $result = [];

        foreach ($templates as $template) {
            $proposals = array_values($template->proposals
                ->sortBy(fn (Proposal $proposal): string => mb_strtolower((string) $proposal->name))
                ->map(fn (Proposal $proposal): array => [
                    'id' => (int) $proposal->id,
                    'slug' => (string) $proposal->slug,
                    'name' => (string) $proposal->name,
                    'number' => $proposal->internal_project_number,
                ])
                ->all());

            $result[$template->id] = [
                'place' => $this->placeWith($template, $cities),
                'proposals' => $proposals,
                'proposalsText' => $this->joined(array_column($proposals, 'name')),
            ];
        }

        return $result;
    }

    /**
     * The place of one template split into its city, program and center, for
     * a header that labels each part instead of joining them with dots.
     *
     * @param  array<int, string>|null  $cities  city names already read (placePartsFor())
     * @return array{city: string|null, program: string|null, center: string|null}
     */
    public function placeParts(ReportTemplate $template, ?array $cities = null): array
    {
        $cities ??= $this->cityNames(new Collection([$template]));
        $center = match (true) {
            $template->city_wide => (string) __('monthly_reports.place_field_work'),
            $template->center_id !== null => HrCenter::remembered($template->center_id)->name,
            default => null,
        };

        return [
            'city' => $cities[$template->city_id] ?? null,
            'program' => HrProgram::nameOf($template->program_id) ?: null,
            'center' => filled($center) ? $center : null,
        ];
    }

    /**
     * placeParts() of many templates with one city query, keyed by template id.
     *
     * @param  iterable<ReportTemplate>  $templates
     * @return array<int, array{city: string|null, program: string|null, center: string|null}>
     */
    public function placePartsFor(iterable $templates): array
    {
        $templates = new Collection(collect($templates)->filter()->unique('id')->values()->all());

        if ($templates->isEmpty()) {
            return [];
        }

        $cities = $this->cityNames($templates);

        return $templates->mapWithKeys(fn (ReportTemplate $template): array => [$template->id => $this->placeParts($template, $cities)])->all();
    }

    /**
     * The place of one template in the interface language.
     */
    public function place(ReportTemplate $template): string
    {
        return $this->placeWith($template, $this->cityNames(new Collection([$template])));
    }

    /**
     * The label stored with an activity and shown in notifications, in
     * English like every stored label: "Proposals · Place · 2026-10".
     */
    public function label(ReportTemplate $template, CarbonInterface $period): string
    {
        return $this->withLocale('en', function () use ($template, $period): string {
            $names = $this->forTemplates([$template])[$template->id];

            return implode(' · ', array_filter([$names['proposalsText'], $names['place'], $period->format('Y-m')], fn (string $part): bool => $part !== ''));
        });
    }

    /**
     * @param  array<int, string>  $cities
     */
    private function placeWith(ReportTemplate $template, array $cities): string
    {
        $center = match (true) {
            $template->city_wide => (string) __('monthly_reports.place_field_work'),
            $template->center_id !== null => HrCenter::remembered($template->center_id)->name ?? (string) __('monthly_reports.place_center_not_set'),
            default => (string) __('monthly_reports.place_center_not_set'),
        };

        return implode(' · ', array_filter([
            $cities[$template->city_id] ?? '',
            HrProgram::nameOf($template->program_id),
            $center,
        ], fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  Collection<int, ReportTemplate>  $templates
     * @return array<int, string>
     */
    private function cityNames(Collection $templates): array
    {
        $ids = array_values(array_unique($templates->pluck('city_id')->map(fn (mixed $id): int => (int) $id)->all()));

        return HrCity::query()->whereKey($ids)->get()
            ->mapWithKeys(fn (HrCity $city): array => [(int) $city->id => $city->name])
            ->all();
    }

    /**
     * @param  list<string>  $names
     */
    private function joined(array $names): string
    {
        return implode(app()->getLocale() === 'ar' ? '، ' : ', ', $names);
    }
}
