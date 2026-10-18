<?php

namespace App\Services;

use App\Exceptions\OfferingProposalException;
use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\FacultyMember;
use App\Models\OfferingProposal;
use App\Models\OfferingProposalItem;
use App\Models\ProgramCourse;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OfferingProposalService
{
    private const MANDATORY_TYPES = ['mandatory', 'required'];

    public function preview(int $academicYearId, int $semesterId, int $departmentId): array
    {
        $this->assertTerm($academicYearId, $semesterId, $departmentId);

        return [
            'academic_year_id' => $academicYearId,
            'semester_id' => $semesterId,
            'department_id' => $departmentId,
            'courses' => $this->planCourses($departmentId)->map(fn (ProgramCourse $plan) => $this->formatPlanCourse($plan))->values()->all(),
        ];
    }

    public function submit(User $dean, array $data): array
    {
        return DB::transaction(function () use ($dean, $data): array {
            $academicYearId = (int) $data['academic_year_id'];
            $semesterId = (int) $data['semester_id'];
            $departmentId = (int) $data['department_id'];
            $this->assertTerm($academicYearId, $semesterId, $departmentId);

            $pending = OfferingProposal::query()
                ->where('academic_year_id', $academicYearId)
                ->where('semester_id', $semesterId)
                ->where('department_id', $departmentId)
                ->where('status', OfferingProposal::STATUS_SUBMITTED)
                ->exists();

            if ($pending) {
                throw new OfferingProposalException('يوجد طرح مرسل لهذا القسم والفصل بانتظار موافقة النائب العلمي.');
            }

            $plan = $this->planCourses($departmentId);
            $facultyIds = $this->facultyAssignments($data['mandatory_faculty'] ?? []);
            $electives = $this->selectedElectives($plan, $data['electives'] ?? []);
            $mandatory = $plan->whereIn('course_type', self::MANDATORY_TYPES);

            if ($mandatory->isEmpty() && $electives === []) {
                throw new OfferingProposalException('لا توجد مواد لإرسالها في هذا القسم.');
            }

            $proposal = OfferingProposal::query()->create([
                'academic_year_id' => $academicYearId,
                'semester_id' => $semesterId,
                'department_id' => $departmentId,
                'submitted_by_user_id' => $dean->user_id,
                'status' => OfferingProposal::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);

            foreach ($mandatory as $course) {
                $facultyId = $facultyIds[$this->planKey($course)] ?? null;
                $this->assertFaculty($facultyId);
                $exception = $facultyId === null;

                OfferingProposalItem::query()->create([
                    'offering_proposal_id' => $proposal->offering_proposal_id,
                    'course_id' => $course->course_id,
                    'academic_program_id' => $course->academic_program_id,
                    'course_type' => 'mandatory',
                    'faculty_member_id' => $facultyId,
                    'instructor_exception' => $exception,
                    'exception_reason' => $exception ? OfferingProposalItem::EXCEPTION_REASON : null,
                    'capacity' => 40,
                ]);
            }

            foreach ($electives as $elective) {
                OfferingProposalItem::query()->create([
                    'offering_proposal_id' => $proposal->offering_proposal_id,
                    'course_id' => $elective['course']->course_id,
                    'academic_program_id' => $elective['course']->academic_program_id,
                    'course_type' => 'elective',
                    'faculty_member_id' => $elective['faculty_member_id'],
                    'instructor_exception' => false,
                    'exception_reason' => null,
                    'capacity' => $elective['capacity'],
                ]);
            }

            return $this->formatProposal($proposal->fresh()->load($this->relations()));
        });
    }

    public function listFor(?string $status = null): array
    {
        $query = OfferingProposal::query()->with($this->relations())->latest('offering_proposal_id');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->get()->map(fn (OfferingProposal $proposal) => $this->formatProposal($proposal))->values()->all();
    }

    public function show(int $proposalId): array
    {
        $proposal = OfferingProposal::query()->with($this->relations())->findOrFail($proposalId);

        return $this->formatProposal($proposal);
    }

    public function approve(User $vicePresident, int $proposalId, ?string $note = null): array
    {
        return DB::transaction(function () use ($vicePresident, $proposalId, $note): array {
            $proposal = OfferingProposal::query()->with('items')->lockForUpdate()->findOrFail($proposalId);
            $this->assertSubmitted($proposal);

            foreach ($proposal->items as $item) {
                $this->openOffering($proposal, $item);
            }

            $proposal->update([
                'status' => OfferingProposal::STATUS_APPROVED,
                'decided_by_user_id' => $vicePresident->user_id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            return $this->formatProposal($proposal->fresh()->load($this->relations()));
        });
    }

    public function reject(User $vicePresident, int $proposalId, ?string $note = null): array
    {
        return DB::transaction(function () use ($vicePresident, $proposalId, $note): array {
            $proposal = OfferingProposal::query()->lockForUpdate()->findOrFail($proposalId);
            $this->assertSubmitted($proposal);

            $proposal->update([
                'status' => OfferingProposal::STATUS_REJECTED,
                'decided_by_user_id' => $vicePresident->user_id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            return $this->formatProposal($proposal->fresh()->load($this->relations()));
        });
    }

    private function openOffering(OfferingProposal $proposal, OfferingProposalItem $item): void
    {
        $existing = CourseOffering::query()
            ->where('course_id', $item->course_id)
            ->where('academic_year_id', $proposal->academic_year_id)
            ->where('semester_id', $proposal->semester_id)
            ->where('department_id', $proposal->department_id)
            ->where('academic_program_id', $item->academic_program_id)
            ->first();

        if ($existing !== null) {
            $existing->update([
                'status' => 'open',
                'faculty_member_id' => $item->faculty_member_id ?? $existing->faculty_member_id,
            ]);

            return;
        }

        CourseOffering::query()->create([
            'course_id' => $item->course_id,
            'academic_year_id' => $proposal->academic_year_id,
            'semester_id' => $proposal->semester_id,
            'department_id' => $proposal->department_id,
            'academic_program_id' => $item->academic_program_id,
            'faculty_member_id' => $item->faculty_member_id,
            'capacity' => $item->capacity,
            'available_seats' => $item->capacity,
            'status' => 'open',
        ]);
    }

    private function planCourses(int $departmentId): Collection
    {
        return ProgramCourse::query()
            ->with(['course', 'academicProgram'])
            ->where('is_active', true)
            ->whereIn('course_type', [...self::MANDATORY_TYPES, 'elective'])
            ->whereHas('academicProgram', fn ($query) => $query
                ->where('department_id', $departmentId)
                ->where('is_active', true))
            ->orderBy('program_course_id')
            ->get();
    }

    private function selectedElectives(Collection $plan, array $requested): array
    {
        $electives = $plan->where('course_type', 'elective')->keyBy(fn (ProgramCourse $course) => $this->planKey($course));
        $selected = [];

        foreach ($requested as $row) {
            $key = ((int) $row['course_id']).'-'.((int) $row['academic_program_id']);
            $course = $electives->get($key);

            if ($course === null) {
                throw new OfferingProposalException('إحدى المواد الاختيارية ليست ضمن خطة هذا القسم.');
            }

            $facultyId = isset($row['faculty_member_id']) ? (int) $row['faculty_member_id'] : null;
            if ($facultyId === null) {
                throw new OfferingProposalException('المادة الاختيارية تحتاج مدرساً قبل إرسالها. الاستثناء التلقائي للمواد الإجبارية فقط.');
            }

            $this->assertFaculty($facultyId);
            $selected[$key] = [
                'course' => $course,
                'faculty_member_id' => $facultyId,
                'capacity' => max(1, (int) ($row['capacity'] ?? 40)),
            ];
        }

        return array_values($selected);
    }

    private function facultyAssignments(array $rows): array
    {
        $assignments = [];

        foreach ($rows as $row) {
            if (! isset($row['faculty_member_id']) || $row['faculty_member_id'] === null || $row['faculty_member_id'] === '') {
                continue;
            }

            $assignments[((int) $row['course_id']).'-'.((int) $row['academic_program_id'])] = (int) $row['faculty_member_id'];
        }

        return $assignments;
    }

    private function assertFaculty(?int $facultyId): void
    {
        if ($facultyId === null) {
            return;
        }

        $exists = FacultyMember::query()->whereKey($facultyId)->where('is_active', true)->exists();
        if (! $exists) {
            throw new OfferingProposalException('المدرس المحدد غير موجود أو غير نشط.');
        }
    }

    private function assertTerm(int $academicYearId, int $semesterId, int $departmentId): void
    {
        AcademicYear::query()->findOrFail($academicYearId);
        Semester::query()->findOrFail($semesterId);
        Department::query()->findOrFail($departmentId);
    }

    private function assertSubmitted(OfferingProposal $proposal): void
    {
        if ($proposal->status !== OfferingProposal::STATUS_SUBMITTED) {
            throw new OfferingProposalException('يمكن البت فقط في طرح مرسل ولم يُعتمد بعد.');
        }
    }

    private function planKey(ProgramCourse $course): string
    {
        return $course->course_id.'-'.$course->academic_program_id;
    }

    private function relations(): array
    {
        return [
            'department',
            'academicYear',
            'semester',
            'submittedBy',
            'items.course',
            'items.academicProgram',
            'items.facultyMember',
        ];
    }

    private function formatPlanCourse(ProgramCourse $plan): array
    {
        $mandatory = in_array($plan->course_type, self::MANDATORY_TYPES, true);

        return [
            'course_id' => $plan->course_id,
            'course_code' => $plan->course?->course_code,
            'course_name' => $plan->course?->course_name,
            'credit_hours' => $plan->course?->credit_hours,
            'academic_program_id' => $plan->academic_program_id,
            'program_name' => $plan->academicProgram?->program_name,
            'course_type' => $mandatory ? 'mandatory' : 'elective',
            'included_automatically' => $mandatory,
            'instructor_exception' => $mandatory,
            'exception_reason' => $mandatory ? OfferingProposalItem::EXCEPTION_REASON : null,
        ];
    }

    private function formatProposal(OfferingProposal $proposal): array
    {
        return [
            'offering_proposal_id' => $proposal->offering_proposal_id,
            'status' => $proposal->status,
            'submitted_at' => $proposal->submitted_at,
            'decided_at' => $proposal->decided_at,
            'decision_note' => $proposal->decision_note,
            'academic_year' => [
                'academic_year_id' => $proposal->academic_year_id,
                'year_name' => $proposal->academicYear?->year_name,
            ],
            'semester' => [
                'semester_id' => $proposal->semester_id,
                'semester_name' => $proposal->semester?->semester_name,
            ],
            'department' => [
                'department_id' => $proposal->department_id,
                'department_name' => $proposal->department?->department_name,
            ],
            'submitted_by' => $proposal->submittedBy ? [
                'user_id' => $proposal->submittedBy->user_id,
                'username' => $proposal->submittedBy->username,
            ] : null,
            'items' => $proposal->items->map(fn (OfferingProposalItem $item) => [
                'offering_proposal_item_id' => $item->offering_proposal_item_id,
                'course_id' => $item->course_id,
                'course_code' => $item->course?->course_code,
                'course_name' => $item->course?->course_name,
                'credit_hours' => $item->course?->credit_hours,
                'academic_program_id' => $item->academic_program_id,
                'program_name' => $item->academicProgram?->program_name,
                'course_type' => $item->course_type,
                'faculty_member_id' => $item->faculty_member_id,
                'instructor_exception' => (bool) $item->instructor_exception,
                'exception_reason' => $item->exception_reason,
                'capacity' => $item->capacity,
            ])->values()->all(),
        ];
    }
}
