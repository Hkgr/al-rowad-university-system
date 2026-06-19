<?php

/**
 * Generates Laravel API layer from al_rowad_university_db.sql schema.
 * Run: php database/scripts/generate-api-from-schema.php
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
$schemaPath = $basePath.'/database/schema/al_rowad_university_db.sql';
$sql = file_get_contents($schemaPath);

if ($sql === false) {
    fwrite(STDERR, "Could not read schema file.\n");
    exit(1);
}

function parseTables(string $sql): array
{
    preg_match_all('/CREATE TABLE `([^`]+)` \((.*?)\)\s*(?:ENGINE=[^;]+)?;/s', $sql, $matches, PREG_SET_ORDER);

    $tables = [];
    foreach ($matches as $match) {
        $name = $match[1];
        if (str_starts_with($name, 'vw_')) {
            continue;
        }

        $body = $match[2];
        $columns = [];
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'PRIMARY') || str_starts_with($line, 'UNIQUE') || str_starts_with($line, 'KEY') || str_starts_with($line, 'CONSTRAINT')) {
                continue;
            }
            if (! preg_match('/^`([^`]+)`\s+([^,]+)/', $line, $colMatch)) {
                continue;
            }
            $colName = $colMatch[1];
            $colDef = strtolower($colMatch[2]);
            $nullable = str_contains($colDef, 'default null') || ! str_contains($colDef, 'not null');
            $columns[$colName] = [
                'definition' => $colDef,
                'nullable' => $nullable,
            ];
        }
        $tables[$name] = $columns;
    }

    return $tables;
}

function parsePrimaryKeys(string $sql): array
{
    preg_match_all('/ALTER TABLE `([^`]+)`\s+ADD PRIMARY KEY \(`([^`]+)`\)/', $sql, $matches, PREG_SET_ORDER);
    $pks = [];
    foreach ($matches as $match) {
        if (! str_starts_with($match[1], 'vw_')) {
            $pks[$match[1]] = $match[2];
        }
    }

    return $pks;
}

function parseForeignKeys(string $sql): array
{
    preg_match_all('/ADD CONSTRAINT `[^`]+` FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)` \(`([^`]+)`\)/', $sql, $matches, PREG_SET_ORDER);
    $fks = [];
    foreach ($matches as $match) {
        $fks[] = [
            'column' => $match[1],
            'referenced_table' => $match[2],
            'referenced_column' => $match[3],
        ];
    }

    return $fks;
}

function tableToModelClass(string $table): string
{
    $singular = match ($table) {
        'organizational_units' => 'organizational_unit',
        'organizational_unit_types' => 'organizational_unit_type',
        'employee_unit_assignments' => 'employee_unit_assignment',
        'library_book_authors' => 'library_book_author',
        'library_book_copies' => 'library_book_copy',
        'library_borrowings' => 'library_borrowing',
        'library_categories' => 'library_category',
        'library_authors' => 'library_author',
        'library_books' => 'library_book',
        'student_credit_limits' => 'student_credit_limit',
        'password_reset_tokens' => 'password_reset_token',
        'user_activity_logs' => 'user_activity_log',
        'user_roles' => 'user_role',
        'system_modules' => 'system_module',
        'board_decision_attachments' => 'board_decision_attachment',
        'role_permissions' => 'role_permission',
        'login_audit_logs' => 'login_audit_log',
        'grade_audit_logs' => 'grade_audit_log',
        'meeting_attendees' => 'meeting_attendee',
        'course_departments' => 'course_department',
        'course_instructors' => 'course_instructor',
        'course_offerings' => 'course_offering',
        'course_prerequisites' => 'course_prerequisite',
        'program_courses' => 'program_course',
        'student_academic_terms' => 'student_academic_term',
        'student_attendance' => 'student_attendance',
        'student_course_registrations' => 'student_course_registration',
        'student_course_results' => 'student_course_result',
        'student_documents' => 'student_document',
        'student_grade_components' => 'student_grade_component',
        'student_statuses' => 'student_status',
        'supplementary_exam_periods' => 'supplementary_exam_period',
        'supplementary_exam_results' => 'supplementary_exam_result',
        'grade_appeals' => 'grade_appeal',
        'grade_approvals' => 'grade_approval',
        'grade_components' => 'grade_component',
        'grading_policies' => 'grading_policy',
        'academic_levels' => 'academic_level',
        'academic_programs' => 'academic_program',
        'academic_years' => 'academic_year',
        'account_statuses' => 'account_status',
        'admission_applications' => 'admission_application',
        'appeal_statuses' => 'appeal_status',
        'approval_statuses' => 'approval_status',
        'attendance_sessions' => 'attendance_session',
        'attendance_statuses' => 'attendance_status',
        'board_decisions' => 'board_decision',
        'board_meetings' => 'board_meeting',
        'board_members' => 'board_member',
        'document_types' => 'document_type',
        'employee_positions' => 'employee_position',
        'employee_statuses' => 'employee_status',
        'employee_types' => 'employee_type',
        'faculty_members' => 'faculty_member',
        'registration_statuses' => 'registration_status',
        'result_statuses' => 'result_status',
        default => str_replace('_', '_', rtrim($table, 's')),
    };

    return str_replace(' ', '', ucwords(str_replace('_', ' ', $singular)));
}

function tableToRouteName(string $table): string
{
    return str_replace('_', '-', $table);
}

function columnToRelationMethod(string $column, string $referencedTable): string
{
    if (str_ends_with($column, '_user_id')) {
        $prefix = substr($column, 0, -strlen('_user_id'));

        return $prefix === '' ? 'user' : lcfirst(tableToModelClass($prefix.'s'));
    }

    if ($column === 'module_id') {
        return 'systemModule';
    }

    if ($column === 'category_id') {
        return 'libraryCategory';
    }

    if ($column === 'parent_unit_id') {
        return 'parentUnit';
    }

    if ($column === 'unit_type_id') {
        return 'organizationalUnitType';
    }

    if ($column === 'prerequisite_course_id') {
        return 'prerequisiteCourse';
    }

    if ($column === 'recommended_semester_id') {
        return 'recommendedSemester';
    }

    if ($column === 'minimum_result_status_id') {
        return 'minimumResultStatus';
    }

    if (str_ends_with($column, '_status_id')) {
        $statusTable = substr($column, 0, -strlen('_id')).'es';

        return lcfirst(tableToModelClass($statusTable));
    }

    $singular = preg_replace('/_id$/', '', $column);

    return lcfirst(tableToModelClass($singular.'s'));
}

function exportPhpStringList(array $values): string
{
    if ($values === []) {
        return '[]';
    }

    $items = array_map(static fn (string $value): string => "        '{$value}',", $values);

    return "[\n".implode("\n", $items)."\n    ]";
}

function inferCast(string $definition): ?string
{
    if (str_contains($definition, 'tinyint(1)')) {
        return 'boolean';
    }
    if (str_contains($definition, 'decimal(')) {
        return 'decimal:2';
    }
    if (str_contains($definition, 'date') && ! str_contains($definition, 'datetime') && ! str_contains($definition, 'timestamp')) {
        return 'date';
    }
    if (str_contains($definition, 'datetime') || str_contains($definition, 'timestamp')) {
        return 'datetime';
    }
    if (str_contains($definition, 'time') && ! str_contains($definition, 'datetime') && ! str_contains($definition, 'timestamp')) {
        return 'datetime:H:i:s';
    }

    return null;
}

function validationRule(string $column, array $meta, bool $isUpdate, array $fkColumns): string
{
    $nullable = $meta['nullable'] || $isUpdate;
    $required = $nullable ? 'nullable' : 'required';
    $def = $meta['definition'];

    if (in_array($column, ['created_at', 'updated_at', 'uploaded_at', 'granted_at', 'attempted_at', 'changed_at', 'entered_at', 'assigned_at', 'submitted_at', 'decision_date', 'calculated_at', 'verified_at', 'used_at'], true)) {
        return "'{$column}' => '".($isUpdate ? 'sometimes|' : '')."nullable|date',";
    }

    if (isset($fkColumns[$column])) {
        [$refTable, $refCol] = $fkColumns[$column];

        return "'{$column}' => '".($isUpdate ? 'sometimes|' : '')."{$required}|integer|exists:{$refTable},{$refCol}',";
    }

    if (str_contains($def, 'int(') || str_contains($def, 'bigint(')) {
        return "'{$column}' => '".($isUpdate ? 'sometimes|' : '')."{$required}|integer',";
    }
    if (str_contains($def, 'decimal(')) {
        return "'{$column}' => '".($isUpdate ? 'sometimes|' : '')."{$required}|numeric',";
    }
    if (str_contains($def, 'tinyint(1)')) {
        return "'{$column}' => '".($isUpdate ? 'sometimes|' : '')."{$required}|boolean',";
    }
    if (str_contains($def, 'date') && ! str_contains($def, 'datetime')) {
        return "'{$column}' => '".($isUpdate ? 'sometimes|' : '')."{$required}|date',";
    }
    if (str_contains($def, 'datetime') || str_contains($def, 'timestamp')) {
        return "'{$column}' => '".($isUpdate ? 'sometimes|' : '')."{$required}|date',";
    }
    if (str_contains($def, 'time')) {
        return "'{$column}' => '".($isUpdate ? 'sometimes|' : '')."{$required}|date_format:H:i:s',";
    }
    if (str_contains($def, 'text')) {
        return "'{$column}' => '".($isUpdate ? 'sometimes|' : '')."{$required}|string',";
    }
    if (preg_match('/varchar\((\d+)\)/', $def, $m)) {
        return "'{$column}' => '".($isUpdate ? 'sometimes|' : '')."{$required}|string|max:{$m[1]}',";
    }

    return "'{$column}' => '".($isUpdate ? 'sometimes|' : '')."{$required}|string',";
}

function writeFile(string $path, string $content): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $content);
}

$tables = parseTables($sql);
$pks = parsePrimaryKeys($sql);
$allFks = parseForeignKeys($sql);

$fksByTable = [];
foreach ($allFks as $fk) {
    // assign FK to table by finding which table has this column - need table context from ALTER TABLE blocks
}
preg_match_all('/ALTER TABLE `([^`]+)`\s+(.*?)(?=ALTER TABLE|COMMIT;)/s', $sql, $alterBlocks, PREG_SET_ORDER);
foreach ($alterBlocks as $block) {
    $table = $block[1];
    if (str_starts_with($table, 'vw_')) {
        continue;
    }
    preg_match_all('/FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)` \(`([^`]+)`\)/', $block[2], $fkMatches, PREG_SET_ORDER);
    foreach ($fkMatches as $fk) {
        $fksByTable[$table][] = [
            'column' => $fk[1],
            'referenced_table' => $fk[2],
            'referenced_column' => $fk[3],
        ];
    }
}

$hasManyByTable = [];
foreach ($fksByTable as $childTable => $fks) {
    foreach ($fks as $fk) {
        $parentTable = $fk['referenced_table'];
        $hasManyByTable[$parentTable][] = [
            'child_table' => $childTable,
            'foreign_key' => $fk['column'],
        ];
    }
}

$routeLines = ["<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n"];
$routeLines[] = "Route::prefix('v1')->group(function (): void {";

foreach ($tables as $table => $columns) {
    if (! isset($pks[$table])) {
        continue;
    }

    $pk = $pks[$table];
    $modelClass = tableToModelClass($table);
    $routeName = tableToRouteName($table);
    $controllerClass = "App\\Http\\Controllers\\Api\\{$modelClass}Controller";
    $resourceClass = "App\\Http\\Resources\\{$modelClass}Resource";
    $storeRequestClass = "App\\Http\\Requests\\{$modelClass}\\Store{$modelClass}Request";
    $updateRequestClass = "App\\Http\\Requests\\{$modelClass}\\Update{$modelClass}Request";

    $fillable = array_values(array_filter(array_keys($columns), fn ($c) => $c !== $pk));
    $hasCreatedAt = isset($columns['created_at']);
    $hasUpdatedAt = isset($columns['updated_at']);

    $fkColumnMap = [];
    foreach ($fksByTable[$table] ?? [] as $fk) {
        $fkColumnMap[$fk['column']] = [$fk['referenced_table'], $fk['referenced_column']];
    }

    $casts = [];
    foreach ($columns as $col => $meta) {
        $cast = inferCast($meta['definition']);
        if ($cast) {
            $casts[$col] = $cast;
        }
    }

    // Model
    $belongsToMethods = [];
    foreach ($fksByTable[$table] ?? [] as $fk) {
        $relMethod = columnToRelationMethod($fk['column'], $fk['referenced_table']);
        $relatedModel = tableToModelClass($fk['referenced_table']);
        $belongsToMethods[$relMethod] = [
            'model' => $relatedModel,
            'foreign_key' => $fk['column'],
            'owner_key' => $fk['referenced_column'],
        ];
    }

    $hasManyMethods = [];
    foreach ($hasManyByTable[$table] ?? [] as $hm) {
        $childModel = tableToModelClass($hm['child_table']);
        $method = lcfirst($childModel).'s';
        if (isset($hasManyMethods[$method])) {
            $method = lcfirst($childModel).'Records';
        }
        $hasManyMethods[$method] = [
            'model' => $childModel,
            'foreign_key' => $hm['foreign_key'],
        ];
    }

    $modelContent = "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;\nuse Illuminate\\Database\\Eloquent\\Relations\\HasMany;\n\nclass {$modelClass} extends Model\n{\n";
    $modelContent .= "    protected \$table = '{$table}';\n\n";
    $modelContent .= "    protected \$primaryKey = '{$pk}';\n\n";
    $modelContent .= '    protected $fillable = '.exportPhpStringList($fillable).";\n\n";
    if (! $hasCreatedAt && ! $hasUpdatedAt) {
        $modelContent .= "    public \$timestamps = false;\n\n";
    } elseif (! $hasUpdatedAt) {
        $modelContent .= "    public const UPDATED_AT = null;\n\n";
    } elseif (! $hasCreatedAt) {
        $modelContent .= "    public const CREATED_AT = null;\n\n";
    }
    if ($casts !== []) {
        $modelContent .= "    protected function casts(): array\n    {\n        return [\n";
        foreach ($casts as $col => $cast) {
            $modelContent .= "            '{$col}' => '{$cast}',\n";
        }
        $modelContent .= "        ];\n    }\n\n";
    }
    foreach ($belongsToMethods as $method => $rel) {
        $modelContent .= "    public function {$method}(): BelongsTo\n    {\n";
        $modelContent .= "        return \$this->belongsTo({$rel['model']}::class, '{$rel['foreign_key']}', '{$rel['owner_key']}');\n";
        $modelContent .= "    }\n\n";
    }
    foreach ($hasManyMethods as $method => $rel) {
        $modelContent .= "    public function {$method}(): HasMany\n    {\n";
        $modelContent .= "        return \$this->hasMany({$rel['model']}::class, '{$rel['foreign_key']}', '{$pk}');\n";
        $modelContent .= "    }\n\n";
    }
    $modelContent .= "}\n";
    writeFile("{$basePath}/app/Models/{$modelClass}.php", $modelContent);

    // Controller
    $controllerContent = "<?php\n\nnamespace App\\Http\\Controllers\\Api;\n\nuse App\\Http\\Requests\\{$modelClass}\\Store{$modelClass}Request;\nuse App\\Http\\Requests\\{$modelClass}\\Update{$modelClass}Request;\nuse App\\Http\\Resources\\{$modelClass}Resource;\nuse App\\Models\\{$modelClass};\n\nclass {$modelClass}Controller extends ApiController\n{\n";
    $controllerContent .= "    protected function modelClass(): string\n    {\n        return {$modelClass}::class;\n    }\n\n";
    $controllerContent .= "    protected function resourceClass(): string\n    {\n        return {$modelClass}Resource::class;\n    }\n\n";
    $controllerContent .= "    protected function storeRequestClass(): string\n    {\n        return Store{$modelClass}Request::class;\n    }\n\n";
    $controllerContent .= "    protected function updateRequestClass(): string\n    {\n        return Update{$modelClass}Request::class;\n    }\n}\n";
    writeFile("{$basePath}/app/Http/Controllers/Api/{$modelClass}Controller.php", $controllerContent);

    // Store Request
    $storeRules = [];
    foreach ($fillable as $col) {
        if (isset($columns[$col])) {
            $storeRules[] = validationRule($col, $columns[$col], false, $fkColumnMap);
        }
    }
    $storeContent = "<?php\n\nnamespace App\\Http\\Requests\\{$modelClass};\n\nuse Illuminate\\Foundation\\Http\\FormRequest;\n\nclass Store{$modelClass}Request extends FormRequest\n{\n    public function authorize(): bool\n    {\n        return true;\n    }\n\n    public function rules(): array\n    {\n        return [\n            ".implode("\n            ", $storeRules)."\n        ];\n    }\n}\n";
    writeFile("{$basePath}/app/Http/Requests/{$modelClass}/Store{$modelClass}Request.php", $storeContent);

    // Update Request
    $updateRules = [];
    foreach ($fillable as $col) {
        if (isset($columns[$col])) {
            $updateRules[] = validationRule($col, $columns[$col], true, $fkColumnMap);
        }
    }
    $updateContent = "<?php\n\nnamespace App\\Http\\Requests\\{$modelClass};\n\nuse Illuminate\\Foundation\\Http\\FormRequest;\n\nclass Update{$modelClass}Request extends FormRequest\n{\n    public function authorize(): bool\n    {\n        return true;\n    }\n\n    public function rules(): array\n    {\n        return [\n            ".implode("\n            ", $updateRules)."\n        ];\n    }\n}\n";
    writeFile("{$basePath}/app/Http/Requests/{$modelClass}/Update{$modelClass}Request.php", $updateContent);

    // Resource
    $resourceContent = "<?php\n\nnamespace App\\Http\\Resources;\n\nuse Illuminate\\Http\\Request;\nuse Illuminate\\Http\\Resources\\Json\\JsonResource;\n\n/** @mixin \\App\\Models\\{$modelClass} */\nclass {$modelClass}Resource extends JsonResource\n{\n    public function toArray(Request \$request): array\n    {\n        return [\n";
    foreach (array_merge([$pk], $fillable) as $col) {
        if ($table === 'users' && $col === 'password_hash') {
            continue;
        }
        $resourceContent .= "            '{$col}' => \$this->{$col},\n";
    }
    $resourceContent .= "        ];\n    }\n}\n";
    writeFile("{$basePath}/app/Http/Resources/{$modelClass}Resource.php", $resourceContent);

    $routeLines[] = "    Route::apiResource('{$routeName}', {$modelClass}Controller::class);";
}

$routeLines[] = "});\n";
writeFile("{$basePath}/routes/api.php", implode("\n", $routeLines)."\n");

// Fix route imports
$routeImports = ["<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n"];
foreach ($tables as $table => $_) {
    if (! isset($pks[$table])) {
        continue;
    }
    $modelClass = tableToModelClass($table);
    $routeImports[] = "use App\\Http\\Controllers\\Api\\{$modelClass}Controller;";
}
$routeImports[] = "\nRoute::prefix('v1')->group(function (): void {";
foreach ($tables as $table => $_) {
    if (! isset($pks[$table])) {
        continue;
    }
    $modelClass = tableToModelClass($table);
    $routeName = tableToRouteName($table);
    $routeImports[] = "    Route::apiResource('{$routeName}', {$modelClass}Controller::class);";
}
$routeImports[] = "});\n";
writeFile("{$basePath}/routes/api.php", implode("\n", $routeImports)."\n");

echo 'Generated '.count($pks).' API resources from schema.'.PHP_EOL;
