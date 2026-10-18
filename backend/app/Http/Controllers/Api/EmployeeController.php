<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PayrollException;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeController extends ApiController
{
    protected function beforeDestroyMutation(Employee $employee): void
    {
        // The inherited CRUD hook runs only after its existing authorization and DataScope checks.
        if (Schema::hasColumn('payroll_employees', 'employee_id') && DB::table('payroll_employees')->where('employee_id', $employee->employee_id)->exists()) {
            throw new PayrollException('للعامل ملف مالي محفوظ؛ استخدم إيقافه وفق صلاحيات النظام بدل حذف هويته وتاريخه.', 'employee_financial_history_preserved', 409);
        }
    }

    protected function modelClass(): string
    {
        return Employee::class;
    }

    protected function resourceClass(): string
    {
        return EmployeeResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreEmployeeRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateEmployeeRequest::class;
    }
}
