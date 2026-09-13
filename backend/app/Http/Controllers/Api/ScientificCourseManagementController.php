<?php

namespace App\Http\Controllers\Api;

use App\Services\ScientificCourseManagementService;
use Illuminate\Http\Request;

final class ScientificCourseManagementController extends \App\Http\Controllers\Controller
{
    private function successResponse(array $data) { return response()->json(['success' => true, 'data' => $data]); }
    public function __construct(private ScientificCourseManagementService $catalog) {}
    public function index(Request $r) { return $this->successResponse($this->catalog->listing($r->user(), $r->query())); }
    public function options(Request $r) { return $this->successResponse($this->catalog->options($r->user(), $r->query())); }
    public function show(Request $r, int $course) { return $this->successResponse($this->catalog->course($r->user(), $course)); }
    public function createCourse(Request $r) { return $this->successResponse($this->catalog->saveCourse($r->user(), null, $r->all())); }
    public function updateCourse(Request $r, int $course) { return $this->successResponse($this->catalog->saveCourse($r->user(), $course, $r->all())); }
    public function deleteCourse(Request $r, int $course) { return $this->successResponse($this->catalog->deleteCourse($r->user(), $course, $r->all())); }
    public function program(Request $r, int $program) { return $this->successResponse($this->catalog->program($r->user(), $program)); }
    public function groups(Request $r, int $program) { return $this->successResponse($this->catalog->saveGroups($r->user(), $program, $r->all())); }
    public function membership(Request $r, int $program, int $course) { return $this->successResponse($this->catalog->saveMembership($r->user(), $program, $course, $r->all(), $r->isMethod('delete'))); }
}
