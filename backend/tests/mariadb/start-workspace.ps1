param([Parameter(Mandatory=$true)][string]$BinaryDirectory)
$ErrorActionPreference = 'Stop'
$binaryDirectoryResolved = (Resolve-Path -LiteralPath $BinaryDirectory).Path
$installer = Join-Path $binaryDirectoryResolved 'mariadb-install-db.exe'
$server = Join-Path $binaryDirectoryResolved 'mariadbd.exe'
if (!(Test-Path -LiteralPath $installer) -or !(Test-Path -LiteralPath $server)) { throw 'Existing MariaDB binaries required; do not install anything.' }
$marker = [guid]::NewGuid().ToString('N')
$testDirectory = Join-Path ([IO.Path]::GetTempPath()) ('pr132-catalog-' + $marker)
$null = New-Item -ItemType Directory -Path $testDirectory
$port = Get-Random -Minimum 25000 -Maximum 39000
$rootPassword = [guid]::NewGuid().ToString('N')
$config = @{host='127.0.0.1';port=$port;database='alrowad_uni_rust';directory=$testDirectory;marker=$marker;version='10.11.18';hostname=$env:COMPUTERNAME;root_password=$rootPassword;password=[guid]::NewGuid().ToString('N')}
# Generated test artifacts only, never project configuration or production .env.
$configPath = Join-Path $testDirectory 'workspace-test.json'
[IO.File]::WriteAllText($configPath, ($config | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
$env:SCIENTIFIC_MARIADB_CONFIG = $configPath
& $installer "--datadir=$testDirectory/data" "--port=$port" "--password=$rootPassword" --silent
if ($LASTEXITCODE -ne 0) { throw 'Disposable database initialization failed' }
$process = Start-Process -FilePath $server -WindowStyle Hidden -PassThru -ArgumentList @("--defaults-file=$testDirectory/data/my.ini", '--bind-address=127.0.0.1', "--port=$port", '--skip-log-bin', '--innodb-flush-log-at-trx-commit=1')
try {
    $ready = $false
    for ($i=0; $i -lt 80; $i++) {
        $client = [Net.Sockets.TcpClient]::new()
        try { $client.Connect('127.0.0.1', $port); $ready=$true; break } catch { Start-Sleep -Milliseconds 100 } finally { $client.Dispose() }
    }
    if (!$ready) { throw 'Disposable server did not start; no other service will be used' }
    foreach ($arguments in @(@('catalog.php','init'), @('catalog.php','01_apply.sql'), @('catalog.php','02_verify.sql'),
        @('academic-plans.php','fixture'), @('academic-plans.php','complete-runtime-fixture'), @('academic-plans.php','complete-decision-fixture'),
        @('academic-plans.php','01_apply.sql'), @('academic-plans.php','02_verify.sql'),
        @('workspace.php','prepare'), @('workspace.php','test'))) {
        & php (Join-Path $PSScriptRoot $arguments[0]) $arguments[1]
        if ($LASTEXITCODE -ne 0) { throw ('Guarded step failed: ' + ($arguments -join ' ')) }
    }
} finally {
    # Guard verifies engine/port/datadir/marker before stopping ONLY our disposable instance.
    & php (Join-Path $PSScriptRoot 'catalog.php') shutdown
    if ($LASTEXITCODE -ne 0) { Write-Warning 'Guarded shutdown failed; retained the test instance for investigation, did not touch other processes.' }
}
