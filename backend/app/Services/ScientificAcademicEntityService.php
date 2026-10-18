<?php

namespace App\Services;

use App\Models\User;
use App\Support\ScientificProgramAccess;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Narrow Scientific VP read/capability facade over the existing structure. */
final class ScientificAcademicEntityService
{
    public function __construct(private ScientificProgramAccess $access, private AcademicStructureEntityService $entities,
        private AcademicCatalogTransaction $catalog) {}

    public function listing(User $actor, string $kind, array $input): array
    {
        $this->access->authorize($actor);
        $v = $this->validate($input, ['q' => 'sometimes|nullable|string|max:200', 'college_id' => 'sometimes|integer|min:1',
            'status' => 'sometimes|in:active,inactive', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        return $this->catalog->snapshot(function () use ($actor, $kind, $v) {
            if (isset($v['college_id'])) $this->entities->query($actor, 'colleges')->findOrFail($v['college_id']);
            $query = $this->entities->query($actor, $kind)->with($kind === 'colleges' ? 'organizationalUnit' : ['college', 'organizationalUnit']);
            $prefix = $kind === 'colleges' ? 'college' : 'department';
            if (!empty($v['q'])) $query->where(fn ($q) => $q->where($prefix.'_name', 'like', '%'.trim($v['q']).'%')->orWhere($prefix.'_code', 'like', '%'.trim($v['q']).'%'));
            if ($kind === 'departments' && isset($v['college_id'])) $query->where('college_id', $v['college_id']);
            if (isset($v['status'])) $query->where('is_active', $v['status'] === 'active');
            $page = $query->orderBy($prefix.'_name')->orderBy($prefix.'_id')->paginate($v['per_page'] ?? 20, ['*'], 'page', $v['page'] ?? 1);
            $create = $actor->hasPermission(ScientificProgramAccess::MANAGE) && ($this->entities->owns($actor, 'colleges')
                || ($kind === 'departments' && collect(app(DataScopeService::class)->scopes($actor))->contains(fn ($s) => $s['type'] === 'college')));
            return ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
                'capabilities' => ['create' => $create], 'revision' => $this->catalog->revision()];
        });
    }

    public function detail(User $actor, string $kind, int $id): array
    {
        $this->access->authorize($actor);
        return $this->catalog->snapshot(function () use ($actor, $kind, $id) {
            $entity = $this->entities->query($actor, $kind)->with($kind === 'colleges' ? 'organizationalUnit' : ['college', 'organizationalUnit'])->findOrFail($id);
            $edit = $actor->hasPermission(ScientificProgramAccess::MANAGE) && $this->entities->owns($actor, $kind, $entity);
            $used = $this->entities->used($kind, $entity);
            return ['entity' => $entity, 'revision' => $this->catalog->revision(), 'capabilities' => [
                'edit' => $edit, 'edit_relationships' => $edit && !$used, 'delete' => $edit && !$used,
                'create_child' => $edit && (bool) $entity->is_active,
                'create_child_lock_reason' => !$edit ? 'إضافة عنصر تتطلب صلاحية الإدارة ونطاق العنصر الأب نفسه.' : (!$entity->is_active ? 'العنصر غير فعّال؛ لا يمكن إضافة قسم أو برنامج إليه.' : null),
                'edit_lock_reason' => $edit ? null : 'قراءة العنصر الأب لا تمنح صلاحية تعديله أو إضافة عناصر إلى نطاقه.',
                'relationship_lock_reason' => $used ? 'العلاقات مستخدمة في بيانات قائمة؛ يسمح بالتصحيح النصي دون نقلها.' : null,
                'delete_lock_reason' => $used ? 'توجد عناصر أو ارتباطات أكاديمية تمنع الحذف.' : null,
            ]];
        });
    }

    public function save(User $actor, string $kind, ?int $id, array $input): array
    {
        $this->access->authorize($actor, ScientificProgramAccess::MANAGE);
        $v = Validator::make($input, ['revision' => 'required|string|regex:/^[0-9]+$/'])->validate();
        return $this->catalog->run(function () use ($actor, $kind, $id, $input) {
            $this->access->authorize($actor, ScientificProgramAccess::MANAGE);
            $entity = $this->entities->save($actor, $kind, $id, array_diff_key($input, ['revision' => true]));
            return $this->detail($actor, $kind, (int) $entity->getKey());
        }, $v['revision']);
    }

    public function delete(User $actor, string $kind, int $id, array $input): array
    {
        $this->access->authorize($actor, ScientificProgramAccess::MANAGE);
        $v = $this->validate($input, ['revision' => 'required|string|regex:/^[0-9]+$/', 'confirmed' => 'required|accepted']);
        return $this->catalog->run(function () use ($actor, $kind, $id) {
            $this->access->authorize($actor, ScientificProgramAccess::MANAGE);
            $this->entities->delete($actor, $kind, $id);
            return ['deleted' => true, 'revision' => $this->catalog->revision()];
        }, $v['revision']);
    }

    public function units(User $actor, array $input): array
    {
        $this->access->authorize($actor);
        $v = $this->validate($input, ['q' => 'sometimes|nullable|string|max:150', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        return $this->catalog->snapshot(function () use ($actor, $v) {
            $q = $this->entities->unitOptions($actor);
            if (!empty($v['q'])) $q->where(fn ($q) => $q->where('unit_name', 'like', '%'.trim($v['q']).'%')->orWhere('unit_code', 'like', '%'.trim($v['q']).'%'));
            $page = $q->orderBy('unit_name')->orderBy('organizational_unit_id')->paginate($v['per_page'] ?? 20, ['*'], 'page', $v['page'] ?? 1);
            return ['data' => collect($page->items())->map(fn ($u) => ['id' => $u->getKey(), 'label' => $u->unit_name.' ('.$u->unit_code.')']),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]];
        });
    }

    private function validate(array $input, array $rules): array
    {
        if (array_diff(array_keys($input), array_keys($rules))) throw ValidationException::withMessages(['input' => 'توجد حقول غير مسموحة.']);
        return Validator::make($input, $rules)->validate();
    }
}
