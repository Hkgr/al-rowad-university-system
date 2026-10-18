<?php

namespace Tests\Feature;

use App\Http\Requests\SupplementaryExamGrading\SaveSupplementaryExamGradesRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplementaryExamTheoreticalOnlyRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('student_grade_components');
        Schema::dropIfExists('grade_components');
        Schema::create('grade_components', function (Blueprint $table): void {
            $table->increments('grade_component_id');
            $table->string('component_type', 16);
        });
        Schema::create('student_grade_components', function (Blueprint $table): void {
            $table->increments('student_grade_component_id');
            $table->integer('grade_component_id');
            $table->decimal('mark', 5, 2);
            $table->string('grade_status', 16);
            $table->timestamp('updated_at');
        });
        DB::table('grade_components')->insert([
            'grade_component_id' => 2,
            'component_type' => 'practical',
        ]);
        DB::table('student_grade_components')->insert([
            'student_grade_component_id' => 1,
            'grade_component_id' => 2,
            'mark' => 30,
            'grade_status' => 'approved',
            'updated_at' => '2026-01-01 10:00:00',
        ]);

        Route::put('/_test/supplementary-theoretical-only', function (SaveSupplementaryExamGradesRequest $request) {
            DB::table('student_grade_components')->update([
                'mark' => 0,
                'updated_at' => now(),
            ]);

            return response()->json($request->validated());
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('student_grade_components');
        Schema::dropIfExists('grade_components');
        parent::tearDown();
    }

    #[Test]
    public function practical_like_or_arbitrary_fields_return_422_before_any_mutation(): void
    {
        $before = $this->practicalRows();

        foreach ([
            'practical_mark', 'practical_total', 'practical',
            'practical_components', 'components', 'arbitrary_field',
        ] as $unknownField) {
            $response = $this->putJson('/_test/supplementary-theoretical-only', [
                'marks' => [[
                    'supplementary_exam_registration_id' => 700,
                    'theoretical_mark' => 40,
                    $unknownField => 99,
                ]],
            ]);
            $response->assertUnprocessable()->assertJsonValidationErrors('marks.0');
        }

        $this->putJson('/_test/supplementary-theoretical-only', [
            'marks' => [[
                'supplementary_exam_registration_id' => 700,
                'theoretical_mark' => 40,
            ]],
            'unexpected' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('unexpected');

        $after = $this->practicalRows();
        $this->assertSame($before, $after);
    }

    #[Test]
    public function documented_theoretical_only_shape_is_accepted_without_extra_fields(): void
    {
        $this->putJson('/_test/supplementary-theoretical-only', [
            'marks' => [[
                'supplementary_exam_registration_id' => 700,
                'theoretical_mark' => 40,
            ]],
        ])->assertOk()->assertExactJson(['marks' => [[
            'supplementary_exam_registration_id' => 700,
            'theoretical_mark' => 40,
        ]] ]);
    }

    /** @return list<array<string, mixed>> */
    private function practicalRows(): array
    {
        return DB::table('student_grade_components as grades')
            ->join('grade_components as components', 'components.grade_component_id', '=', 'grades.grade_component_id')
            ->where('components.component_type', 'practical')
            ->orderBy('grades.student_grade_component_id')
            ->get([
                'grades.student_grade_component_id', 'grades.grade_component_id', 'grades.mark',
                'grades.grade_status', 'grades.updated_at',
            ])->map(fn (object $row): array => (array) $row)->all();
    }
}
