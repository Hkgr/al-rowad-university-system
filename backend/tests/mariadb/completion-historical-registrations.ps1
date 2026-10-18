param([Parameter(Mandatory=$true)][string]$MariaDbHome)
$ErrorActionPreference='Stop'
$output=& (Join-Path $PSScriptRoot 'start-catalog.ps1') -MariaDbHome $MariaDbHome -Port (Get-Random -Minimum 25000 -Maximum 39000)
$line=$output | Where-Object { $_ -like 'SCIENTIFIC_MARIADB_CONFIG=*' } | Select-Object -Last 1
if(!$line){throw 'New isolated test configuration required'}
$env:SCIENTIFIC_MARIADB_CONFIG=$line.Substring('SCIENTIFIC_MARIADB_CONFIG='.Length)
Write-Output ('EVIDENCE_DIRECTORY='+(Split-Path -Parent $env:SCIENTIFIC_MARIADB_CONFIG))
try {
    foreach($step in @(@('catalog.php','init'),@('catalog.php','01_apply.sql'),@('academic-plans.php','fixture'),
        @('academic-plans.php','complete-runtime-fixture'),@('academic-plans.php','complete-decision-fixture'),
        @('catalog-completion.php','prepare'),@('academic-plans.php','01_apply.sql'),@('academic-plans.php','02_verify.sql'),
        @('catalog-completion.php','01_apply.sql'),@('catalog-completion.php','02_verify.sql'),@('elective-placement.php','install'),
        @('completion-historical-registrations.php','run'))) {
        & php (Join-Path $PSScriptRoot $step[0]) $step[1]
        if($LASTEXITCODE -ne 0){throw ('Failed guarded step: '+($step -join ' '))}
    }
} finally {
    & php (Join-Path $PSScriptRoot 'catalog.php') shutdown
    if($LASTEXITCODE -ne 0){Write-Warning 'Guarded shutdown failed; no other process was stopped.'}
    Remove-Item Env:SCIENTIFIC_MARIADB_CONFIG
}
