<?php

namespace App\Models\Concerns;

use App\Exceptions\AcademicPlanException;
use App\Models\Student;
use App\Services\AcademicPlanContext;
use Illuminate\Support\Facades\DB;

/** Server-owned context, never mass assignable or rebound when a student moves plans. */
trait HasAcademicPlanRecord
{
    protected static function bootHasAcademicPlanRecord(): void
    {
        static::creating(function ($record) {
            if (!AcademicPlanContext::installed()) return;
            if (DB::transactionLevel() < 1) throw AcademicPlanException::conflict('academic_plan_transaction_required', 'حفظ سياق الخطة يتطلب معاملة ذرية.');
            $student = Student::withTrashed()->findOrFail($record->student_id);
            $record->academic_plan_version_id = AcademicPlanContext::forStudent($student)->versionId;
        });
        static::updating(function ($record) {
            if (AcademicPlanContext::installed() && $record->isDirty('academic_plan_version_id')) {
                throw AcademicPlanException::conflict('academic_plan_record_immutable', 'مرجع خطة العملية محفوظ ولا يُعاد ربطه ضمنيًا.');
            }
        });
    }
}
