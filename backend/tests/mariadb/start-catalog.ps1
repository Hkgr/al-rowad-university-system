param([Parameter(Mandatory=$true)][string]$MariaDbHome, [int]$Port = 23362)
$ErrorActionPreference = 'Stop'
# Only starts a NEW disposable instance; never registers/stops a Windows service.
if ($Port -lt 20000 -or $Port -gt 60000) { throw 'Dedicated high test port required' }
if (Get-NetTCPConnection -LocalPort $Port -ErrorAction SilentlyContinue) { throw 'Port already occupied' }
$catalogRoot = Join-Path $env:TEMP ('pr132-catalog-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $catalogRoot | Out-Null
$catalogPassword = [guid]::NewGuid().ToString('N')
$catalogMarker = [guid]::NewGuid().ToString('N')
& "$MariaDbHome/bin/mariadb-install-db.exe" "--datadir=$catalogRoot/data" "--password=$catalogPassword" "--port=$Port"
if ($LASTEXITCODE -ne 0) { throw 'Isolated initialization failed' }
$catalogProcess = Start-Process -FilePath "$MariaDbHome/bin/mariadbd.exe" -ArgumentList @('--no-defaults', "--basedir=$MariaDbHome", "--datadir=$catalogRoot/data", "--port=$Port", '--bind-address=127.0.0.1', '--skip-name-resolve', '--console') -WindowStyle Hidden -PassThru -RedirectStandardOutput "$catalogRoot/server.stdout" -RedirectStandardError "$catalogRoot/server.stderr"
@{ host='127.0.0.1'; port=$Port; database='alrowad_uni_rust'; root_password=$catalogPassword; password=[guid]::NewGuid().ToString('N'); marker=$catalogMarker; directory=$catalogRoot; pid=$catalogProcess.Id; version='10.11.18'; hostname=$env:COMPUTERNAME } | ConvertTo-Json | Set-Content -Encoding UTF8 "$catalogRoot/connection.json"
Write-Output "SCIENTIFIC_MARIADB_CONFIG=$catalogRoot/connection.json"
