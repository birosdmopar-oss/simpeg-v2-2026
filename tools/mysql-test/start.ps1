<#
.SYNOPSIS
    Jalankan instance MySQL khusus test (MAKE-001) secara DETACHED di port 3307 (default).

.DESCRIPTION
    Memakai binari MySQL Laragon (tanpa mengubah my.ini/datadir Laragon) dengan datadir terpisah
    (default %LOCALAPPDATA%\simpeg-mysql-test\data). Datadir diinisialisasi sekali dengan --initialize-insecure
    (root@localhost tanpa password, hanya bind 127.0.0.1). mysqld dijalankan lewat Start-Process sehingga tetap
    hidup walau terminal/agen yang menjalankannya ditutup. Hentikan dengan stop.ps1.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\mysql-test\start.ps1
#>
param(
    [int]$Port = 3307,
    [string]$DataRoot = (Join-Path $env:LOCALAPPDATA 'simpeg-mysql-test'),
    [string]$MysqlHome = 'C:\laragon\bin\mysql\mysql-8.0.30-winx64'
)

$ErrorActionPreference = 'Stop'

if ($Port -eq 3306) {
    throw 'Port 3306 milik MySQL dev Laragon. Instance test wajib memakai port lain (default 3307).'
}

$ini        = Join-Path $PSScriptRoot 'my-test.ini'
$mysqld     = Join-Path $MysqlHome 'bin\mysqld.exe'
$mysqladmin = Join-Path $MysqlHome 'bin\mysqladmin.exe'
$dataDir    = Join-Path $DataRoot 'data'
$logFile    = Join-Path $DataRoot 'mysqld-test.log'
$pidFile    = Join-Path $DataRoot 'mysqld-test.pid'

foreach ($path in @($ini, $mysqld, $mysqladmin)) {
    if (-not (Test-Path $path)) { throw "Tidak ditemukan: $path" }
}

function Test-MysqlUp {
    # Windows PowerShell 5.1: stderr program native + ErrorActionPreference Stop = error fatal; ping gagal itu wajar.
    $ErrorActionPreference = 'Continue'
    & $mysqladmin --no-defaults --user=root "--host=127.0.0.1" "--port=$Port" --connect-timeout=2 ping 2>$null | Out-Null
    return $LASTEXITCODE -eq 0
}

if (Test-MysqlUp) {
    Write-Host "Instance test sudah berjalan di 127.0.0.1:$Port."
    exit 0
}

if (-not (Test-Path $DataRoot)) {
    New-Item -ItemType Directory -Path $DataRoot | Out-Null
}

if (-not (Test-Path $dataDir)) {
    Write-Host "Inisialisasi datadir baru: $dataDir"
    & $mysqld "--defaults-file=$ini" --initialize-insecure "--datadir=$dataDir" "--log-error=$logFile"
    if ($LASTEXITCODE -ne 0) { throw "Inisialisasi datadir gagal (lihat $logFile)." }
}

# Start-Process menggabungkan argumen apa adanya: path ber-spasi wajib dikutip manual.
$arguments = @(
    "--defaults-file=`"$ini`"",
    "--datadir=`"$dataDir`"",
    "--port=$Port",
    "--log-error=`"$logFile`"",
    "--pid-file=`"$pidFile`""
) -join ' '

$process = Start-Process -FilePath $mysqld -ArgumentList $arguments -WindowStyle Hidden -PassThru

for ($i = 0; $i -lt 60; $i++) {
    if (Test-MysqlUp) {
        Write-Host "Instance test berjalan: 127.0.0.1:$Port (PID $($process.Id)), datadir $dataDir, log $logFile"
        exit 0
    }
    if ($process.HasExited) {
        throw "mysqld berhenti saat start (exit $($process.ExitCode)); lihat $logFile"
    }
    Start-Sleep -Seconds 1
}

throw "mysqld belum menjawab ping setelah 60 detik; lihat $logFile"
