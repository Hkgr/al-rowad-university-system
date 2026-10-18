<?php

namespace App\Services;

use App\Exceptions\AcademicCatalogException;
use App\Http\Requests\{College\StoreCollegeRequest, Department\StoreDepartmentRequest};
use App\Models\{College, Department, OrganizationalUnit, User};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{DB, Gate, Schema, Validator};
use Illuminate\Validation\{Rule, ValidationException};

/** Shared persistence for the existing CRUD and Scientific VP entity pages.
 * The catalog control lock comes first. Colleges/departments have no epoch
 * triggers in the existing SQL package, so ALL HTTP writers advance it here.
 * No new plans, organizational units, or academic decisions are created here.
 */
final class AcademicStructureEntityService
{
    public function __construct(private DataScopeService $scope, private AcademicCatalogTransaction $catalog) {}

    public function query(User $actor, string $kind)
    {
        return $kind === 'colleges' ? $this->scope->scopeColleges(College::query(), $actor)
            : $this->scope->scopeDepartments(Department::query(), $actor);
    }

    public function owns(User $actor, string $kind, ?Model $entity = null): bool
    {
        if ($actor->isSuperAdmin() || $this->scope->canAdministerUniversity($actor)) return true;
        $scopes = collect($this->scope->scopes($actor));
        if (!$entity) return false; // Creating a college is university-scoped only.
        if ($kind === 'colleges') return $scopes->contains(fn ($s) => $s['type'] === 'college' && (int) $s['id'] === (int) $entity->getKey());
        return $scopes->contains(fn ($s) => ($s['type'] === 'department' && (int) $s['id'] === (int) $entity->getKey())
            || ($s['type'] === 'college' && (int) $s['id'] === (int) $entity->college_id));
    }

    public function units(User $actor)
    {
        if ($actor->isSuperAdmin() || $this->scope->canAdministerUniversity($actor)) return OrganizationalUnit::query();
        return OrganizationalUnit::query()->where(fn ($q) => $q
            ->whereIn('organizational_unit_id', $this->query($actor, 'colleges')->select('organizational_unit_id'))
            ->orWhereIn('organizational_unit_id', $this->query($actor, 'departments')->select('organizational_unit_id')));
    }

    public function unitOptions(User $actor)
    {
        $q = $this->units($actor);
        // Academic metadata is not a new organizational-structure permission.
        // Without that existing read authority, disclose only already-linked
        // units of the actor's academic entities, never the HR/technical tree.
        if (!$actor->hasPermission('organizational_structure.view')) $q->where(fn ($units) => $units
            ->whereIn('organizational_unit_id', $this->query($actor, 'colleges')->select('organizational_unit_id'))
            ->orWhereIn('organizational_unit_id', $this->query($actor, 'departments')->select('organizational_unit_id')));
        return $q;
    }

