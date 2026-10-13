<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HrEmployee;
use Illuminate\Http\JsonResponse;

class EmployeePickerOptionsController extends Controller
{
    /**
     * Every employee a person dropdown offers, as one small JSON list.
     *
     * The assignee and responsible dropdowns read this once per page load
     * (Alpine's employeeOptions store) instead of the server rendering every
     * employee into every dropdown on every Livewire response. The list is
     * sorted for the interface language, so the page asks with `?l=<locale>`
     * and the browser may reuse the answer for five minutes.
     */
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(HrEmployee::pickerLabels()->values())
            ->header('Cache-Control', 'private, max-age=300');
    }
}
