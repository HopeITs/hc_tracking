<?php

use App\Models\Account;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\FundingAgreementInstallment;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\HrContract;
use App\Models\HrDepartment;
use App\Models\HrEmployee;
use App\Models\HrJobLevel;
use App\Models\HrPosition;
use App\Models\HrProgram;
use App\Models\HrUser;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\KnownDevice;
use App\Models\Media;
use App\Models\MonthlyReportActivityEntry;
use App\Models\MonthlyReportAnswer;
use App\Models\MonthlyReportFile;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineBreakdown;
use App\Models\MonthlyReportLineShare;
use App\Models\MonthlyReportReopenRequest;
use App\Models\NotificationMute;
use App\Models\Permission;
use App\Models\PlanIndicator;
use App\Models\ProjectActivityStatus;
use App\Models\ProposalReviewer;
use App\Models\ProposalStatusNote;
use App\Models\ReportFile;
use App\Models\ReportTemplateLine;
use App\Models\ReportTemplateQuestion;
use App\Models\Result;
use App\Models\Role;
use App\Models\ScheduleBaseline;
use App\Models\ScheduleBaselineItem;
use App\Models\ScheduleDependency;
use App\Models\TaskAssignee;
use App\Models\TaskAttachment;
use App\Models\TransfersTracking;
use App\Models\TrustedDevice;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;

/*
| Every kind of record the application stores has a decided access rule:
| either a policy class, or an entry below naming the permission module
| that guards it (module => null for records only ever touched by their
| own user or never written here), with the reason. A new model added
| without either fails this test, so nobody forgets to decide.
*/

/**
 * Models without a policy: model class => [permission module or null, why].
 *
 * @return array<class-string<Model>, array{0: string|null, 1: string}>
 */