    public function save(User $actor, string $kind, ?int $id, array $input, ?string $revision = null): Model
    {
        $class = $kind === 'colleges' ? College::class : Department::class;
        $this->authorize($actor, $class, $id ? 'update' : 'create');
        $key = $kind === 'colleges' ? 'college' : 'department';
        // Resolve selected relations in scope before uniqueness/existence errors
        // could disclose an inaccessible organizational record. Rechecked locked below.
        foreach (['college_id', 'organizational_unit_id'] as $relation) if (isset($input[$relation]) && filter_var($input[$relation], FILTER_VALIDATE_INT) !== false) {
            ($relation === 'college_id' ? $this->query($actor, 'colleges') : $this->units($actor))->findOrFail((int) $input[$relation]);
        }
        // Reuse the current form definitions; update uniqueness uses the actual
        // persisted key, not an untrusted/missing route-bound model.
        $rules = ($kind === 'colleges' ? new StoreCollegeRequest : new StoreDepartmentRequest)->rules();
        foreach ([$key.'_code', 'organizational_unit_id'] as $field) {
            $rules[$field] = $field === 'organizational_unit_id'
                ? ['sometimes', 'nullable', 'integer', Rule::unique($kind, $field)->ignore($id, $key.'_id')]
                : [$id ? 'sometimes' : 'required', 'string', 'max:50', Rule::unique($kind, $field)->ignore($id, $key.'_id')];
        }
        if ($id) foreach ([$key.'_name', 'description', 'is_active', ...($kind === 'departments' ? ['college_id'] : [])] as $field) {
            $rules[$field] = 'sometimes|'.str_replace('required|', '', $rules[$field]);
        }
        if (array_diff(array_keys($input), array_keys($rules))) throw ValidationException::withMessages(['input' => 'توجد حقول غير مسموحة.']);
        $v = Validator::make($input, $rules)->validate();
        return $this->write(function () use ($actor, $kind, $id, $v, $class, $key) {
            $entity = $id ? $this->query($actor, $kind)->lockForUpdate()->findOrFail($id) : new $class;
            $this->authorize($actor, $entity, $id ? 'update' : 'create');
            if ($kind === 'colleges' || $id) abort_unless($this->owns($actor, $kind, $id ? $entity : null), 403);
            $attributes = $v;
            foreach ([$key.'_name', $key.'_code'] as $field) if (array_key_exists($field, $attributes)) {
                $attributes[$field] = trim($attributes[$field]);
                if ($attributes[$field] === '') throw ValidationException::withMessages([$field => 'القيمة مطلوبة.']);
            }
            if ($kind === 'departments' && isset($attributes['college_id'])) {
                $parent = $this->query($actor, 'colleges')->lockForUpdate()->findOrFail($attributes['college_id']);
                // A department/program scope may view its parent; it cannot
                // create siblings or move a department into someone else's college.
                if (!$id || (int) $entity->college_id !== (int) $parent->getKey()) abort_unless($this->owns($actor, 'colleges', $parent), 403);
                if (!$id && !$parent->is_active) throw ValidationException::withMessages(['college_id' => 'اختر كلية أو معهدًا فعالًا.']);
            }
            if (isset($attributes['organizational_unit_id'])) $this->units($actor)->lockForUpdate()->findOrFail($attributes['organizational_unit_id']);
            $before = clone $entity;
            $entity->fill($attributes);
            // Preserve established academic/organizational identity when used;
            // name/description corrections and explicit activity changes remain available.
            $identityFields = ['organizational_unit_id', ...($kind === 'departments' ? ['college_id'] : [])];
            if ($id && array_intersect(array_keys($entity->getDirty()), $identityFields) && $this->used($kind, $before)) {
                throw new AcademicCatalogException('هذه العلاقات مرتبطة ببيانات قائمة؛ يمكن تصحيح الاسم والوصف دون نقلها.', 'academic_entity_identity_locked');
            }
            if (!$id || $entity->isDirty()) {
                $entity->save();
                $this->advanceRevision();
                app(ResourceAuditService::class)->record($actor->getKey(), $id ? 'updated' : 'created', $entity, array_keys($entity->getChanges()));
            }
            return $entity->fresh();
        }, $revision);
    }

    public function used(string $kind, Model $entity): bool
    {
        if ($kind === 'colleges') return $entity->departments()->exists();
        return $entity->academicPrograms()->exists() || $entity->courseDepartments()->exists() || $entity->courseOfferings()->exists();
    }

    public function delete(User $actor, string $kind, int $id, ?string $revision = null): void
    {
        $class = $kind === 'colleges' ? College::class : Department::class;
        $this->authorize($actor, $class, 'delete');
        $this->write(function () use ($actor, $kind, $id) {
            $entity = $this->query($actor, $kind)->lockForUpdate()->findOrFail($id);
            $this->authorize($actor, $entity, 'delete');
            abort_unless($this->owns($actor, $kind, $entity), 403);
            if ($this->used($kind, $entity)) throw new AcademicCatalogException('توجد ارتباطات تمنع حذف العنصر؛ تبقى بياناته قابلة للاستعراض.', 'academic_entity_delete_blocked');
            $entity->delete(); $this->advanceRevision();
            app(ResourceAuditService::class)->record($actor->getKey(), 'deleted', $entity);
        }, $revision);
    }

    private function authorize(User $actor, object|string $entity, string $ability): void
    {
        $class = is_string($entity) ? $entity : $entity::class;
        if (Gate::getPolicyFor($class)) Gate::forUser($actor)->authorize($ability, $entity);
        else app(ResourceAuthorizationService::class)->authorize($actor, $class, true);
    }

    private function write(callable $work, ?string $revision): mixed
    {
        // Legacy generic CRUD remains compatible before installation; the new
        // Scientific endpoints always require the existing catalog to be ready.
        return Schema::hasTable('academic_catalog_control') ? $this->catalog->run($work, $revision) : DB::transaction($work);
    }

    private function advanceRevision(): void
    {
        if (Schema::hasTable('academic_catalog_control')) DB::table('academic_catalog_control')->where('control_id', 1)->increment('revision');
    }
}
