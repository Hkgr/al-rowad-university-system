<?php

require __DIR__.'/CatalogEnvironment.php';
$environment = new CatalogEnvironment;
$app = $environment->laravel();
$db = \Illuminate\Support\Facades\DB::class;
$p = $db::connection()->getPdo();
$command = $argv[1] ?? '';
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
$check = static function ($value, string $message): void { if (!$value) throw new RuntimeException($message); };
$package = fn ($file) => $environment->package($p, $file, null, 'elective-advisory-placement');
$expectPackage = function ($file, $expected) use ($package, $check) { $rows = $package($file); $check(end($rows)['result'] === $expected, json_encode($rows)); };
$tx = app(\App\Services\AcademicCatalogTransaction::class);
$workflow = app(\App\Services\AcademicPlanWorkflow::class);
$service = app(\App\Services\CatalogCompletion\CatalogCompletionService::class);
$manifest = new \App\Services\CatalogCompletion\ApprovedCatalogManifest;
$actor = \App\Models\User::findOrFail(1);
$revision = fn () => $tx->revision();
$confirm = fn () => ['revision' => $revision(), 'confirmed' => true];
if ($command === 'install') { $expectPackage('01_apply.sql', 'APPLIED'); $expectPackage('02_verify.sql', 'PASS'); exit; }

