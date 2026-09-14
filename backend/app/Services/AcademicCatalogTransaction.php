<?php

namespace App\Services;

use App\Exceptions\AcademicCatalogException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Database triggers advance this epoch for ALL writers, including query-builder writes.
 * This is deliberately a conservative catalog-wide revision, not a hash or a timestamp.
 */
final class AcademicCatalogTransaction
{
    public function revision(bool $lock = false): string
    {
        if (!Schema::hasTable('academic_catalog_control') || !Schema::hasColumns('academic_catalog_control', ['control_id', 'schema_version', 'revision', 'is_ready'])) $this->unavailable();
        $query = DB::table('academic_catalog_control')->where('control_id', 1);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$row || (int) $row->schema_version !== 1 || !(bool) $row->is_ready) $this->unavailable();
        return (string) $row->revision;
    }

    public function run(callable $work, ?string $expected = null): mixed
    {
        try {
            return DB::transaction(function () use ($work, $expected) {
                $revision = $this->revision(true); // First lock; subsequent reads see the committed epoch.
                if ($expected !== null && !hash_equals($revision, $expected)) {
                    throw new AcademicCatalogException('تغيرت البيانات؛ احتُفظ بنموذجك. أعد تحميل النسخة الحالية وراجع التغييرات.', 'academic_catalog_stale');
                }
                return $work();
            }); // Never replay a caller's mutation automatically.
        } catch (QueryException|DeadlockException $e) {
            $message = $e->getMessage();
            foreach (['academic_plan_schema_not_ready', 'academic_plan_locked', 'academic_plan_context_invalid',
                'academic_plan_initialization_incomplete', 'academic_program_archived', 'academic_plan_program_transfer_required',
                'academic_plan_record_immutable', 'academic_plan_assignment_immutable', 'academic_plan_group_mismatch',
                'academic_plan_initialization_required', 'academic_plan_program_identity_locked', 'academic_plan_transition_invalid',
                'academic_plan_approval_required', 'academic_plan_event_immutable'] as $code) {
                if (str_contains($message, $code)) throw new \App\Exceptions\AcademicPlanException(
                    'تعذر إتمام العملية بسبب حالة الخطة أو ارتباطاتها؛ أعد تحميل المعاينة وراجع سبب المنع.', $code,
                    $code === 'academic_plan_schema_not_ready' ? 503 : 409);
            }
            if (str_contains($message, 'academic_catalog_history_locked')) {
                throw new AcademicCatalogException('هذه البيانات مرتبطة بتاريخ أكاديمي؛ يسمح بالتصحيح النصي فقط.', 'academic_catalog_history_locked');
            }
            if (str_contains($message, 'academic_catalog_schema_not_ready')) $this->unavailable();
            // Laravel wraps nested transaction deadlocks separately from QueryException.
            // Preserve the same controlled conflict; never retry a mutation here.
            if ($e instanceof DeadlockException || in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw new AcademicCatalogException('تزامنت العملية مع تعديل آخر؛ أعد تحميل البيانات وراجعها قبل المحاولة.', 'academic_catalog_stale');
            }
            if (str_contains($message, 'courses.course_code') || ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains($message, 'course_code'))) {
                throw ValidationException::withMessages(['course_code' => 'رمز المادة مستخدم بالفعل؛ أدخل رمزًا آخر']);
            }
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1451, 1452], true) || str_contains($message, 'FOREIGN KEY constraint failed')) {
                throw new AcademicCatalogException('توجد ارتباطات أكاديمية مانعة أو تغيرت إحدى العلاقات. حدّث المعاينة.', 'academic_catalog_relationship_conflict');
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains($message, 'UNIQUE constraint failed')) {
                throw ValidationException::withMessages(['relationship' => 'يوجد رمز أو ارتباط مكرر؛ راجع البيانات قبل الحفظ.']);
            }
            throw $e;
        }
    }

    /** Non-locking read with an epoch fence outside the transaction snapshot.
     * Safe under both REPEATABLE READ and READ COMMITTED: a concurrent commit
     * invalidates the entire response rather than returning mixed data/revision.
     * No automatic retry, and no SELECT FOR UPDATE on ordinary read endpoints.
     */
    public function snapshot(callable $read): mixed
    {
        // Mutation response projections already run under the writer's control lock.
        if (DB::transactionLevel() > 0) return $this->run($read);
        $before = $this->revision();
        $result = DB::transaction($read);
        if (!hash_equals($before, $this->revision())) {
            throw new AcademicCatalogException('تغيرت البيانات أثناء تحميلها؛ أعد التحميل.', 'academic_catalog_stale');
        }
        return $result;
    }

    public function assertAcyclic(): void
    {
        $edges = [];
        foreach (DB::table('course_prerequisites')->get(['course_id', 'prerequisite_course_id']) as $edge) {
            $edges[$edge->course_id][] = $edge->prerequisite_course_id;
        }
        $done = []; $path = [];
        $visit = function ($id) use (&$visit, &$done, &$path, $edges) {
            if (isset($path[$id])) throw ValidationException::withMessages(['prerequisites' => 'لا يسمح بمتطلب سابق ذاتي أو دورة في المتطلبات السابقة.']);
            if (isset($done[$id])) return;
            $path[$id] = true;
            foreach ($edges[$id] ?? [] as $next) $visit($next);
            unset($path[$id]); $done[$id] = true;
        };
        foreach (array_keys($edges) as $id) $visit($id);
    }

    private function unavailable(): never
    {
        throw new AcademicCatalogException('مخطط حماية دليل المواد غير جاهز؛ يلزم استكمال الحزمة اليدوية.', 'academic_catalog_schema_not_ready', 503);
    }
}
