<?php

namespace App\Services;

use App\Exceptions\AcademicPlanException;
use App\Models\{AcademicPlanVersion, AcademicProgram, ProgramCourse, Student};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\{DB, Schema};

/** Explicit immutable context. No ambient/static current-plan state and no student default fallback. */
final readonly class AcademicPlanContext
{
    public function __construct(public int $programId, public ?int $versionId, public string $policy = 'legacy', public ?int $requiredHours = null) {}

    public static function installed(): bool
    {
        return Schema::hasTable('academic_plan_control') || Schema::hasColumn('academic_programs', 'plan_state');
    }

    public static function assertReady(): void
    {
        if (!self::installed() || !Schema::hasColumns('academic_plan_control', ['control_id', 'schema_version', 'is_ready'])
            || !DB::table('academic_plan_control')->where('control_id', 1)->where('schema_version', 1)->where('is_ready', 1)->exists()) {
            throw new AcademicPlanException('إدارة الخطط غير جاهزة؛ يلزم استكمال التهيئة التقنية.', 'academic_plan_schema_not_ready', 503);
        }
    }

    /** Outer entry for existing student-locking writers. Preserve their internal lock order.
     * Before installation this is exactly the legacy transaction implementation.
     * Installed workflows acquire the common catalog epoch BEFORE student/request/offering locks.
     */
    public static function transaction(callable $work, int $attempts = 1): mixed
    {
        if (!self::installed()) return DB::transaction($work, $attempts);
        self::assertReady();
        return app(AcademicCatalogTransaction::class)->run($work);
    }

    public static function forStudent(Student $student): self
    {
        $programId = (int) $student->academic_program_id;
        if (!self::installed()) return new self($programId, null);
        self::assertReady();
        $program = AcademicProgram::query()->findOrFail($programId);
        $links = DB::table('student_academic_plan_assignments')->where('student_id', $student->getKey())->where('current_slot', 1)->get();
        if ($links->isEmpty() && $program->plan_state === 'legacy') return new self($programId, null);
        // Preparing with no fixed reference still uses exactly the legacy curriculum for existing students.
        if ($links->isEmpty() && $program->plan_state === 'preparing'
            && !AcademicPlanVersion::where('academic_program_id', $programId)->where('status', 'transitional')->exists()) return new self($programId, null);
        if ($links->count() !== 1 || (int) $links->sole()->academic_program_id !== $programId) self::invalid();
        return self::fixed($programId, (int) $links->sole()->academic_plan_version_id);
    }

    public static function forProgram(int $programId): self
    {
        if (!self::installed()) return new self($programId, null);
        self::assertReady();
        $program = AcademicProgram::findOrFail($programId);
        if ($program->plan_state === 'legacy') return new self($programId, null);
        if ($program->default_academic_plan_version_id !== null) return self::fixed($programId, (int) $program->default_academic_plan_version_id);
        $versions = AcademicPlanVersion::where('academic_program_id', $programId)->where('status', 'transitional')->get();
        if ($versions->isEmpty() && $program->plan_state === 'preparing') return new self($programId, null);
        if ($versions->count() !== 1) self::invalid();
        return self::fixed($programId, (int) $versions->sole()->getKey());
    }

    /** Two bulk reads for non-student catalog projections; never used to assign a student. */
    public static function constrainProgramProjection(Builder $query, array $programIds): Builder
    {
        if (!self::installed()) return $query;
        self::assertReady();
        $programs = AcademicProgram::whereIn('academic_program_id', $programIds)->get();
        $versions = AcademicPlanVersion::whereIn('academic_program_id', $programIds)->whereIn('status', ['transitional', 'approved'])->get();
        $contexts = [];
        foreach ($programs as $program) {
            $versionId = null;
            if ($program->plan_state !== 'legacy') {
                $matches = $program->default_academic_plan_version_id !== null
                    ? $versions->where('academic_plan_version_id', $program->default_academic_plan_version_id)->where('status', 'approved')
                    : $versions->where('academic_program_id', $program->getKey())->where('status', 'transitional');
                if ($matches->count() > 1 || ($matches->isEmpty() && $program->plan_state === 'ready')) self::invalid();
                $version = $matches->first();
                if ($version !== null && ((int) $version->academic_program_id !== (int) $program->getKey() || !$version->fixed_at)) self::invalid();
                $versionId = $version?->getKey();
            }
            $contexts[] = [(int) $program->getKey(), $versionId];
        }
        return $query->where(function ($q) use ($contexts) {
            $q->whereRaw('1=0');
            foreach ($contexts as [$program, $version]) $q->orWhere(fn ($part) => $part->where('academic_program_id', $program)->where('academic_plan_version_id', $version));
        });
    }

    public static function fixed(int $programId, int $versionId): self
    {
        $version = AcademicPlanVersion::where('academic_program_id', $programId)->find($versionId);
        if (!$version || !in_array($version->status, ['approved', 'transitional'], true) || !$version->fixed_at) self::invalid();
        return new self($programId, $versionId, $version->calculation_policy, $version->total_credit_hours);
    }

    /** Used only by authorized draft validation/preview, never as an assignment. */
    public static function forVersion(AcademicPlanVersion $version): self
    {
        return new self((int) $version->academic_program_id, (int) $version->getKey(), $version->calculation_policy, $version->total_credit_hours);
    }

    public function constrain(Builder $query): Builder
    {
        $query->where($query->getModel()->qualifyColumn('academic_program_id'), $this->programId);
        if (self::installed()) $query->where($query->getModel()->qualifyColumn('academic_plan_version_id'), $this->versionId);
        return $query;
    }

    public function courses(): Builder
    {
        return $this->constrain(ProgramCourse::query());
    }

    public function membership(int $courseId): ?ProgramCourse
    {
        $rows = $this->courses()->where('course_id', $courseId)->where('is_active', true)->get();
        if ($rows->count() > 1) self::invalid();
        return $rows->first();
    }

    /** A proposal names its source membership; fixed historical plans remain usable for their students. */
    public static function forOfferingSource(int $programId, ?ProgramCourse $source = null): self
    {
        if (!self::installed()) return new self($programId, null);
        self::assertReady();
        if ($source === null) return self::forProgram($programId);
        if ((int) $source->academic_program_id !== $programId) self::invalid();
        if ($source->academic_plan_version_id !== null) return self::fixed($programId, (int) $source->academic_plan_version_id);
        $context = self::forProgram($programId);
        if ($context->versionId !== null) self::invalid();
        return $context;
    }

    private static function invalid(): never
    {
        throw AcademicPlanException::conflict('academic_plan_context_invalid', 'تعذر تحديد خطة الطالب أو ارتباط المادة بأمان؛ راجع إعداد البرنامج.');
    }
}
