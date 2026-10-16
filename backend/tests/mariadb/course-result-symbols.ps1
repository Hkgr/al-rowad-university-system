param([string]$BinaryDirectory='C:\wamp64\bin\mariadb\mariadb11.4.9\bin')
$ErrorActionPreference='Stop'
$binary=(Resolve-Path -LiteralPath $BinaryDirectory).Path
$installer=Join-Path $binary 'mariadb-install-db.exe'
$server=Join-Path $binary 'mariadbd.exe'
if (!(Test-Path -LiteralPath $installer) -or !(Test-Path -LiteralPath $server)) { throw 'Existing MariaDB binaries required.' }
$marker=[guid]::NewGuid().ToString('N')
$directory=Join-Path ([IO.Path]::GetTempPath()) ('course-symbols-'+$marker)
$null=New-Item -ItemType Directory -Path $directory
$port=Get-Random -Minimum 25000 -Maximum 39000
$password=[guid]::NewGuid().ToString('N')
$config=@{directory=$directory;marker=$marker;host='127.0.0.1';port=$port;database=('course_symbols_'+$marker);password=$password}
$configPath=Join-Path $directory 'isolated.json'
[IO.File]::WriteAllText($configPath,($config|ConvertTo-Json),[Text.UTF8Encoding]::new($false))
$env:COURSE_SYMBOLS_MARIADB_CONFIG=$configPath
& $installer "--datadir=$directory/data" "--port=$port" "--password=$password" --silent
if($LASTEXITCODE -ne 0){throw 'Disposable initialization failed.'}
$process=Start-Process -FilePath $server -WindowStyle Hidden -PassThru -ArgumentList @("--defaults-file=$directory/data/my.ini",'--bind-address=127.0.0.1',"--port=$port",'--skip-log-bin')
try {
    $ready=$false
    for($i=0;$i -lt 100;$i++){
        $client=[Net.Sockets.TcpClient]::new()
        try{$client.Connect('127.0.0.1',$port);$ready=$true;break}catch{Start-Sleep -Milliseconds 100}finally{$client.Dispose()}
    }
    if(!$ready){throw 'Isolated server did not start; no other service will be used.'}
    & php (Join-Path $PSScriptRoot 'course-result-symbols.php') run
    if($LASTEXITCODE -ne 0 -or !(Test-Path -LiteralPath (Join-Path $directory 'verification-passed'))){throw 'MariaDB verification failed; see actual output.'}
} finally {
    & php (Join-Path $PSScriptRoot 'course-result-symbols.php') shutdown
    if($LASTEXITCODE -ne 0){Write-Warning 'Guarded shutdown refused; no unrelated process was stopped.'}
    Remove-Item Env:COURSE_SYMBOLS_MARIADB_CONFIG
}
