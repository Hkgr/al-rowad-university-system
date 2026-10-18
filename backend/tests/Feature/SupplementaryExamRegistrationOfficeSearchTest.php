<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SupplementaryExamRegistrationOfficeController;
use App\Models\SupplementaryExamRegistration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class SupplementaryExamRegistrationOfficeSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::disableForeignKeyConstraints();
        foreach (['supplementary_exam_registrations', 'supplementary_exam_offerings', 'students', 'courses', 'academic_programs'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
        Schema::create('students', function (Blueprint $table): void {
            $table->increments('student_id');
            $table->string('student_number');
            $table->string('first_name');
            $table->string('last_name');
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->increments('course_id');
            $table->string('course_code');
            $table->string('course_name');
        });
        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->increments('academic_program_id');
            $table->string('program_code');
            $table->string('program_name');
        });
        Schema::create('supplementary_exam_offerings', function (Blueprint $table): void {
            $table->increments('supplementary_exam_offering_id');
            $table->integer('course_id');
            $table->integer('academic_program_id');
        });
        Schema::create('supplementary_exam_registrations', function (Blueprint $table): void {
            $table->increments('supplementary_exam_registration_id');
            $table->integer('supplementary_exam_offering_id');
            $table->integer('student_id');
        });

        DB::table('students')->insert([
            ['student_id' => 1, 'student_number' => 'CURRENT-1', 'first_name' => 'Current', 'last_name' => 'Student'],
            ['student_id' => 2, 'student_number' => 'OTHER-2', 'first_name' => 'needle', 'last_name' => 'Elsewhere'],
        ]);
        DB::table('courses')->insert([
            ['course_id' => 1, 'course_code' => 'CUR-100', 'course_name' => 'Current Course'],
            ['course_id' => 2, 'course_code' => 'OTHER-200', 'course_name' => 'needle'],
        ]);
        DB::table('academic_programs')->insert([
            ['academic_program_id' => 1, 'program_code' => 'CUR-P', 'program_name' => 'Current Program'],
            ['academic_program_id' => 2, 'program_code' => 'OTHER-P', 'program_name' => 'needle'],
        ]);
        DB::table('supplementary_exam_offerings')->insert([
            ['supplementary_exam_offering_id' => 1, 'course_id' => 1, 'academic_program_id' => 1],
            ['supplementary_exam_offering_id' => 2, 'course_id' => 2, 'academic_program_id' => 2],
        ]);
        DB::table('supplementary_exam_registrations')->insert([
            ['supplementary_exam_registration_id' => 1, 'supplementary_exam_offering_id' => 1, 'student_id' => 1],
            ['supplementary_exam_registration_id' => 2, 'supplementary_exam_offering_id' => 2, 'student_id' => 2],
        ]);
    }

    #[Test]
    public function unrelated_relation_rows_cannot_escape_search_relation_constraints(): void
    {
        $unrelated = SupplementaryExamRegistration::query()
            ->where('supplementary_exam_registration_id', 1);
        $this->applySearch($unrelated, 'needle');
        $this->assertSame([], $unrelated->pluck('supplementary_exam_registration_id')->all());

        $matching = SupplementaryExamRegistration::query()
            ->where('supplementary_exam_registration_id', 1);
        $this->applySearch($matching, 'CUR-100');
        $this->assertSame([1], $matching->pluck('supplementary_exam_registration_id')->all());
    }

    private function applySearch($query, string $search): void
    {
        $controller = (new ReflectionClass(SupplementaryExamRegistrationOfficeController::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SupplementaryExamRegistrationOfficeController::class, 'applySearch');
        $method->invoke($controller, $query, $search);
    }
}