$rows = fn () => $p->query('SELECT * FROM program_courses ORDER BY program_course_id')->fetchAll(PDO::FETCH_ASSOC);
$objects = function () use ($p): array {
    return [
        $p->query('SELECT trigger_name,event_object_table,event_manipulation,action_timing,action_statement FROM information_schema.triggers WHERE trigger_schema=DATABASE() ORDER BY trigger_name')->fetchAll(PDO::FETCH_ASSOC),
        $p->query('SELECT routine_name,routine_type,routine_definition,data_type FROM information_schema.routines WHERE routine_schema=DATABASE() ORDER BY routine_name')->fetchAll(PDO::FETCH_ASSOC),
        $p->query("SELECT constraint_name,column_name,ordinal_position,referenced_table_schema,referenced_table_name,referenced_column_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='program_courses' ORDER BY constraint_name,ordinal_position")->fetchAll(PDO::FETCH_ASSOC),
        $p->query("SELECT constraint_name,update_rule,delete_rule FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE() AND table_name='program_courses' ORDER BY constraint_name")->fetchAll(PDO::FETCH_ASSOC),
        $p->query("SELECT index_name,non_unique,seq_in_index,column_name,index_type FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='program_courses' ORDER BY index_name,seq_in_index")->fetchAll(PDO::FETCH_ASSOC),
        $p->query("SELECT engine,table_collation,create_options,table_comment FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='program_courses'")->fetchAll(PDO::FETCH_ASSOC),
    ];
};
$columns = fn () => $p->query("SELECT column_name,column_type,is_nullable,column_default,extra,column_comment,generation_expression FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='program_courses' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
if ($command === 'schema-tests') {
    echo 'SERVER_VERSION='.$p->query('SELECT VERSION()')->fetchColumn()."\n";
    $check((int) $p->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='program_courses' AND column_name IN('academic_level_id','recommended_semester_id') AND is_nullable='NO'")->fetchColumn() === 2, 'Initial fixture must genuinely be NOT NULL');
    $before = $rows(); $definitions = $objects(); $columnBefore = $columns(); $epoch = $revision();
    // Actual old accepted payload at its DB boundary, in a legitimate catalog-first draft transaction.
    try {
        $tx->run(function () use ($workflow, $actor, $confirm, $revision, $db) {
            $source = $workflow->fixTransition($actor, 40, $confirm())['current_version_id'];
            $copy = $workflow->copy($actor, 40, $source, ['revision' => $revision(), 'label' => 'Synthetic original 1048']);
            $db::table('program_courses')->insert(['academic_program_id' => 40, 'academic_plan_version_id' => $copy['version']->getKey(),
                'course_id' => 409, 'course_type' => 'elective', 'academic_level_id' => null, 'recommended_semester_id' => null, 'is_active' => true]);
        });
        throw new RuntimeException('Original NOT NULL insert unexpectedly succeeded');
    } catch (\Illuminate\Database\QueryException $e) {
        $check((int) ($e->errorInfo[1] ?? 0) === 1048 && str_contains($e->getMessage(), 'academic_level_id'), $e->getMessage());
    }
    $check($before === $rows() && $epoch === $revision() && !$db::table('academic_plan_versions')->exists(), '1048 transaction leaked history or assumed an allocated version committed');
    $preview = $service->preview($actor, $manifest);
    $check($preview['status'] === 'BLOCKED' && !$preview['schema_prerequisites']['elective_advisory_placement']['ready'], 'Preview hid schema prerequisite');
    try { $service->apply($actor, $manifest, $revision(), (string) \Illuminate\Support\Str::uuid()); throw new RuntimeException('Unready apply accepted'); }
    catch (\App\Exceptions\AcademicPlanException $e) { $check($e->errorCode === 'academic_placement_schema_not_ready', $e->getMessage()); }
    $check(!$db::table('academic_plan_versions')->exists() && !$db::table('user_activity_logs')->exists(), 'Unready apply started a new stage/receipt');
    $expectPackage('00_preflight.sql', 'READY'); $expectPackage('02_verify.sql', 'FAIL');
    $expectPackage('01_apply.sql', 'APPLIED'); $expectPackage('02_verify.sql', 'PASS');
    $check($before === $rows() && $definitions === $objects(), 'Upgrade changed existing rows/timestamps/keys/engines/guards');
    $afterColumns = $columns();
    foreach ($columnBefore as $i => $column) {
        if (in_array($column['column_name'], ['academic_level_id', 'recommended_semester_id'], true)) { $column['is_nullable'] = 'YES'; $column['column_default'] = 'NULL'; }
        $check($column === $afterColumns[$i], 'Unexpected column metadata change: '.$column['column_name']);
    }
    $installed = $columns(); $expectPackage('01_apply.sql', 'APPLIED');
    $check($installed === $columns() && $definitions === $objects() && $before === $rows(), 'Reapplication changed state');
    $expectPackage('03_rollback.sql', 'RESTORED_NOT_NULL');
    foreach (['academic_level_id', 'recommended_semester_id'] as $partialColumn) {
        $p->exec("ALTER TABLE program_courses MODIFY $partialColumn INT NULL DEFAULT NULL");
        $expectPackage('00_preflight.sql', 'READY'); $expectPackage('02_verify.sql', 'FAIL');
        $expectPackage('01_apply.sql', 'APPLIED'); $expectPackage('02_verify.sql', 'PASS');
        $expectPackage('03_rollback.sql', 'RESTORED_NOT_NULL');
    }
    $expectPackage('01_apply.sql', 'APPLIED');
    $check($definitions === $objects() && $before === $rows(), 'Partial resume lost an original object/row');
    $reject = function () use ($expectPackage, $package, $rows, $objects, $check): void {
        $data = $rows(); $schema = $objects();
        $expectPackage('00_preflight.sql', 'BLOCKED'); $expectPackage('02_verify.sql', 'FAIL');
        $check(!\App\Services\AcademicPlacementSchema::inspect()['ready'], 'Runtime accepted an incompatible schema');
        try { $package('01_apply.sql'); throw new RuntimeException('Unknown schema accepted'); }
        catch (PDOException $e) { $check(str_contains($e->getMessage(), 'elective_placement_incompatible_schema_or_data'), $e->getMessage()); }
        $check($data === $rows() && $schema === $objects(), 'Rejected upgrade overwrote data or an unrecognized object');
    };
    $trigger = $p->query("SELECT action_statement FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='sc_cat_program_courses_i'")->fetchColumn();
    $p->exec('CREATE OR REPLACE TRIGGER sc_cat_program_courses_i BEFORE INSERT ON program_courses FOR EACH ROW BEGIN DO 1; END');
    $reject(); $p->exec('CREATE OR REPLACE TRIGGER sc_cat_program_courses_i BEFORE INSERT ON program_courses FOR EACH ROW '.$trigger);
    $p->exec("ALTER TABLE program_courses MODIFY academic_level_id INT NULL DEFAULT NULL COMMENT 'unrecognized definition'");
    $reject(); $p->exec('ALTER TABLE program_courses MODIFY academic_level_id INT NULL DEFAULT NULL');
    $foreign = $p->query("SELECT constraint_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='program_courses' AND column_name='academic_level_id' AND referenced_table_name='academic_levels'")->fetchColumn();
    $p->exec("ALTER TABLE program_courses DROP FOREIGN KEY `$foreign`, MODIFY academic_level_id BIGINT NULL DEFAULT NULL");
    $reject(); $p->exec("ALTER TABLE program_courses MODIFY academic_level_id INT NULL DEFAULT NULL, ADD CONSTRAINT `$foreign` FOREIGN KEY(academic_level_id) REFERENCES academic_levels(academic_level_id) ON DELETE RESTRICT ON UPDATE RESTRICT");
    $p->exec("ALTER TABLE program_courses DROP FOREIGN KEY `$foreign`");
    $p->exec("ALTER TABLE program_courses ADD CONSTRAINT `$foreign` FOREIGN KEY(academic_level_id) REFERENCES academic_levels(academic_level_id) ON DELETE CASCADE");
    $reject(); $p->exec("ALTER TABLE program_courses DROP FOREIGN KEY `$foreign`");
    $p->exec("ALTER TABLE program_courses ADD CONSTRAINT `$foreign` FOREIGN KEY(academic_level_id) REFERENCES academic_levels(academic_level_id) ON DELETE RESTRICT ON UPDATE RESTRICT");
    // MariaDB may recreate its implicit FK index under the constraint's name after the type probe.
    // Restore the test's original index name too; the actual repair never drops any FK/index.
    foreach ($definitions[4] as $index) if ($index['column_name'] === 'academic_level_id' && (int)$index['non_unique'] === 1 && $index['index_name'] !== $foreign) {
        $p->exec("ALTER TABLE program_courses RENAME INDEX `$foreign` TO `{$index['index_name']}`");
        break;
    }
    $clause = $p->query("SELECT check_clause FROM information_schema.check_constraints WHERE constraint_schema=DATABASE() AND table_name='program_courses' AND constraint_name='chk_program_course_advisory_placement'")->fetchColumn();
    $p->exec('ALTER TABLE program_courses DROP CONSTRAINT chk_program_course_advisory_placement, ADD CONSTRAINT chk_program_course_advisory_placement CHECK(1)');
    $reject(); $p->exec('ALTER TABLE program_courses DROP CONSTRAINT chk_program_course_advisory_placement, ADD CONSTRAINT chk_program_course_advisory_placement CHECK('.$clause.')');
    $p->exec('ALTER TABLE program_courses DROP INDEX uq_program_plan_course, ADD UNIQUE INDEX uq_program_plan_course(academic_program_id,plan_scope_key,course_id,academic_level_id)');
    $reject(); $p->exec('ALTER TABLE program_courses DROP INDEX uq_program_plan_course, ADD UNIQUE INDEX uq_program_plan_course(academic_program_id,plan_scope_key,course_id)');
    $expectPackage('02_verify.sql', 'PASS');
    // Constraint-first interruption: the two columns remain NOT NULL until the second step finishes.
    $p->exec('ALTER TABLE program_courses MODIFY academic_level_id INT NOT NULL, MODIFY recommended_semester_id INT NOT NULL');
    $expectPackage('00_preflight.sql', 'READY'); $expectPackage('02_verify.sql', 'FAIL');
    $expectPackage('01_apply.sql', 'APPLIED'); $expectPackage('02_verify.sql', 'PASS');
    try {
        $tx->run(function () use ($workflow, $actor, $confirm, $revision, $db, $package, $check) {
            $source = $workflow->fixTransition($actor, 40, $confirm())['current_version_id'];
            $copy = $workflow->copy($actor, 40, $source, ['revision' => $revision(), 'label' => 'Synthetic placement validation']);
            $version = $copy['version']->getKey();
            $workflow->saveRequirements($actor, 40, $version, ['revision' => $revision(), 'total_credit_hours' => 3,
                'groups' => collect(['university','college','department'])->flatMap(fn ($scope) => collect(['mandatory','elective'])->map(fn ($type) => [
                    'requirement_scope' => $scope, 'requirement_type' => $type, 'required_credit_hours' => $scope === 'university' && $type === 'elective' ? 3 : 0, 'is_active' => true]))->all()]);
            $workflow->saveMembership($actor, 40, $version, 409, ['revision' => $revision(), 'course_type' => 'elective',
                'requirement_scope' => 'university', 'academic_level_id' => null, 'recommended_semester_id' => null, 'is_active' => true]);
            foreach ([['mandatory',null,null],['elective',1,null],['elective',null,1]] as [$type,$level,$semester]) {
                try { $workflow->saveMembership($actor, 40, $version, 403, ['revision' => $revision(), 'course_type' => $type,
                    'requirement_scope' => 'university', 'academic_level_id' => $level, 'recommended_semester_id' => $semester, 'is_active' => true]); throw new RuntimeException('Invalid application placement accepted'); }
                catch (\Illuminate\Validation\ValidationException $e) { $check(isset($e->errors()['academic_level_id']), 'Wrong validation error'); }
                // Real CHECK enforcement, independently of PHP validation.
                try { $db::table('program_courses')->insert(['academic_program_id' => 40, 'academic_plan_version_id' => $version, 'course_id' => 403,
                    'course_type' => $type, 'academic_level_id' => $level, 'recommended_semester_id' => $semester]); throw new RuntimeException('Invalid SQL placement accepted'); }
                catch (\Illuminate\Database\QueryException $e) { $check((int) ($e->errorInfo[1] ?? 0) === 4025, $e->getMessage()); }
            }
            throw new RuntimeException('rollback_placement_probe');
        });
    } catch (RuntimeException $e) { if ($e->getMessage() !== 'rollback_placement_probe') throw $e; }
    $check($before === $rows(), 'Validation probes changed pre-existing row contents');
    foreach ($objects() as $i => $objectRows) {
        $difference = array_values(array_udiff($objectRows,$definitions[$i],fn ($a,$b) => strcmp(json_encode($a),json_encode($b))));
        $check($definitions[$i] === $objectRows, 'Validation probes changed schema group '.$i.': '.json_encode($difference));
    }
    echo "PASS real 1048/atomic failed insert/early preview-apply guard/upgrade/reapply/partial columns and CHECK-first/negative definitions/PHP and real CHECK validation/schema and row preservation\n";
    exit;
}
if ($command === 'incident-resume') {
    // Populated immutable decision payloads, not count-only preservation assertions.
    foreach (['student_progression_decisions','student_graduation_decisions'] as $table) {
        $p->exec("ALTER TABLE $table ADD COLUMN outcome_snapshot LONGTEXT NULL");
        $tx->run(fn () => $db::table($table)->insert(['student_id'=>1028,'status'=>'approved','current_slot'=>0,
            'outcome_snapshot'=>json_encode(['synthetic'=>true,'gpa'=>'3.00','earned_credits'=>7,'decision'=>'historical']),
            'updated_at'=>'2025-04-02 12:00:00']));
    }
    $material = function () use ($p): array {
        $snapshot = [];
        foreach (['student_course_registrations','student_course_results','student_grade_components','grade_approvals',
            'grade_part_approvals','grade_part_approval_events','grade_audit_logs','student_progression_decisions','student_graduation_decisions'] as $table) {
            $snapshot[$table] = array_map(function ($row) { unset($row['academic_plan_version_id'],$row['plan_program_course_id']); return $row; },
                $p->query("SELECT * FROM $table ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC));
        }
        $snapshot['announcements'] = $p->query('SELECT course_offering_id,result_announced_at FROM course_offerings ORDER BY course_offering_id')->fetchAll(PDO::FETCH_ASSOC);
        return $snapshot;
    };
    $materialBefore = $material();
    $grades = app(\App\Services\GradeService::class);
    $student = fn () => \App\Models\Student::findOrFail(1040);
    $gpaBefore = $grades->calculateGpa($student(),1,1);
    $transcriptBefore = $grades->getTranscript($student());
    // Incident-shaped synthetic groups, not a production execution list or the live operation UUID.
    $earlier = [19,20,32,33,34,38];
    $tx->run(function () use ($manifest, $earlier, $db) {
        foreach ($manifest->programs() as $definition) if (in_array((int) $definition['program_id'], $earlier, true))
            $db::table('academic_programs')->where('academic_program_id', $definition['program_id'])->update(['program_name' => $definition['current_name']]);
    });
    $operation = (string) \Illuminate\Support\Str::uuid();
    $armed = true;
    $db::listen(function ($q) use (&$armed, $operation, $db) {
        if ($armed && str_starts_with($q->sql, 'select') && in_array(40, $q->bindings, true)
            && $db::table('academic_plan_events')->where('action', 'catalog_completion_published')->where('context->operation_id', $operation)->count() === 6) {
            $armed = false; throw new RuntimeException('synthetic_interruption_before_sports');
        }
    });
    try { $service->apply($actor, $manifest, $revision(), $operation); throw new RuntimeException('Expected interruption after six publications'); }
    catch (RuntimeException $e) { $check($e->getMessage() === 'synthetic_interruption_before_sports', $e->getMessage()); }
    $status = $service->status($actor, $manifest, $operation);
    $check($status['status'] === 'IN_PROGRESS' && !$db::table('user_activity_logs')->where('action_code', 'catalog_completion.result')->exists(), 'Interrupted root was given a terminal receipt');
    $check(!$db::table('user_activity_logs')->where('action_code', 'catalog_completion.assignment')->exists(), 'Assignments started before the failed stage');
    $publications = $db::table('academic_plan_events')->where('action','catalog_completion_published')->orderBy('academic_program_id')->get()->all();
    $check(array_map(fn ($r) => (int) $r->academic_program_id, $publications) === $earlier, 'Earlier committed groups did not match incident-shaped fixture');
    $pins = $db::table('student_academic_plan_assignments')->orderBy('student_academic_plan_assignment_id')->get()->all();
    $sources = [];
    foreach ($publications as $event) { $context = json_decode($event->context,true); $sources[$context['source_version_id']] = \App\Services\CatalogCompletion\CatalogCompletionPublication::sourceFingerprint($context['source_version_id']); }
    $defaultIds = $db::table('academic_programs')->whereIn('academic_program_id', $earlier)->orderBy('academic_program_id')->pluck('default_academic_plan_version_id','academic_program_id')->all();
    $oldMembers = $rows();
    $expectPackage('03_rollback.sql', 'RESTORED_NOT_NULL'); // Fixture reconstructs the genuinely old schema at the failed stage.
    try {
        $tx->run(function () use ($workflow, $actor, $confirm, $revision, $db) {
            $source = $workflow->fixTransition($actor,40,$confirm())['current_version_id'];
            $copy = $workflow->copy($actor,40,$source,['revision'=>$revision(),'label'=>'Synthetic interrupted sports insert']);
            $db::table('program_courses')->insert(['academic_program_id'=>40,'academic_plan_version_id'=>$copy['version']->getKey(),
                'course_id'=>409,'course_type'=>'elective','academic_level_id'=>null,'recommended_semester_id'=>null]);
        });
        throw new RuntimeException('Expected genuine 1048 at sports stage');
    } catch (\Illuminate\Database\QueryException $e) { $check((int)($e->errorInfo[1]??0)===1048,$e->getMessage()); }
    $check(!$db::table('academic_plan_versions')->whereIn('academic_program_id',[40,41,42])->exists(),'Failed allocation mistaken for committed version');
    $check($oldMembers === $rows(), 'Failed sports stage changed earlier memberships');
    $check($service->apply($actor,$manifest,'7',$operation) === ['status'=>'IN_PROGRESS','operation_id'=>$operation], 'Authorized pure replay was blocked by schema or stale revision');
    $expectPackage('01_apply.sql','APPLIED'); $expectPackage('02_verify.sql','PASS');
    $preview = $service->preview($actor,$manifest); $check($preview['can_apply'],'New preview did not expose ready schema');
    $result = $service->apply($actor,$manifest,$preview['revision'],$operation,true);
    $check($materialBefore === $material(),'Resume changed result/component/approval/decision/announcement contents or timestamps');
    $check($gpaBefore === $grades->calculateGpa($student(),1,1),'Resume changed official GPA');
    array_walk_recursive($transcriptBefore,function (&$value,$key) { if ($key === 'program_name') $value = 'برنامج الإجازة في التدريب الرياضي'; });
    $check($transcriptBefore === $grades->getTranscript($student()),'Resume changed transcript beyond the approved sports display name');
    foreach ($sources as $id => $fingerprint) $check($fingerprint === \App\Services\CatalogCompletion\CatalogCompletionPublication::sourceFingerprint($id),'A historical source changed');
    $check($defaultIds === $db::table('academic_programs')->whereIn('academic_program_id',$earlier)->orderBy('academic_program_id')->pluck('default_academic_plan_version_id','academic_program_id')->all(),'Earlier published IDs/defaults changed');
    $check($publications == $db::table('academic_plan_events')->where('action','catalog_completion_published')->whereIn('academic_program_id',$earlier)->orderBy('academic_program_id')->get()->all(),'Earlier publication duplicated or rewritten');
    foreach ([40=>141,41=>82,42=>82] as $program=>$hours) {
        $target = \App\Services\AcademicPlanContext::forProgram($program)->versionId;
        $check((int)$db::table('academic_plan_versions')->where('academic_plan_version_id',$target)->value('total_credit_hours')===$hours,'Target was not completed: '.json_encode($result['groups']));
    }
    $sports = \App\Services\AcademicPlanContext::forProgram(40)->versionId;
    $check($db::table('program_courses')->where('academic_plan_version_id',$sports)->where('course_id',409)->where('course_type','elective')->whereNull('academic_level_id')->whereNull('recommended_semester_id')->exists(),'Sports alternative was fabricated or lost');
    $expectedSports = [];
    foreach ($preview['rows'] as $row) if ($row['program_id'] === 40 && in_array($row['state'],['reuse','create'],true)) {
        $courseId = str_starts_with($row['identity'],'id:') ? (int)substr($row['identity'],3)
            : (int)$db::table('courses')->where('course_code',$row['source']['target_code'])->value('course_id');
        $expectedSports[] = $courseId;
        $check($db::table('program_courses')->where('academic_plan_version_id',$sports)->where('course_id',$courseId)->exists(),'A verified sports alternative was lost');
    }
    sort($expectedSports); $expectedSports = array_values(array_unique($expectedSports));
    $check($expectedSports === $db::table('program_courses')->where('academic_plan_version_id',$sports)->orderBy('course_id')->pluck('course_id')->map(fn ($id)=>(int)$id)->all(),'Sports membership set differs from verified alternatives');
    $check(count($sources)===6 && count($pins)===6,'Unexpected earlier source/assignment fixture');
    $beforeReplay = [$rows(),$db::table('academic_plan_versions')->get()->all(),$db::table('student_academic_plan_assignments')->get()->all(),$db::table('academic_plan_events')->get()->all()];
    $check($result === $service->apply($actor,$manifest,'7',$operation),'Pure replay changed its receipt');
    $service->apply($actor,$manifest,$revision(),$operation,true);
    $check($beforeReplay == [$rows(),$db::table('academic_plan_versions')->get()->all(),$db::table('student_academic_plan_assignments')->get()->all(),$db::table('academic_plan_events')->get()->all()],'Repeated resume duplicated academic data');
    try { $package('03_rollback.sql'); throw new RuntimeException('Lossy rollback accepted'); }
    catch (PDOException $e) { $check(str_contains($e->getMessage(),'elective_placement_rollback_would_lose_unplaced_memberships'),$e->getMessage()); }
    $check($beforeReplay == [$rows(),$db::table('academic_plan_versions')->get()->all(),$db::table('student_academic_plan_assignments')->get()->all(),$db::table('academic_plan_events')->get()->all()],'Refused rollback changed history');
    echo "PASS six committed groups/real failed NOT NULL sports insert/IN_PROGRESS with no final receipt or assignments/same UUID authorized replay/fresh explicit resume/earlier IDs and sources preserved/sports 141/institutes 82/no duplicate academic writes/lossy rollback refusal\n";
    exit;
}
throw new RuntimeException('Unknown isolated placement command');
