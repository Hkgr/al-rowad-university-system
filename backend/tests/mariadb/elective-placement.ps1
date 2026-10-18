param([Parameter(Mandatory=$true)][string]$MariaDbHome)
$ErrorActionPreference = 'Stop'
$port = Get-Random -Minimum 25000 -Maximum 39000
$output = & (Join-Path $PSScriptRoot 'start-catalog.ps1') -MariaDbHome $MariaDbHome -Port $port
$line = $output | Where-Object { $_ -like 'SCIENTIFIC_MARIADB_CONFIG=*' } | Select-Object -Last 1
if (!$line) { throw 'New isolated configuration was not returned' }
$env:SCIENTIFIC_MARIADB_CONFIG = $line.Substring('SCIENTIFIC_MARIADB_CONFIG='.Length)
Write-Output ('EVIDENCE_DIRECTORY=' + (Split-Path -Parent $env:SCIENTIFIC_MARIADB_CONFIG))
try {
    foreach ($step in @(@('catalog.php','init'), @('catalog.php','01_apply.sql'), @('academic-plans.php','fixture'),
        @('academic-plans.php','complete-runtime-fixture'), @('academic-plans.php','complete-decision-fixture'),
        @('catalog-completion.php','prepare'), @('academic-plans.php','01_apply.sql'), @('academic-plans.php','02_verify.sql'),
        @('catalog-completion.php','01_apply.sql'), @('catalog-completion.php','02_verify.sql'),
        @('elective-placement.php','schema-tests'), @('elective-placement.php','incident-resume'))) {
        & php (Join-Path $PSScriptRoot $step[0]) $step[1]
        if ($LASTEXITCODE -ne 0) { throw ('Failed guarded step: ' + ($step -join ' ')) }
    }
} finally {
    & php (Join-Path $PSScriptRoot 'catalog.php') shutdown
    if ($LASTEXITCODE -ne 0) { Write-Warning 'Verified isolated shutdown failed; no unrelated process was stopped.' }
    Remove-Item Env:SCIENTIFIC_MARIADB_CONFIG
}