function modelsGuardedWithoutPolicy(): array
{
    return [
        Account::class => ['accounts', 'accounts.* permissions on the admin accounts page and the transfer forms.'],
        Activity::class => ['activity-log', 'The global log needs activity-log.view; a record\'s own Activity tab follows that record\'s page.'],
        Donor::class => ['donors', 'donors.* permissions (ManagesDonorForm).'],
        DonorProposal::class => ['funding-agreements', 'funding-agreements.* permissions (ManagesAgreementForm).'],
        FundingAgreementInstallment::class => ['funding-agreements', 'The payment schedule of an agreement: written only by SaveAgreementInstallments under funding-agreements.update (T09).'],
        HrCenter::class => [null, 'Read-only mirror of the HR database; never written here.'],
        HrCity::class => ['cities', 'cities.* permissions on the admin cities page.'],
        HrContract::class => [null, 'Read-only mirror of the HR database; never written here.'],
        HrDepartment::class => [null, 'Read-only mirror of the HR database; never written here.'],
        HrEmployee::class => [null, 'Read-only mirror of the HR database; never written here.'],
        HrJobLevel::class => [null, 'Read-only mirror of the HR database; never written here.'],
        HrPosition::class => [null, 'Read-only mirror of the HR database; never written here.'],
        HrProgram::class => [null, 'Read-only mirror of the HR database; never written here.'],
        HrUser::class => [null, 'Read-only mirror of the HR database; never written here.'],
        Indicator::class => ['indicators', 'indicators.* permissions on the indicators page.'],
        IndicatorProgress::class => ['indicators', 'Readings are recorded under indicators.* permissions.'],
        KnownDevice::class => [null, 'The signed-in user\'s own sign-in devices only.'],
        Media::class => [null, 'Files follow their owner\'s policy (project, proposal, milestone) and the download controllers check it.'],
        MonthlyReportActivityEntry::class => ['reports', 'Part of its monthly report (MonthlyReportPolicy::update); written only by SaveMonthlyReportActivities.'],
        MonthlyReportAnswer::class => ['reports', 'Part of its monthly report (MonthlyReportPolicy::update); written only by SaveMonthlyReportAnswers.'],
        MonthlyReportFile::class => ['reports', 'Part of its monthly report (MonthlyReportPolicy::update, fillMealLines); loaded through the report and downloaded through T17B\'s protected route.'],
        MonthlyReportLine::class => ['reports', 'Part of its monthly report (MonthlyReportPolicy::update, fillMealLines); loaded through the report.'],
        MonthlyReportLineBreakdown::class => ['reports', 'Part of its monthly report line (MonthlyReportPolicy::update, fillMealLines); loaded through the line.'],
        MonthlyReportLineShare::class => ['reports', 'Part of its monthly report line (MonthlyReportPolicy::update, fillMealLines); loaded through the line.'],
        MonthlyReportReopenRequest::class => ['reports', 'Raised and decided under MonthlyReportPolicy::requestReopen and decideReopen; never deleted.'],
        NotificationMute::class => [null, 'The signed-in user\'s own notification preferences only.'],
        Permission::class => ['access-management', 'access-management.* permissions and UserPolicy::grantPermission.'],
        PlanIndicator::class => ['targets', 'targets.* permissions on the targets page.'],
        ProjectActivityStatus::class => ['reports', 'Written only when a monthly report is validated or reopened (MonthlyReportPolicy::validate, decideReopen).'],
        ProposalStatusNote::class => ['proposals', 'Written only through a proposal status change (ProposalPolicy::update).'],
        ProposalReviewer::class => ['proposals', 'Written only by the review actions under ProposalPolicy::transition, manageReviewers and markReviewed (T07).'],
        ReportFile::class => ['reports', 'Follows its report (ReportPolicy); the download controllers check it.'],
        ReportTemplateLine::class => ['report-templates', 'Part of a report template: written only by the report template actions under ReportTemplatePolicy::manage (T33).'],
        ReportTemplateQuestion::class => ['report-templates', 'Part of a report template: written only by the report template actions under ReportTemplatePolicy::manage (T33).'],
        Result::class => ['results', 'results.* permissions on the results page.'],
        Role::class => ['access-management', 'UserPolicy::manageRole, editRolePermissions and assignRole.'],
        ScheduleBaseline::class => ['projects', 'Part of a project schedule (ProjectPolicy::update).'],
        ScheduleBaselineItem::class => ['projects', 'Part of a project schedule (ProjectPolicy::update).'],
        ScheduleDependency::class => ['projects', 'Part of a project schedule (ProjectPolicy::update).'],
        TaskAssignee::class => ['tasks', 'Follows its task (TaskPolicy).'],
        TaskAttachment::class => ['tasks', 'Follows its task (TaskPolicy); the download controllers check it.'],
        TransfersTracking::class => ['transfers', 'transfers.* permissions (ManagesTransferForm).'],
        TrustedDevice::class => [null, 'The signed-in user\'s own trusted browsers only.'],
        UserNotification::class => [null, 'Always read through forUser(): a user only ever reaches their own inbox.'],
    ];
}

/**
 * Every concrete Eloquent model in app/Models.
 *
 * @return list<class-string<Model>>
 */
function everyApplicationModel(): array
{
    return collect(File::files(app_path('Models')))
        ->map(fn (SplFileInfo $file): string => 'App\\Models\\'.$file->getBasename('.php'))
        ->filter(fn (string $class): bool => class_exists($class)
            && is_subclass_of($class, Model::class)
            && ! (new ReflectionClass($class))->isAbstract())
        ->values()
        ->all();
}

test('every model has a policy or a recorded access rule', function () {
    $undecided = collect(everyApplicationModel())
        ->filter(fn (string $class): bool => Gate::getPolicyFor($class) === null && ! array_key_exists($class, modelsGuardedWithoutPolicy()))
        ->values()
        ->all();

    expect($undecided)->toBe([], "These models have no policy and no entry in modelsGuardedWithoutPolicy():\n".implode("\n", $undecided));
});

test('a model given a policy is taken off the list', function () {
    $both = collect(array_keys(modelsGuardedWithoutPolicy()))
        ->filter(fn (string $class): bool => class_exists($class) && Gate::getPolicyFor($class) !== null)
        ->values()
        ->all();

    expect($both)->toBe([]);
});

test('every listed model exists and every named module is in the permission catalog', function () {
    foreach (modelsGuardedWithoutPolicy() as $class => [$module]) {
        expect(class_exists($class))->toBeTrue("{$class} no longer exists");

        if ($module !== null) {
            expect(config('access-control.groups'))->toHaveKey($module);
        }
    }
});
