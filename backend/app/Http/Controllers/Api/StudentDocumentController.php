<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StudentDocument\StoreStudentDocumentRequest;
use App\Http\Requests\StudentDocument\UpdateStudentDocumentRequest;
use App\Http\Resources\StudentDocumentResource;
use App\Models\Student;
use App\Models\StudentDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentDocumentController extends ApiController
{
    private const STORAGE_DISK = 'local';

    protected function modelClass(): string
    {
        return StudentDocument::class;
    }

    protected function resourceClass(): string
    {
        return StudentDocumentResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreStudentDocumentRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateStudentDocumentRequest::class;
    }

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', StudentDocument::class);

        $query = StudentDocument::query()->with('documentType');
        if (request()->user()->effectiveRoles()->contains('student')) {
            $query->where('student_id', request()->user()->student_id);
        }

        $documents = $query->paginate(request()->integer('per_page', 15));

        return $this->successResponse(
            StudentDocumentResource::collection($documents)->response(request())->getData(true)
        );
    }

    public function upload(Student $student, Request $request): JsonResponse
    {
        Gate::authorize('createFor', [StudentDocument::class, $student]);
        $rules = [
            'document_type_id' => ['required', 'integer', 'exists:document_types,document_type_id'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
        if ($request->user()->student_id === null) {
            $rules['verification_notes'] = ['nullable', 'string', 'max:255'];
        }
        $validated = $request->validate($rules);

        $file = $request->file('file');
        $originalFileName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $storedFileName = uniqid('doc_', true).'.'.$extension;
        $directory = 'students/'.$student->student_number.'/documents';

        $storedPath = Storage::disk(self::STORAGE_DISK)->putFileAs(
            $directory,
            $file,
            $storedFileName
        );

        if ($storedPath === false) {
            return $this->errorResponse('Unable to store the uploaded file.', [], 500);
        }

        $document = StudentDocument::query()->create([
            'student_id' => $student->student_id,
            'document_type_id' => $validated['document_type_id'],
            'file_name' => $originalFileName,
            'file_url' => $storedPath,
            'verification_status' => 'pending',
            'verified_by_user_id' => null,
            'verified_at' => null,
            'verification_notes' => $request->user()->student_id === null
                ? ($validated['verification_notes'] ?? null)
                : null,
            'uploaded_at' => now(),
        ]);

        $document->load('documentType');

        return $this->successResponse(
            (new StudentDocumentResource($document))->resolve($request),
            'Student document uploaded successfully',
            201
        );
    }

    public function download(StudentDocument $studentDocument): StreamedResponse|JsonResponse
    {
        Gate::authorize('view', $studentDocument);
        $path = $studentDocument->file_url;

        if ($path === null || $path === '' || ! Storage::disk(self::STORAGE_DISK)->exists($path)) {
            return $this->errorResponse('File not found.', [], 404);
        }

        return Storage::disk(self::STORAGE_DISK)->download(
            $path,
            $studentDocument->file_name
        );
    }

    public function destroy($id): JsonResponse
    {
        $document = StudentDocument::query()->findOrFail($id);
        Gate::authorize('delete', $document);

        $this->deleteStoredFile($document);
        $document->delete();

        return $this->successResponse(
            [],
            'Operation completed successfully'
        );
    }

    private function deleteStoredFile(StudentDocument $document): void
    {
        $path = $document->file_url;

        if ($path === null || $path === '') {
            return;
        }

        if (Storage::disk(self::STORAGE_DISK)->exists($path)) {
            Storage::disk(self::STORAGE_DISK)->delete($path);
        }
    }
}
