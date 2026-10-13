<#
.SYNOPSIS
    Hentikan instance MySQL khusus test (MAKE-001) dengan shutdown bersih. Datadir dibiarkan (dipakai lagi oleh start.ps1).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\mysql-test\stop.ps1
#>
param(
    [int]$Port = 3307,
    [string]$MysqlHome = 'C:\laragon\bin\mysql\mysql-8.0.30-winx64'
)

$ErrorActionPreference = 'Stop'

if ($Port -eq 3306) {
    throw 'Port 3306 milik MySQL dev Laragon; stop.ps1 tidak pernah menghentikannya.'
}

$mysqladmin = Join-Path $MysqlHome 'bin\mysqladmin.exe'

function Test-MysqlUp {
    # Windows PowerShell 5.1: stderr program native + ErrorActionPreference Stop = error fatal; ping gagal itu wajar.
    $ErrorActionPreference = 'Continue'
    & $mysqladmin --no-defaults --user=root "--host=127.0.0.1" "--port=$Port" --connect-timeout=2 ping 2>$null | Out-Null
    return $LASTEXITCODE -eq 0
}

if (-not (Test-MysqlUp)) {
    Write-Host "Tidak ada instance yang menjawab di 127.0.0.1:$Port."
    exit 0
}

& $mysqladmin --no-defaults --user=root "--host=127.0.0.1" "--port=$Port" shutdown
if ($LASTEXITCODE -ne 0) { throw "shutdown instance 127.0.0.1:$Port gagal." }

for ($i = 0; $i -lt 60; $i++) {
    if (-not (Test-MysqlUp)) {
        Write-Host "Instance test 127.0.0.1:$Port berhenti."
        exit 0
    }
    Start-Sleep -Seconds 1
}

throw "Instance 127.0.0.1:$Port masih menjawab setelah 60 detik."
