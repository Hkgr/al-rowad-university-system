<?php

namespace App\Services;

use App\Models\User;
use App\Support\AdministrativeGovernanceException as Failure;
use App\Support\HrOffice;
use App\Support\OwnerPortal;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Office 711. No accounts, teaching assignments, dean appointments or financial amounts are issued here. */
final class HrOfficeService
{
    private const TABLES = ['hr_staffing_needs', 'hr_staffing_need_items', 'hr_candidates', 'hr_interviews', 'hr_interview_participants', 'hr_relationship_requests', 'hr_employment_relationships', 'hr_workforce_events'];

    private const REVISION = ['required', 'integer', 'min:1'];

    public function __construct(private readonly HrOffice $access) {}

    private function atomic(Closure $work): mixed
    {
        try {
            return DB::transaction($work, 1); // Never replay a user's write automatically.
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '40001' || in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw Failure::conflict('hr_concurrent_change', 'تعارضت العملية مع تعديل آخر. لم تُحفظ تغييرات جزئية؛ راجع الحالة قبل طلب جديد.');
            }
            throw $e;
        }
    }

    public function ready(): bool
    {
        foreach (self::TABLES as $t) {
            if (! Schema::hasTable($t)) {
                return false;
            }
        }
        foreach (['hr_staffing_needs' => ['title', 'body', 'organizational_unit_id', 'college_id', 'revision', 'status'], 'hr_staffing_need_items' => ['need_id', 'job_title', 'quantity', 'is_active'], 'hr_candidates' => ['need_item_id', 'employee_id', 'revision', 'status'], 'hr_interviews' => ['candidate_id', 'scheduled_at', 'revision', 'evaluation', 'result'], 'hr_interview_participants' => ['interview_id', 'employee_id'], 'hr_relationship_requests' => ['target_key', 'current_slot', 'proposal', 'context', 'revision', 'submission_version', 'materialized_at'], 'hr_employment_relationships' => ['employee_id', 'request_id', 'need_item_id', 'source', 'body', 'starts_on', 'ends_on', 'superseded_from', 'predecessor_id'], 'hr_workforce_events' => ['subject_type', 'subject_id', 'actor_user_id', 'details']] as $table => $columns) {
            if (! Schema::hasColumns($table, $columns)) {
                return false;
            }
        }

        return Schema::hasColumns('employees', ['hr_revision', 'hr_body']);
    }

    private function requireReady(): void
    {
        if (! $this->ready()) {
            throw new Failure('مخطط الموارد البشرية غير جاهز. تبقى الخدمات السابقة مستقلة.', 503, 'hr_schema_not_ready');
        }
    }

    private function input(array $data, array $rules): array
    {
        if (array_diff(array_keys($data), array_keys($rules))) {
            throw Failure::invalid('hr_invalid_fields', 'يتضمن الطلب حقولًا غير مسموحة.');
        }

        return Validator::make($data, $rules)->validate();
    }

    private function revision(object $row, int $expected, string $key = 'revision'): void
    {
        if ((int) $row->$key !== $expected) {
            throw Failure::conflict('hr_stale', 'تغيرت البيانات. راجع النسخة الحالية قبل تأكيد طلب جديد.');
        }
    }

    private function row(string $table, int $id, bool $lock = true): object
    {
        $q = DB::table($table)->where($table === 'employees' ? 'employee_id' : 'id', $id);

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? throw Failure::denied('hr_not_found', 'العنصر غير موجود أو غير متاح.');
    }

    private function event(User $actor, string $type, int $id, string $action, array $details): void
    {
        DB::table('hr_workforce_events')->insert(['subject_type' => $type, 'subject_id' => $id, 'action' => $action, 'actor_user_id' => $actor->user_id, 'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }

    public function options(User $actor): array
    {
        $this->access->authorize($actor, HrOffice::VIEW);
        $units = $this->access->scopeUnits(DB::table('organizational_units'), $actor, 'organizational_unit_id');
        $colleges = $this->access->scopeUnits(DB::table('colleges'), $actor, 'organizational_unit_id');

        return ['schema_ready' => $this->ready(), 'colleges' => $colleges->orderBy('college_name')->get(['college_id', 'college_name', 'organizational_unit_id', 'is_active']),
            'units' => $units->orderBy('unit_name')->get(['organizational_unit_id', 'unit_name', 'unit_code', 'is_active']),
            'positions' => DB::table('positions')->orderBy('position_title')->get(['position_id', 'position_title', 'position_code']),
            'employee_types' => DB::table('employee_types')->orderBy('employee_type_id')->get(['employee_type_id', 'type_name', 'type_code']),
            'capabilities' => collect(HrOffice::PERMISSIONS)->map(fn ($label, $code) => $this->access->allows($actor, $code))->all()];
    }

    private function employees(User $actor): Builder
    {
        $q = DB::table('employees as e');
        if ($actor->isSuperAdmin() || app(DataScopeService::class)->hasActualUniversityScope($actor)) {
            return $q;
        }
        $units = $this->access->collegeUnitIds($actor);

        return $q->where(fn ($q) => $q->whereIn('e.organizational_unit_id', $units)
            ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('employee_unit_assignments as a')->whereColumn('a.employee_id', 'e.employee_id')->whereIn('a.organizational_unit_id', $units)->where('a.is_active', true)->where('a.start_date', '<=', now()->toDateString())->where(fn ($q) => $q->whereNull('a.end_date')->orWhere('a.end_date', '>=', now()->toDateString())))
            ->orWhereExists(fn ($q) => $this->effectiveQuery($q->selectRaw('1')->from('hr_employment_relationships as r')->whereColumn('r.employee_id', 'e.employee_id')->whereIn('r.organizational_unit_id', $units))));
    }

    public function listing(User $actor, string $section, array $input): array
    {
        $this->access->authorize($actor, HrOffice::VIEW);
        $this->requireReady();
        $f = $this->input($input, ['q' => 'nullable|string|max:120', 'body' => 'nullable|in:educational,administrative', 'status' => 'nullable|string|max:30', 'college_id' => 'nullable|integer|min:1', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $employee = in_array($section, ['workers', 'classification'], true);
        $table = match ($section) {
            'needs' => 'hr_staffing_needs', 'candidates' => 'hr_candidates', 'requests' => 'hr_relationship_requests', 'relationships' => 'hr_employment_relationships', 'workers', 'classification' => 'employees', default => throw Failure::invalid('hr_section_invalid', 'القسم غير صالح.')
        };
        if ($employee) {
            $q = $this->employees($actor)->select('e.employee_id as id', 'e.employee_number', 'e.first_name', 'e.last_name', 'e.hr_body', 'e.hr_revision', 'e.organizational_unit_id');
            if ($section === 'classification') {
                $q->where(fn ($q) => $q->whereNull('e.hr_body')->orWhereNotExists(fn ($q) => $q->selectRaw('1')->from('hr_employment_relationships as r')->whereColumn('r.employee_id', 'e.employee_id')));
            }
            if (! empty($f['body'])) {
                $q->where(fn ($q) => $q
                    ->whereExists(fn ($r) => $this->effectiveQuery($r->selectRaw('1')->from('hr_employment_relationships')->whereColumn('employee_id', 'e.employee_id')->where('body', $f['body'])))
                    ->orWhere(fn ($q) => $q->where('e.hr_body', $f['body'])->whereNotExists(fn ($r) => $this->effectiveQuery($r->selectRaw('1')->from('hr_employment_relationships')->whereColumn('employee_id', 'e.employee_id')))));
            }
            if (! empty($f['q'])) {
                $q->where(fn ($q) => $q->where('e.employee_number', 'like', '%'.$f['q'].'%')->orWhere('e.first_name', 'like', '%'.$f['q'].'%')->orWhere('e.last_name', 'like', '%'.$f['q'].'%'));
            }
            $order = 'e.employee_id';
        } elseif ($section === 'needs' || $section === 'relationships') {
            $q = $this->access->scopeUnits(DB::table($table.' as t'), $actor, 't.organizational_unit_id')->select('t.*');
            if (! empty($f['body'])) {
                $q->where('t.body', $f['body']);
            }
            if (! empty($f['q'])) {
                $q->where($section === 'needs' ? 't.title' : 't.job_title', 'like', '%'.$f['q'].'%');
            }
            if (! empty($f['status']) && $section === 'needs') {
                $q->where('t.status', $f['status']);
            }
            $order = 't.id';
        } else {
            $q = DB::table($table.' as t')->select('t.*');
            if ($section === 'requests') {
                $q->leftJoin('hr_candidates as target_candidate', 'target_candidate.id', '=', 't.candidate_id')
                    ->leftJoin('employees as target_employee', 'target_employee.employee_id', '=', 't.employee_id')
                    ->selectRaw('COALESCE(target_candidate.first_name, target_employee.first_name) as target_first_name, COALESCE(target_candidate.last_name, target_employee.last_name) as target_last_name');
            }
            if (! $actor->isSuperAdmin() && ! app(DataScopeService::class)->hasActualUniversityScope($actor)) {
                $allowedNeeds = $this->access->scopeUnits(DB::table('hr_staffing_needs'), $actor, 'organizational_unit_id')->select('id');
                $candidates = DB::table('hr_candidates as c')->join('hr_staffing_need_items as i', 'i.id', '=', 'c.need_item_id')->whereIn('i.need_id', $allowedNeeds)->select('c.id');
                if ($section === 'candidates') {
                    $q->whereIn('t.id', $candidates);
                } else {
                    $q->where(fn ($q) => $q->whereIn('t.candidate_id', $candidates)->orWhereIn('t.employee_id', $this->employees($actor)->select('e.employee_id')));
                }
            }
            if (! empty($f['status'])) {
                $q->where('t.status', $f['status']);
            }
            if (! empty($f['body'])) {
                if ($section === 'requests') {
                    $q->where('t.proposal->body', $f['body']);
                } else {
                    $q->whereIn('t.need_item_id', DB::table('hr_staffing_need_items as i')->join('hr_staffing_needs as n', 'n.id', '=', 'i.need_id')->where('n.body', $f['body'])->select('i.id'));
                }
            }
            if (! empty($f['q']) && $section === 'candidates') {
                $q->where(fn ($q) => $q->where('t.first_name', 'like', '%'.$f['q'].'%')->orWhere('t.last_name', 'like', '%'.$f['q'].'%'));
            }
            if (! empty($f['q']) && $section === 'requests') {
                $q->where(function ($q) use ($f): void {
                    $q->where('t.reason', 'like', '%'.$f['q'].'%')
                        ->orWhereRaw('COALESCE(target_candidate.first_name, target_employee.first_name) LIKE ?', ['%'.$f['q'].'%'])
                        ->orWhereRaw('COALESCE(target_candidate.last_name, target_employee.last_name) LIKE ?', ['%'.$f['q'].'%']);
                    if (filter_var($f['q'], FILTER_VALIDATE_INT) !== false) {
                        $q->orWhere('t.id', (int) $f['q']);
                    }
                });
            }
            $order = 't.id';
        }
        if (! empty($f['college_id'])) {
            $unit = DB::table('colleges')->where('college_id', $f['college_id'])->value('organizational_unit_id');
            $this->access->unit($actor, (int) $unit);
            if ($employee) {
                $q->where(fn ($q) => $q->where('e.organizational_unit_id', $unit)
                    ->orWhereExists(fn ($r) => $this->effectiveQuery($r->selectRaw('1')->from('hr_employment_relationships')->whereColumn('employee_id', 'e.employee_id')->where('college_id', $f['college_id'])))
                    ->orWhereExists(fn ($a) => $a->selectRaw('1')->from('employee_unit_assignments')->whereColumn('employee_id', 'e.employee_id')->where('organizational_unit_id', $unit)->where('is_active', true)->where('start_date', '<=', now()->toDateString())->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString()))));
            } elseif (in_array($section, ['needs', 'relationships'], true)) {
                $q->where('t.college_id', $f['college_id']);
            } elseif ($section === 'requests') {
                $q->where('t.proposal->college_id', (int) $f['college_id']);
            } else {
                $q->whereIn('t.id', DB::table('hr_candidates as c')->join('hr_staffing_need_items as i', 'i.id', '=', 'c.need_item_id')->join('hr_staffing_needs as n', 'n.id', '=', 'i.need_id')->where('n.college_id', $f['college_id'])->select('c.id'));
            }
        }
        $page = $q->orderByDesc($order)->paginate($f['per_page'] ?? 15, ['*'], 'page', $f['page'] ?? 1);
        $rows = collect($page->items());
        if ($employee && $rows->isNotEmpty()) {
            $ids = $rows->pluck('id');
            $relations = DB::table('hr_employment_relationships')->whereIn('employee_id', $ids)->orderBy('starts_on')->get()->groupBy('employee_id');
            $faculty = DB::table('faculty_members')->whereIn('employee_id', $ids)->get(['employee_id', 'faculty_member_id'])->keyBy('employee_id');
            foreach ($rows as $row) {
                $row->relationships = $relations->get($row->id, collect());
                $row->faculty_member_id = $faculty->get($row->id)?->faculty_member_id;
                $row->current_relationship = $row->relationships->first(fn ($r) => $this->effective($r));
            }
        }
        if ($section === 'needs' && $rows->isNotEmpty()) {
            $items = DB::table('hr_staffing_need_items')->whereIn('need_id', $rows->pluck('id'))->get();
            $counts = $this->effectiveQuery(DB::table('hr_employment_relationships'))->where('source', 'approved_request')->whereIn('need_item_id', $items->pluck('id'))->selectRaw('need_item_id, COUNT(DISTINCT employee_id) as filled')->groupBy('need_item_id')->pluck('filled', 'need_item_id');
            foreach ($items as $item) {
                $item->filled = (int) ($counts[$item->id] ?? 0);
                $item->remaining = max(0, $item->quantity - $item->filled);
            }
            foreach ($rows as $row) {
                $row->items = $items->where('need_id', $row->id)->values();
            }
        }

        return ['rows' => $rows, 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()], 'generated_at' => now()->utc()->toIso8601String()];
    }

    private function effectiveQuery(Builder $q): Builder
    {
        $today = now()->toDateString();

        return $q->where('starts_on', '<=', $today)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->where(fn ($q) => $q->whereNull('superseded_from')->orWhere('superseded_from', '>', $today));
    }

    private function effective(object $r): bool
    {
        return $r->starts_on <= now()->toDateString() && (! $r->ends_on || $r->ends_on >= now()->toDateString()) && (! $r->superseded_from || $r->superseded_from > now()->toDateString());
    }

    private function placement(User $actor, array $p): array
    {
        if ($p['body'] === 'educational') {
            $college = DB::table('colleges')->where('college_id', $p['college_id'] ?? 0)->where('is_active', true)->lockForUpdate()->first();
            if (! $college?->organizational_unit_id) {
                throw Failure::invalid('hr_college_required', 'حدد كلية فعالة لها وحدة تنظيمية.');
            }
            $p['organizational_unit_id'] = $college->organizational_unit_id;
        } else {
            if (! empty($p['college_id'])) {
                throw Failure::invalid('hr_placement_invalid', 'حدد الوحدة التنظيمية للهيئة الإدارية دون كلية.');
            }
            $p['college_id'] = null;
        }
        $this->access->unit($actor, (int) ($p['organizational_unit_id'] ?? 0));
        if (! DB::table('organizational_units')->where('organizational_unit_id', $p['organizational_unit_id'])->where('is_active', true)->lockForUpdate()->first()) {
            throw Failure::invalid('hr_unit_inactive', 'الوحدة غير فعالة.');
        }
        if (! empty($p['position_id']) && ! DB::table('positions')->where('position_id', $p['position_id'])->where('is_active', true)->lockForUpdate()->first()) {
            throw Failure::invalid('hr_position_invalid', 'المنصب غير متاح.');
        }

        return $p;
    }

    public function saveNeed(User $actor, array $data, ?int $id = null): array
    {
        $this->access->authorize($actor, HrOffice::RECRUIT);
        $this->requireReady();
        $d = $this->input($data, ['revision' => $id ? self::REVISION : 'prohibited', 'title' => 'required|string|max:200', 'body' => 'required|in:educational,administrative', 'college_id' => 'nullable|integer|min:1', 'organizational_unit_id' => 'nullable|integer|min:1', 'notes' => 'nullable|string|max:4000', 'status' => 'required|in:open,closed', 'items' => 'required|array|min:1|max:30']);
        Validator::make($d, ['items.*' => 'array'])->validate();
        $items = array_map(fn ($i) => $this->input($i, ['id' => 'nullable|integer|min:1', 'position_id' => 'nullable|integer|min:1', 'job_title' => 'required|string|max:200', 'quantity' => 'required|integer|min:1|max:1000', 'education' => 'nullable|string|max:4000', 'specialization' => 'nullable|string|max:4000', 'skills' => 'nullable|string|max:4000', 'experience' => 'nullable|string|max:4000', 'notes' => 'nullable|string|max:4000', 'is_active' => 'required|boolean']), $d['items']);

        return $this->atomic(function () use ($actor, $id, $d, $items): array {
            $old = $id ? $this->row('hr_staffing_needs', $id) : null;
            $oldItems = $old ? DB::table('hr_staffing_need_items')->where('need_id', $id)->orderBy('id')->lockForUpdate()->get()->all() : [];
            if ($old) {
                $this->access->unit($actor, $old->organizational_unit_id);
                $this->revision($old, $d['revision']);
            }
            $p = $this->placement($actor, $d);
            unset($p['items'], $p['revision']);
            if ($old && ($old->body !== $p['body'] || $old->college_id != ($p['college_id'] ?? null) || $old->organizational_unit_id != $p['organizational_unit_id'])) {
                throw Failure::conflict('hr_need_identity_locked', 'جهة الاحتياج ثابتة؛ أنشئ احتياجًا آخر.');
            }
            $p['updated_at'] = now();
            if ($old) {
                DB::table('hr_staffing_needs')->where('id', $id)->update($p + ['revision' => $old->revision + 1]);
            } else {
                $id = DB::table('hr_staffing_needs')->insertGetId($p + ['created_by_user_id' => $actor->user_id, 'created_at' => now()]);
            }
            $seen = [];
            foreach ($items as $item) {
                $itemId = $item['id'] ?? null;
                unset($item['id']);
                if (! empty($item['position_id'])) {
                    $this->placement($actor, $p + ['position_id' => $item['position_id']]);
                }
                if ($itemId) {
                    if (in_array($itemId, $seen, true) || ! DB::table('hr_staffing_need_items')->where('id', $itemId)->where('need_id', $id)->exists()) {
                        throw Failure::invalid('hr_item_invalid', 'بند مكرر أو تابع لاحتياج آخر.');
                    }
                    DB::table('hr_staffing_need_items')->where('id', $itemId)->update($item + ['updated_at' => now()]);
                } else {
                    $itemId = DB::table('hr_staffing_need_items')->insertGetId($item + ['need_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                }
                $seen[] = $itemId;
            }
            // Referenced items are never deleted by an omitted row.
            $this->event($actor, 'need', $id, $old ? 'updated' : 'created', ['before' => $old, 'after' => $p, 'before_items' => $oldItems, 'items' => $items]);

            return (array) $this->row('hr_staffing_needs', $id);
        });
    }

    private function candidateLocks(User $actor, int $id): array
    {
        $c = $this->row('hr_candidates', $id, false);
        $item = $this->row('hr_staffing_need_items', $c->need_item_id, false);
        $need = $this->row('hr_staffing_needs', $item->need_id);
        $this->access->unit($actor, $need->organizational_unit_id);

        $item = $this->row('hr_staffing_need_items', $item->id);
        $c = $this->row('hr_candidates', $id);
        if ($c->employee_id) {
            $this->access->employee($actor, $this->row('employees', $c->employee_id));
        }

        return [$need, $item, $c];
    }

    public function candidate(User $actor, int $id): array
    {
        $this->access->authorize($actor, HrOffice::VIEW);
        $this->requireReady();
        $c = $this->row('hr_candidates', $id, false);
        $i = $this->row('hr_staffing_need_items', $c->need_item_id, false);
        $n = $this->row('hr_staffing_needs', $i->need_id, false);
        $this->access->unit($actor, $n->organizational_unit_id);
        $interviews = DB::table('hr_interviews')->where('candidate_id', $id)->orderBy('scheduled_at')->orderBy('id')->get();
        $participants = DB::table('hr_interview_participants as p')->join('employees as e', 'e.employee_id', '=', 'p.employee_id')->whereIn('p.interview_id', $interviews->pluck('id'))->get(['p.interview_id', 'e.employee_id', 'e.first_name', 'e.last_name'])->groupBy('interview_id');
        foreach ($interviews as $r) {
            $r->participants = $participants->get($r->id, collect());
        }

        return ['candidate' => $c, 'item' => $i, 'need' => $n, 'interviews' => $interviews, 'events' => $this->events('candidate', $id)];
    }

    public function saveCandidate(User $actor, array $data, ?int $id = null): array
    {
        $this->access->authorize($actor, HrOffice::RECRUIT);
        $this->requireReady();
        $rules = ['revision' => $id ? self::REVISION : 'prohibited', 'need_item_id' => 'required|integer|min:1', 'employee_id' => 'nullable|integer|min:1', 'first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100', 'father_name' => 'nullable|string|max:100', 'phone_number' => 'nullable|string|max:30', 'email' => 'nullable|email|max:150'];
        foreach (['education', 'specialization', 'skills', 'experience', 'notes'] as $k) {
            $rules[$k] = 'nullable|string|max:4000';
        }
        $d = $this->input($data, $rules);

        return $this->atomic(function () use ($actor, $id, $d): array {
            $hint = $id ? $this->row('hr_candidates', $id, false) : null;
            $i = $this->row('hr_staffing_need_items', $hint?->need_item_id ?? $d['need_item_id'], false);
            $n = $this->row('hr_staffing_needs', $i->need_id);
            $this->access->unit($actor, $n->organizational_unit_id);
            $i = $this->row('hr_staffing_need_items', $i->id);
            if ($n->status !== 'open' || ! $i->is_active) {
                throw Failure::conflict('hr_need_closed', 'بند الاحتياج غير مفتوح.');
            }
            $old = $id ? $this->row('hr_candidates', $id) : null;
            $employeeIds = array_values(array_unique(array_filter([$old?->employee_id, $d['employee_id'] ?? null])));
            sort($employeeIds, SORT_NUMERIC);
            $employeeRows = [];
            foreach ($employeeIds as $employeeId) {
                $employeeRows[$employeeId] = $this->row('employees', $employeeId);
                $this->access->employee($actor, $employeeRows[$employeeId]);
            }
            if ($old) {
                if ($old->need_item_id != $d['need_item_id']) {
                    throw Failure::conflict('hr_candidate_identity_locked', 'بند المرشح ثابت.');
                }
                $this->editableCandidate($old);
                $this->revision($old, $d['revision']);
            }
            if (! empty($d['employee_id'])) {
                $e = $employeeRows[$d['employee_id']];
                if ($e->first_name !== $d['first_name'] || $e->last_name !== $d['last_name']) {
                    throw Failure::invalid('hr_identity_mismatch', 'تحقق من هوية الموظف المحدد.');
                }
            }
            unset($d['revision']);
            if ($old) {
                DB::table('hr_candidates')->where('id', $id)->update($d + ['revision' => $old->revision + 1, 'updated_at' => now()]);
            } else {
                $id = DB::table('hr_candidates')->insertGetId($d + ['created_by_user_id' => $actor->user_id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->event($actor, 'candidate', $id, $old ? 'updated' : 'registered', ['before' => $old, 'after' => $d]);

            return (array) $this->row('hr_candidates', $id);
        });
    }

    private function editableCandidate(object $c): void
    {
        if (in_array($c->status, ['accepted', 'declined'], true) || DB::table('hr_relationship_requests')->where('candidate_id', $c->id)->where('status', 'submitted')->orderBy('id')->lockForUpdate()->first(['id'])) {
            throw Failure::conflict('hr_candidate_locked', 'ملف المرشح ثابت أثناء المراجعة أو بعد القرار النهائي.');
        }
    }

    public function interview(User $actor, int $candidateId, array $data, ?int $id = null): array
    {
        $this->access->authorize($actor, HrOffice::RECRUIT);
        $this->requireReady();
        $d = $this->input($data, ['candidate_revision' => self::REVISION, 'revision' => $id ? self::REVISION : 'prohibited', 'scheduled_at' => 'required|date', 'status' => 'required|in:scheduled,completed,cancelled', 'participant_ids' => 'required|array|min:1|max:20', 'notes' => 'nullable|string|max:4000', 'evaluation' => 'nullable|string|max:4000', 'result' => 'nullable|string|max:4000']);
        Validator::make($d, ['participant_ids.*' => 'integer|min:1|distinct'])->validate();

        return $this->atomic(function () use ($actor, $candidateId, $d, $id): array {
            [, , $c] = $this->candidateLocks($actor, $candidateId);
            $this->editableCandidate($c);
            $this->revision($c, $d['candidate_revision']);
            foreach ($d['participant_ids'] as $employeeId) {
                $this->access->employee($actor, $this->row('employees', $employeeId, false));
            }
            $old = $id ? $this->row('hr_interviews', $id) : null;
            if ($old) {
                if ($old->candidate_id != $candidateId) {
                    throw Failure::denied('hr_scope_denied', 'المقابلة غير تابعة للمرشح.');
                } $this->revision($old, $d['revision']);
            }
            $p = $d;
            unset($p['participant_ids'], $p['candidate_revision'], $p['revision']);
            $p['scheduled_at'] = CarbonImmutable::parse($p['scheduled_at'])->format('Y-m-d H:i:s');
            if ($old) {
                DB::table('hr_interviews')->where('id', $id)->update($p + ['revision' => $old->revision + 1, 'updated_at' => now()]);
            } else {
                $id = DB::table('hr_interviews')->insertGetId($p + ['candidate_id' => $candidateId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $oldParticipants = DB::table('hr_interview_participants')->where('interview_id', $id)->orderBy('employee_id')->pluck('employee_id')->all();
            DB::table('hr_interview_participants')->where('interview_id', $id)->delete();
            foreach ($d['participant_ids'] as $e) {
                DB::table('hr_interview_participants')->insert(['interview_id' => $id, 'employee_id' => $e]);
            }
            DB::table('hr_candidates')->where('id', $candidateId)->increment('revision');
            $this->event($actor, 'candidate', $candidateId, 'interview_saved', ['interview_id' => $id, 'before' => $old, 'after' => $p, 'before_participant_ids' => $oldParticipants, 'participant_ids' => $d['participant_ids']]);

            return (array) $this->row('hr_interviews', $id);
        });
    }

    public function decline(User $actor, int $id, array $data): array
    {
        $this->access->authorize($actor, HrOffice::RECRUIT);
        $this->requireReady();
        $d = $this->input($data, ['revision' => self::REVISION, 'reason' => 'required|string|max:4000']);

        return $this->atomic(function () use ($actor, $id, $d): array {
            [, , $c] = $this->candidateLocks($actor, $id);
            $this->revision($c, $d['revision']);
            $this->editableCandidate($c);
            if (DB::table('hr_relationship_requests')->where('candidate_id', $id)->whereNotNull('current_slot')->exists()) {
                throw Failure::conflict('hr_request_pending', 'احسم طلب العلاقة أولًا.');
            }
            DB::table('hr_candidates')->where('id', $id)->update(['status' => 'declined', 'decision_reason' => $d['reason'], 'revision' => $c->revision + 1, 'updated_at' => now()]);
            $this->event($actor, 'candidate', $id, 'declined', ['reason' => $d['reason']]);

            return (array) $this->row('hr_candidates', $id);
        });
    }

    private function proposal(User $actor, array $data): array
    {
        $p = $this->input($data, ['body' => 'required|in:educational,administrative', 'relationship_type' => 'required|in:temporary_contract,continuous_contract,employment', 'work_mode' => 'required|in:full,part', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d', 'college_id' => 'nullable|integer|min:1', 'organizational_unit_id' => 'nullable|integer|min:1', 'position_id' => 'nullable|integer|min:1', 'job_title' => 'required|string|max:200', 'notes' => 'nullable|string|max:4000', 'employee_number' => 'nullable|string|max:50', 'employee_type_id' => 'nullable|integer|min:1', 'academic_rank' => 'nullable|string|max:100', 'specialization' => 'nullable|string|max:200', 'predecessor_id' => 'nullable|integer|min:1']);
        $start = CarbonImmutable::parse($p['starts_on']);
        $end = $p['ends_on'] ?? null;
        if ($p['relationship_type'] === 'temporary_contract') {
            if ($end !== $start->addMonthsNoOverflow(3)->subDay()->toDateString()) {
                throw Failure::invalid('hr_temporary_duration', 'أكد نهاية مدة الثلاثة أشهر (اليوم السابق لنفس التاريخ بعد ثلاثة أشهر).');
            }
        } elseif ($end !== null) {
            throw Failure::invalid('hr_continuous_end', 'العلاقة المستمرة بلا تاريخ نهاية افتراضي.');
        }
        if ($p['relationship_type'] === 'employment' && $p['work_mode'] !== 'full') {
            throw Failure::invalid('hr_employment_mode', 'التوظيف مستمر وكلي فقط.');
        }

        return $this->placement($actor, $p);
    }

    private function requestLocks(User $actor, object $r): array
    {
        $n = $i = $c = $e = null;
        if ($r->candidate_id) {
            [$n, $i, $c] = $this->candidateLocks($actor, $r->candidate_id);
            if ($c->employee_id) {
                $e = $this->row('employees', $c->employee_id);
            }
        } else {
            $e = $this->row('employees', $r->employee_id);
        }
        if ($e) {
            $this->access->employee($actor, $e);
        }

        return [$n, $i, $c, $e];
    }

    public function saveRequest(User $actor, array $data, ?int $id = null): array
    {
        $this->access->authorize($actor, HrOffice::PREPARE);
        $this->requireReady();
        $d = $this->input($data, ['revision' => $id ? self::REVISION : 'prohibited', 'candidate_id' => 'nullable|integer|min:1', 'employee_id' => 'nullable|integer|min:1', 'kind' => 'required|in:accept,issue,renew,convert', 'reason' => 'required|string|max:4000', 'proposal' => 'required|array']);
        if ((bool) ($d['candidate_id'] ?? null) === (bool) ($d['employee_id'] ?? null) || (($d['kind'] === 'accept') !== ! empty($d['candidate_id']))) {
            throw Failure::invalid('hr_target_invalid', 'حدد مرشحًا للقبول أو موظفًا للعلاقة الوظيفية.');
        }
        try {
            return $this->atomic(function () use ($actor, $d, $id): array {
                $identity = (object) ['candidate_id' => $d['candidate_id'] ?? null, 'employee_id' => $d['employee_id'] ?? null];
                $hint = $id ? $this->row('hr_relationship_requests', $id, false) : $identity;
                [$n, $i, $c, $e] = $this->requestLocks($actor, $hint);
                if ($c) {
                    $this->editableCandidate($c);
                }
                $old = $id ? $this->row('hr_relationship_requests', $id) : null;
                if ($old) {
                    if ($old->employee_id != $identity->employee_id || $old->candidate_id != $identity->candidate_id || $old->kind !== $d['kind']) {
                        throw Failure::conflict('hr_request_identity_locked', 'هوية الطلب ثابتة.');
                    } $this->revision($old, $d['revision']);
                    if (! in_array($old->status, ['draft', 'returned'], true)) {
                        throw Failure::conflict('hr_request_locked', 'الطلب غير قابل للتحرير.');
                    }
                }
                $p = $this->proposal($actor, $d['proposal']);
                if ($n && ($p['body'] !== $n->body || $p['college_id'] != $n->college_id || $p['organizational_unit_id'] != $n->organizational_unit_id)) {
                    throw Failure::invalid('hr_need_mismatch', 'العلاقة المقترحة لا تطابق جهة الاحتياج.');
                }
                if ($e && ! empty($p['employee_number']) && $p['employee_number'] !== $e->employee_number) {
                    throw Failure::invalid('hr_identity_mismatch', 'الرقم الوظيفي لا يطابق الموظف المرتبط.');
                }
                if (! $e && (empty($p['employee_number']) || empty($p['employee_type_id']))) {
                    throw Failure::invalid('hr_employee_data_required', 'أدخل رقم الموظف ونوعه لإنشاء الملف بعد الاعتماد فقط.');
                }
                $payload = ['proposal' => json_encode($p, JSON_THROW_ON_ERROR), 'reason' => $d['reason'], 'updated_at' => now()];
                if ($old) {
                    DB::table('hr_relationship_requests')->where('id', $id)->update($payload + ['revision' => $old->revision + 1]);
                } else {
                    $id = DB::table('hr_relationship_requests')->insertGetId($payload + (array) $identity + ['kind' => $d['kind'], 'target_key' => $c ? 'candidate:'.$c->id : 'employee:'.$e->employee_id, 'created_by_user_id' => $actor->user_id, 'created_at' => now()]);
                }
                if ($c) {
                    DB::table('hr_candidates')->where('id', $c->id)->update(['status' => 'proposed']);
                }
                $this->event($actor, 'request', $id, $old ? 'updated' : 'prepared', ['before' => $old, 'after' => $payload]);

                return $this->presentRequest($id);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' || $e->getCode() === '19') {
                throw Failure::conflict('hr_current_request_exists', 'يوجد طلب حالي أو رقم موظف مستخدم.');
            } throw $e;
        }
    }

    private function context(?object $n, ?object $c, ?object $e): array
    {
        return ['need_revision' => $n?->revision, 'candidate_revision' => $c?->revision, 'employee_revision' => $e?->hr_revision];
    }

    public function submit(User $actor, int $id, array $data): array
    {
        $this->access->authorize($actor, HrOffice::PREPARE);
        $this->requireReady();
        $d = $this->input($data, ['revision' => self::REVISION]);

        return $this->atomic(function () use ($actor, $id, $d): array {
            $hint = $this->row('hr_relationship_requests', $id, false);
            [$n, , $c, $e] = $this->requestLocks($actor, $hint);
            $r = $this->row('hr_relationship_requests', $id);
            $this->revision($r, $d['revision']);
            if (! in_array($r->status, ['draft', 'returned'], true) || $r->current_slot != 1) {
                throw Failure::conflict('hr_request_locked', 'الطلب غير قابل للإرسال.');
            }
            $p = $this->proposal($actor, json_decode($r->proposal, true, 512, JSON_THROW_ON_ERROR));
            $this->relationContext($r, $p, $e);
            if ($n && $n->status !== 'open') {
                throw Failure::conflict('hr_need_closed', 'الاحتياج مغلق.');
            }
            DB::table('hr_relationship_requests')->where('id', $id)->update(['status' => 'submitted', 'submission_version' => $r->submission_version + 1, 'revision' => $r->revision + 1, 'context' => json_encode($this->context($n, $c, $e), JSON_THROW_ON_ERROR), 'submitted_at' => now(), 'updated_at' => now()]);
            $this->event($actor, 'request', $id, $r->submission_version ? 'resubmitted' : 'submitted', ['version' => $r->submission_version + 1, 'proposal' => $p, 'context' => $this->context($n, $c, $e)]);

            return $this->presentRequest($id);
        });
    }

    private function relationContext(object $r, array $p, ?object $e): ?object
    {
        $predecessor = null;
        if (! empty($p['predecessor_id'])) {
            $predecessor = $this->row('hr_employment_relationships', $p['predecessor_id']);
            if (! $e || $predecessor->employee_id != $e->employee_id || $predecessor->superseded_from) {
                throw Failure::conflict('hr_predecessor_stale', 'العلاقة السابقة لا تطابق السياق الحالي.');
            }
        }
        if (in_array($r->kind, ['renew', 'convert'], true) && ! $predecessor) {
            throw Failure::invalid('hr_predecessor_required', 'اختر العلاقة السابقة للتجديد أو التحويل.');
        }
        if (! in_array($r->kind, ['renew', 'convert'], true) && $predecessor) {
            throw Failure::invalid('hr_predecessor_invalid', 'إصدار جديد لا يستبدل علاقة موجودة.');
        }
        if ($predecessor && $p['starts_on'] <= $predecessor->starts_on) {
            throw Failure::invalid('hr_relation_dates', 'بداية العلاقة الجديدة يجب أن تلي بداية السابقة.');
        }
        if ($r->kind === 'renew' && ($predecessor->relationship_type !== $p['relationship_type'] || $predecessor->work_mode !== $p['work_mode'] || ! $predecessor->ends_on || $p['starts_on'] <= $predecessor->ends_on)) {
            throw Failure::invalid('hr_renewal_invalid', 'التجديد يلي نهاية العقد ويحافظ على نوعه ونمطه؛ استخدم التحويل للتغيير.');
        }
        if ($e) {
            $q = DB::table('hr_employment_relationships')->where('employee_id', $e->employee_id)->orderBy('id')->lockForUpdate();
            foreach ($q->get() as $old) {
                if ($predecessor && $old->id === $predecessor->id) {
                    continue;
                }
                $last = $old->superseded_from ? CarbonImmutable::parse($old->superseded_from)->subDay()->toDateString() : $old->ends_on;
                if ((! $last || $last >= $p['starts_on']) && (empty($p['ends_on']) || $old->starts_on <= $p['ends_on'])) {
                    throw Failure::conflict('hr_relation_overlap', 'توجد علاقة وظيفية متداخلة.');
                }
            }
        }

        return $predecessor;
    }

    public function decide(User $actor, int $id, array $data): array
    {
        $this->access->authorize($actor, HrOffice::REVIEW);
        $this->requireReady();
        $d = $this->input($data, ['revision' => self::REVISION, 'decision' => 'required|in:approve,return,reject', 'note' => 'nullable|string|max:4000']);
        if ($d['decision'] !== 'approve' && trim($d['note'] ?? '') === '') {
            throw Failure::invalid('hr_review_reason_required', 'سبب الإعادة أو الرفض مطلوب.');
        }
        try {
            return $this->atomic(function () use ($actor, $id, $d): array {
                $hint = $this->row('hr_relationship_requests', $id, false);
                [$n, $i, $c, $e] = $this->requestLocks($actor, $hint);
                $r = $this->row('hr_relationship_requests', $id);
                $this->revision($r, $d['revision']);
                if ($r->status !== 'submitted' || $r->current_slot != 1 || $r->materialized_at) {
                    throw Failure::conflict('hr_request_locked', 'الطلب ليس قيد المراجعة.');
                }
                $status = match ($d['decision']) {
                    'approve' => 'approved', 'return' => 'returned', 'reject' => 'rejected'
                };
                $changes = ['status' => $status, 'review_note' => $d['note'] ?? null, 'reviewed_by_user_id' => $actor->user_id, 'reviewed_at' => now(), 'revision' => $r->revision + 1, 'updated_at' => now()];
                if ($d['decision'] === 'approve') {
                    if (json_decode($r->context, true, 512, JSON_THROW_ON_ERROR) != $this->context($n, $c, $e)) {
                        throw Failure::conflict('hr_context_stale', 'تغير ملف الموظف أو الاحتياج أو المقابلات بعد الإرسال. أعد الطلب للمراجعة.');
                    }
                    $p = $this->proposal($actor, json_decode($r->proposal, true, 512, JSON_THROW_ON_ERROR));
                    if ($n && ($n->status !== 'open' || ! $i->is_active || $n->body !== $p['body'] || $n->college_id != ($p['college_id'] ?? null))) {
                        throw Failure::conflict('hr_need_changed', 'جهة الاحتياج غير صالحة للاعتماد.');
                    }
                    $predecessor = $this->relationContext($r, $p, $e);
                    if (! $e) {
                        if (! DB::table('employee_types')->where('employee_type_id', $p['employee_type_id'])->where('is_active', true)->exists()) {
                            throw Failure::invalid('hr_employee_type_invalid', 'نوع الموظف غير صالح.');
                        }
                        $active = DB::table('employee_statuses')->where('status_code', 'active')->where('is_active', true)->value('employee_status_id');
                        if (! $active) {
                            throw Failure::invalid('hr_employee_status_unavailable', 'حالة الموظف الفعال غير معرفة.');
                        }
                        $employeeId = DB::table('employees')->insertGetId(['employee_number' => $p['employee_number'], 'first_name' => $c->first_name, 'last_name' => $c->last_name, 'father_name' => $c->father_name, 'phone_number' => $c->phone_number, 'email' => $c->email, 'employee_type_id' => $p['employee_type_id'], 'employee_status_id' => $active, 'organizational_unit_id' => $p['organizational_unit_id'], 'hr_body' => $p['body'], 'created_at' => now(), 'updated_at' => now()], 'employee_id');
                        $e = $this->row('employees', $employeeId);
                    }
                    if (DB::table('hr_relationship_requests')->where('employee_id', $e->employee_id)->whereNotNull('current_slot')->where('id', '!=', $id)->orderBy('id')->lockForUpdate()->first(['id'])) {
                        throw Failure::conflict('hr_current_request_exists', 'للموظف طلب علاقة آخر يجب حسمه أولًا.');
                    }
                    if ($predecessor) {
                        DB::table('hr_employment_relationships')->where('id', $predecessor->id)->update(['superseded_from' => $p['starts_on'], 'updated_at' => now()]);
                    }
                    $needItem = $i?->id;
                    if (! $needItem && $predecessor && $predecessor->body === $p['body'] && $predecessor->organizational_unit_id == $p['organizational_unit_id'] && $predecessor->position_id == ($p['position_id'] ?? null) && $predecessor->job_title === $p['job_title']) {
                        $needItem = $predecessor->need_item_id;
                    }
                    $relation = $this->materializeRelation($actor, $p, $e->employee_id, 'approved_request', $id, $needItem);
                    if ($e->hr_body === null) {
                        DB::table('employees')->where('employee_id', $e->employee_id)->update(['hr_body' => $p['body']]);
                    }
                    if ($c) {
                        DB::table('hr_candidates')->where('id', $c->id)->update(['employee_id' => $e->employee_id, 'status' => 'accepted', 'revision' => $c->revision + 1, 'updated_at' => now()]);
                    }
                    // A faculty profile is a person profile only, not a teaching assignment/account/dean appointment.
                    if ($c && $p['body'] === 'educational' && ! DB::table('faculty_members')->where('employee_id', $e->employee_id)->exists()) {
                        DB::table('faculty_members')->insert(['employee_id' => $e->employee_id, 'academic_rank' => $p['academic_rank'] ?? null, 'specialization' => $p['specialization'] ?? null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $changes['materialized_at'] = now();
                    $changes['current_slot'] = null;
                    $this->event($actor, 'request', $id, 'materialized', ['relationship_id' => $relation, 'employee_id' => $e->employee_id, 'version' => $r->submission_version]);
                } elseif ($d['decision'] === 'reject') {
                    $changes['current_slot'] = null;
                    if ($c) {
                        DB::table('hr_candidates')->where('id', $c->id)->update(['status' => 'candidate']);
                    }
                }
                DB::table('hr_relationship_requests')->where('id', $id)->update($changes);
                $this->event($actor, 'request', $id, $status, ['version' => $r->submission_version, 'note' => $d['note'] ?? null, 'before' => $r->status, 'after' => $status]);

                return $this->presentRequest($id);
            });
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '19'], true)) {
                throw Failure::conflict('hr_identity_conflict', 'هوية الموظف أو العلاقة مستخدمة. لم تُحفظ أي تغييرات جزئية.');
            } throw $e;
        }
    }

    private function materializeRelation(User $actor, array $p, int $employee, string $source, ?int $request = null, ?int $item = null): int
    {
        $keys = ['body', 'relationship_type', 'work_mode', 'starts_on', 'ends_on', 'position_id', 'job_title', 'college_id', 'organizational_unit_id', 'notes', 'predecessor_id'];

        return DB::table('hr_employment_relationships')->insertGetId(array_intersect_key($p, array_flip($keys)) + ['employee_id' => $employee, 'source' => $source, 'request_id' => $request, 'need_item_id' => $item, 'recorded_by_user_id' => $actor->user_id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function classify(User $actor, int $employeeId, array $data): array
    {
        $this->access->authorize($actor, HrOffice::CLASSIFY);
        $this->requireReady();
        $d = $this->input($data, ['revision' => self::REVISION, 'reason' => 'required|string|max:4000', 'proposal' => 'required|array']);

        return $this->atomic(function () use ($actor, $employeeId, $d): array {
            $e = $this->row('employees', $employeeId);
            $this->access->employee($actor, $e);
            $this->revision($e, $d['revision'], 'hr_revision');
            if (DB::table('hr_employment_relationships')->where('employee_id', $employeeId)->exists() || DB::table('hr_relationship_requests')->where('employee_id', $employeeId)->whereNotNull('current_slot')->exists()) {
                throw Failure::conflict('hr_classification_exists', 'الملف مصنف أو له طلب حالي. استخدم التجديد أو التحويل بإقرار النائب.');
            }
            $p = $this->proposal($actor, $d['proposal']);
            if (! empty($p['predecessor_id']) || $p['starts_on'] > now()->toDateString()) {
                throw Failure::invalid('hr_classification_not_current', 'هذا الإجراء لتوثيق علاقة العامل الحالي بتواريخ صريحة، وليس لإصدار عقد جديد.');
            }
            $id = $this->materializeRelation($actor, $p, $employeeId, 'legacy_classification');
            DB::table('employees')->where('employee_id', $employeeId)->update(['hr_body' => $p['body']]);
            $this->event($actor, 'employee', $employeeId, 'classification_completed', ['before' => ['employee_id' => $e->employee_id, 'hr_body' => $e->hr_body, 'hr_revision' => $e->hr_revision], 'after' => $p, 'relationship_id' => $id, 'reason' => $d['reason'], 'historical_approval' => false]);

            return $this->worker($actor, $employeeId);
        });
    }

    private function events(string $type, int $id): mixed
    {
        return DB::table('hr_workforce_events')->where('subject_type', $type)->where('subject_id', $id)->orderByDesc('id')->limit(100)->get();
    }

    private function presentRequest(int $id): array
    {
        $r = (array) $this->row('hr_relationship_requests', $id, false);
        $r['proposal'] = json_decode($r['proposal'], true, 512, JSON_THROW_ON_ERROR);
        $r['context'] = $r['context'] ? json_decode($r['context'], true, 512, JSON_THROW_ON_ERROR) : null;

        return $r;
    }

    public function request(User $actor, int $id): array
    {
        $this->access->authorize($actor, HrOffice::VIEW);
        $this->requireReady();
        $r = $this->presentRequest($id);
        // Read-only scope checks: never acquire write locks or mutate in GET.
        $candidate = $r['candidate_id'] ? $this->candidate($actor, $r['candidate_id']) : null;
        $employee = $r['employee_id'] ? $this->row('employees', $r['employee_id'], false) : null;
        if ($employee) {
            $this->access->employee($actor, $employee);
        }
        $this->access->unit($actor, $r['proposal']['organizational_unit_id']);

        $identity = $employee ? collect((array) $employee)->only(['employee_id', 'employee_number', 'first_name', 'last_name', 'employee_type_id', 'employee_status_id', 'organizational_unit_id', 'hr_body', 'hr_revision'])->all() : null;

        return ['request' => $r, 'candidate_context' => $candidate, 'employee' => $identity, 'events' => $this->events('request', $id)];
    }

    public function worker(User $actor, int $id): array
    {
        $this->access->authorize($actor, HrOffice::VIEW);
        $this->requireReady();
        $e = $this->row('employees', $id, false);
        $this->access->employee($actor, $e);
        $payroll = null;
        if ($this->access->allows($actor, HrOffice::PAYROLL_LINK) && Schema::hasColumn('payroll_employees', 'employee_id')) {
            $payroll = DB::table('payroll_employees')->where('employee_id', $id)->first(['id', 'employee_number', 'full_name', 'payroll_body_id', 'revision', 'hr_body_at_link', 'hr_revision_at_link']);
        }

        return ['employee' => collect((array) $e)->only(['employee_id', 'employee_number', 'first_name', 'last_name', 'father_name', 'phone_number', 'email', 'employee_type_id', 'employee_status_id', 'organizational_unit_id', 'hr_body', 'hr_revision'])->all(),
            'faculty' => DB::table('faculty_members')->where('employee_id', $id)->first(['faculty_member_id', 'academic_rank', 'specialization', 'is_active']),
            'positions' => DB::table('employee_positions')->where('employee_id', $id)->orderBy('start_date')->get(),
            'affiliations' => DB::table('employee_unit_assignments')->where('employee_id', $id)->orderBy('start_date')->get(),
            'relationships' => DB::table('hr_employment_relationships')->where('employee_id', $id)->orderByDesc('starts_on')->orderByDesc('id')->get(), 'payroll' => $payroll, 'events' => $this->events('employee', $id)];
    }

    public function payrollLookup(User $actor, array $input): array
    {
        $this->access->authorize($actor, HrOffice::PAYROLL_LINK);
        $this->access->payroll($actor, OwnerPortal::EMPLOYEES_MANAGE);
        $this->requireReady();
        $d = $this->input($input, ['q' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1']);
        if (! Schema::hasColumn('payroll_employees', 'employee_id')) {
            throw new Failure('ربط الرواتب غير جاهز.', 503, 'hr_schema_not_ready');
        }
        $q = DB::table('payroll_employees')->select(['id', 'employee_number', 'full_name', 'job_title', 'payroll_body_id', 'employee_id', 'revision']);
        if (! empty($d['q'])) {
            $q->where(fn ($q) => $q->where('employee_number', 'like', '%'.$d['q'].'%')->orWhere('full_name', 'like', '%'.$d['q'].'%'));
        }

        return $q->orderBy('employee_number')->orderBy('id')->paginate(15, ['*'], 'page', $d['page'] ?? 1)->toArray();
    }

    public function payrollPersonnelLookup(User $actor, array $input): array
    {
        $this->access->authorize($actor, HrOffice::PAYROLL_LINK);
        $this->access->payroll($actor, OwnerPortal::EMPLOYEES_MANAGE);
        $this->requireReady();
        $d = $this->input($input, ['q' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1']);
        $q = $this->employees($actor)->select(['e.employee_id as id', 'e.employee_number', 'e.first_name', 'e.last_name', 'e.hr_revision', 'e.hr_body']);
        if (! empty($d['q'])) {
            $q->where(fn ($q) => $q->where('e.employee_number', 'like', '%'.$d['q'].'%')->orWhere('e.first_name', 'like', '%'.$d['q'].'%')->orWhere('e.last_name', 'like', '%'.$d['q'].'%'));
        }

        return $q->orderBy('e.employee_number')->orderBy('e.employee_id')->paginate(15, ['*'], 'page', $d['page'] ?? 1)->toArray();
    }

    public function linkPayroll(User $actor, int $employeeId, array $data): array
    {
        $this->access->authorize($actor, HrOffice::PAYROLL_LINK);
        $this->access->payroll($actor, OwnerPortal::EMPLOYEES_MANAGE);
        $this->requireReady();
        $d = $this->input($data, ['payroll_employee_id' => 'required|integer|min:1', 'revision' => self::REVISION, 'payroll_revision' => self::REVISION, 'confirmed' => 'required|accepted', 'reason' => 'required|string|max:4000']);
        if (! Schema::hasColumn('payroll_employees', 'employee_id')) {
            throw new Failure('ربط الرواتب غير جاهز.', 503, 'hr_schema_not_ready');
        }
        try {
            return $this->atomic(function () use ($actor, $employeeId, $d): array {
                // Existing financial writers lock payroll employee first. HR never locks financial rows during approval.
                $p = $this->row('payroll_employees', $d['payroll_employee_id']);
                $e = $this->row('employees', $employeeId);
                $this->access->employee($actor, $e);
                $this->revision($e, $d['revision'], 'hr_revision');
                $this->revision($p, $d['payroll_revision']);
                if ($p->employee_id && $p->employee_id != $employeeId) {
                    throw Failure::conflict('hr_payroll_identity_conflict', 'الملف المالي مرتبط بشخص آخر.');
                }
                if (DB::table('payroll_employees')->where('employee_id', $employeeId)->where('id', '!=', $p->id)->exists()) {
                    throw Failure::conflict('hr_payroll_identity_conflict', 'الموظف مرتبط بملف مالي آخر.');
                }
                if (! $p->employee_id) {
                    DB::table('payroll_employees')->where('id', $p->id)->update(['employee_id' => $employeeId, 'hr_revision_at_link' => $e->hr_revision, 'hr_body_at_link' => $e->hr_body, 'revision' => $p->revision + 1, 'updated_at' => now()]);
                    $this->event($actor, 'employee', $employeeId, 'payroll_linked', ['payroll_employee_id' => $p->id, 'before' => null, 'after' => $employeeId, 'reason' => $d['reason'], 'financial_classification_unchanged' => true]);
                }

                return ['employee_id' => $employeeId, 'payroll_employee_id' => $p->id];
            });
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '19'], true)) {
                throw Failure::conflict('hr_payroll_identity_conflict', 'الهوية مرتبطة بالفعل.');
            } throw $e;
        }
    }
}
