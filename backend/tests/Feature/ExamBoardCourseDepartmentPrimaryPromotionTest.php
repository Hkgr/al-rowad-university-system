<?php

namespace Tests\Feature;

use App\Http\Requests\CourseDepartment\StoreCourseDepartmentRequest;
use App\Http\Requests\CourseDepartment\UpdateCourseDepartmentRequest;
use App\Models\CourseDepartment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ExamBoardCourseDepartmentPrimaryPromotionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createSchema();
    }

    public function test_scalar_route_id_promotes_the_only_department_link(): void
    {
        DB::table('courses')->insert(['course_id' => 1]);
        DB::table('departments')->insert(['department_id' => 18]);

        self::assertSame(0, CourseDepartment::query()->where('course_id', 1)->count());

        $storeRequest = StoreCourseDepartmentRequest::create('/api/v1/course-departments', 'POST', [
            'course_id' => 1,
            'department_id' => 18,
            'is_primary' => false,
        ]);
        $storeValidator = Validator::make($storeRequest->all(), $storeRequest->rules());
        self::assertTrue($storeValidator->passes(), $storeValidator->errors()->toJson());

        $link = CourseDepartment::query()->create($storeValidator->validated());
        self::assertFalse($link->is_primary);

        $request = $this->updateRequest((int) $link->course_department_id, [
            'is_primary' => true,
        ]);
        self::assertSame((string) $link->course_department_id, $request->route('course_department'));

        $validator = Validator::make($request->all(), $request->rules());
        self::assertTrue($validator->passes(), $validator->errors()->toJson());

        CourseDepartment::query()
            ->findOrFail((int) $request->route('course_department'))
            ->update($validator->validated());

        $reloaded = CourseDepartment::query()->where('course_id', 1)->get();
        self::assertCount(1, $reloaded);
        self::assertTrue($reloaded->sole()->is_primary);
    }

    public function test_scalar_route_id_keeps_course_department_uniqueness_when_department_is_omitted(): void
    {
        DB::table('courses')->insert([
            ['course_id' => 1],
            ['course_id' => 2],
        ]);
        DB::table('departments')->insert(['department_id' => 18]);
        CourseDepartment::query()->create([
            'course_id' => 1,
            'department_id' => 18,
            'is_primary' => true,
        ]);
        $second = CourseDepartment::query()->create([
            'course_id' => 2,
            'department_id' => 18,
            'is_primary' => true,
        ]);

        $request = $this->updateRequest((int) $second->course_department_id, [
            'course_id' => 1,
        ]);
        $validator = Validator::make($request->all(), $request->rules());

        self::assertTrue($validator->fails());
        self::assertArrayHasKey('course_id', $validator->errors()->toArray());
    }

    /** @param array<string, mixed> $payload */
    private function updateRequest(int $courseDepartmentId, array $payload): UpdateCourseDepartmentRequest
    {
        $request = UpdateCourseDepartmentRequest::create(
            "/api/v1/course-departments/{$courseDepartmentId}",
            'PUT',
            $payload,
        );
        $request->setRouteResolver(static fn () => new class ((string) $courseDepartmentId) {
            public function __construct(private readonly string $courseDepartmentId)
            {
            }

            public function parameter(string $key, mixed $default = null): mixed
            {
                return $key === 'course_department' ? $this->courseDepartmentId : $default;
            }
        });

        return $request;
    }

    private function createSchema(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->increments('course_id');
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->increments('department_id');
        });
        Schema::create('course_departments', function (Blueprint $table): void {
            $table->increments('course_department_id');
            $table->integer('course_id');
            $table->integer('department_id');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->unique(['course_id', 'department_id']);
        });
    }
}
