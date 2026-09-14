<?php

namespace App\Http\Controllers\Api;

use App\Services\{AcademicPlanWorkflow, ScientificProgramManagementService};
use Illuminate\Http\Request;

final class ScientificProgramManagementController extends \App\Http\Controllers\Controller
{
    public function __construct(private ScientificProgramManagementService $programs, private AcademicPlanWorkflow $plans) {}
    private function response(array $data) { return response()->json(['success' => true, 'data' => $data]); }
    public function index(Request $r) { return $this->response($this->programs->listing($r->user(), $r->query())); }
    public function options(Request $r) { return $this->response($this->programs->options($r->user(), $r->query())); }
    public function createProgram(Request $r) { return $this->response($this->programs->save($r->user(), null, $r->all())); }
    public function show(Request $r, int $program) { return $this->response($this->programs->detail($r->user(), $program)); }
    public function updateProgram(Request $r, int $program) { return $this->response($this->programs->save($r->user(), $program, $r->all())); }
    public function impact(Request $r, int $program) { return $this->response($this->programs->impact($r->user(), $program)); }
    public function deleteProgram(Request $r, int $program) { return $this->response($this->programs->delete($r->user(), $program, $r->all())); }
    public function archive(Request $r, int $program) { return $this->response($this->programs->archive($r->user(), $program, $r->all())); }
    public function restore(Request $r, int $program) { return $this->response($this->programs->archive($r->user(), $program, $r->all(), true)); }
    public function begin(Request $r, int $program) { return $this->response($this->plans->begin($r->user(), $program, $r->all())); }
    public function previewTransition(Request $r, int $program) { return $this->response($this->plans->previewTransition($r->user(), $program)); }
    public function fixTransition(Request $r, int $program) { return $this->response($this->plans->fixTransition($r->user(), $program, $r->all())); }
    public function version(Request $r, int $program, int $version) { return $this->response($this->plans->version($r->user(), $program, $version)); }
    public function copy(Request $r, int $program, int $version) { return $this->response($this->plans->copy($r->user(), $program, $version, $r->all())); }
    public function requirements(Request $r, int $program, int $version) { return $this->response($this->plans->saveRequirements($r->user(), $program, $version, $r->all())); }
    public function membership(Request $r, int $program, int $version, int $course) { return $this->response($this->plans->saveMembership($r->user(), $program, $version, $course, $r->all(), $r->isMethod('delete'))); }
    public function approve(Request $r, int $program, int $version) { return $this->response($this->plans->approve($r->user(), $program, $version, $r->all())); }
    public function setDefault(Request $r, int $program, int $version) { return $this->response($this->plans->setDefault($r->user(), $program, $version, $r->all())); }
    public function previewTransfer(Request $r, int $program, int $version) { return $this->response($this->plans->previewTransfer($r->user(), $program, $version, $r->all())); }
    public function transfer(Request $r, int $program, int $version) { return $this->response($this->plans->transfer($r->user(), $program, $version, $r->all())); }
}
