# Baseline backend — endpoints candidatos a N+1 / costos crecientes.
# Ejecuta el test de benchmark PHPUnit (dataset fijo, 30 iteraciones por
# endpoint). Requiere PHP >= 8.4 disponible como `php` en PATH o en $PhpExe.
#
# Uso (desde la raíz del repo):
#   .\benchmarks\run-backend-baseline.ps1
#   .\benchmarks\run-backend-baseline.ps1 -PhpExe C:\php84\php.exe -PhpIni C:\php84\php.ini
#
# Para PostgreSQL (staging, nunca mezclar con SQLite en la misma comparación):
#   $env:DB_CONNECTION='pgsql'; $env:DB_HOST='...'; ... ; .\benchmarks\run-backend-baseline.ps1

param(
    [string]$PhpExe = 'php',
    [string]$PhpIni = ''
)

$ErrorActionPreference = 'Stop'
$backend = Join-Path $PSScriptRoot '..\pallet-backend'

$env:RUN_PERFORMANCE_BENCHMARKS = '1'

$args = @()
if ($PhpIni -ne '') { $args += @('-c', $PhpIni) }
$args += @('artisan', 'test', '--filter', 'HotEndpointsBenchmarkTest')

Push-Location $backend
try {
    & $PhpExe @args
} finally {
    Pop-Location
}
