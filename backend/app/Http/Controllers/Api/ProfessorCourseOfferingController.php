<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FacultyMemberResource;
use App\Services\ProfessorGradeAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfessorCourseOfferingController extends Controller
{
    public function index(Request $request, ProfessorGradeAssignmentService $assignments): JsonResponse
    {
        $user = $request->user();
        $facultyMember = $assignments->resolveFacultyMember($user);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully',
            'data' => [
                'faculty_member' => $facultyMember === null
                    ? null
                    : (new FacultyMemberResource($facultyMember))->resolve($request),
                'offerings' => $assignments->offeringsForProfessor($user),
            ],
        ]);
    }
}
