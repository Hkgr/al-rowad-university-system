param([Parameter(Mandatory=$true)][string]$MariaDbHome,[switch]$MemoryAcceptance,[string]$BaselineReceiptFile,[switch]$RepresentativeMemoryActions)
$ErrorActionPreference='Stop'
$output=& (Join-Path $PSScriptRoot 'start-catalog.ps1') -MariaDbHome $MariaDbHome -Port (Get-Random -Minimum 25000 -Maximum 39000)
$line=$output | Where-Object { $_ -like 'SCIENTIFIC_MARIADB_CONFIG=*' } | Select-Object -Last 1
if(!$line){throw 'New marked isolated configuration required'}
$env:SCIENTIFIC_MARIADB_CONFIG=$line.Substring('SCIENTIFIC_MARIADB_CONFIG='.Length)
Write-Output ('EVIDENCE_DIRECTORY='+(Split-Path -Parent $env:SCIENTIFIC_MARIADB_CONFIG))
try {
    if($MemoryAcceptance -and $BaselineReceiptFile) {
        $baselineTarget=Join-Path (Split-Path -Parent $env:SCIENTIFIC_MARIADB_CONFIG) 'memory-baseline-receipts.php'
        Copy-Item -LiteralPath $BaselineReceiptFile -Destination $baselineTarget
        $env:HISTORICAL_EXAM_BASELINE_HASH=(Get-FileHash -Algorithm SHA256 -LiteralPath $baselineTarget).Hash.ToLowerInvariant()
    }
    $steps=@(@('catalog.php','init'),@('catalog.php','01_apply.sql'),@('academic-plans.php','fixture'),
        @('academic-plans.php','complete-runtime-fixture'),@('academic-plans.php','complete-decision-fixture'),
        @('catalog-completion.php','prepare'),@('student-completion.php','fixture'),@('approved-exam-reconciliation.php','catalog-fixture'),@('academic-plans.php','01_apply.sql'),
        @('academic-plans.php','02_verify.sql'),@('catalog-completion.php','01_apply.sql'),@('catalog-completion.php','02_verify.sql'),
        @('elective-placement.php','install'),@('historical-exams.php','run'),@('approved-exam-reconciliation.php','run'),
        @('supplementary-import-race.php','run'))
    foreach($step in $steps) {
        # Preserve the complete native stack before failing; Stop used to truncate it at stderr's first line.
        $ErrorActionPreference='Continue'
        & php (Join-Path $PSScriptRoot $step[0]) $step[1]
        $stepExit=$LASTEXITCODE
        $ErrorActionPreference='Stop'
        if($stepExit -ne 0){throw ('Failed isolated step: '+($step -join ' '))}
    }
    if($MemoryAcceptance) {
        if($RepresentativeMemoryActions){$env:HISTORICAL_EXAM_MEMORY_ACTIONS='representative'}
        $ErrorActionPreference='Continue'
        & php -d memory_limit=2048M (Join-Path $PSScriptRoot 'historical-exam-memory.php') run
        $memoryExit=$LASTEXITCODE
        $ErrorActionPreference='Stop'
        if($memoryExit -ne 0){throw 'Isolated historical memory acceptance failed'}
    }
} finally {
    & php (Join-Path $PSScriptRoot 'catalog.php') shutdown
    if($LASTEXITCODE -ne 0){Write-Warning 'Guarded shutdown failed; no other process stopped.'}
    Remove-Item Env:SCIENTIFIC_MARIADB_CONFIG
    Remove-Item Env:HISTORICAL_EXAM_BASELINE_HASH -ErrorAction SilentlyContinue
    Remove-Item Env:HISTORICAL_EXAM_MEMORY_ACTIONS -ErrorAction SilentlyContinue
}
