<?php

namespace App\Http\Controllers\Api;

use App\Services\ScientificAcademicEntityService;
use Illuminate\Http\Request;

final class ScientificAcademicEntityController extends \App\Http\Controllers\Controller
{
    public function __construct(private ScientificAcademicEntityService $entities) {}
    private function response(array $data) { return response()->json(['success' => true, 'data' => $data]); }
    public function index(Request $r, string $kind) { return $this->response($this->entities->listing($r->user(), $kind, $r->query())); }
    public function show(Request $r, string $kind, int $entity) { return $this->response($this->entities->detail($r->user(), $kind, $entity)); }
    public function store(Request $r, string $kind) { return $this->response($this->entities->save($r->user(), $kind, null, $r->all())); }
    public function update(Request $r, string $kind, int $entity) { return $this->response($this->entities->save($r->user(), $kind, $entity, $r->all())); }
    public function destroy(Request $r, string $kind, int $entity) { return $this->response($this->entities->delete($r->user(), $kind, $entity, $r->all())); }
    public function units(Request $r) { return $this->response($this->entities->units($r->user(), $r->query())); }
}
