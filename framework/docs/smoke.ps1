param(
    [string]$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
)

$ErrorActionPreference = "Stop"

function Fail($msg) {
    Write-Error $msg
    exit 1
}

function Warn($msg) {
    Write-Host ("[WARN] " + $msg) -ForegroundColor Yellow
}

Write-Host "Smoke checks root: $Root"

$required = @(
    "framework/app/Core/FormGenerator.php",
    "framework/public/assets/js/form-grid.js",
    "project/contracts/forms/fact.form.json",
    "project/contracts/forms/cuentasxcobrar.form.json",
    "project/views/facturas.php",
    "project/views/cuentas_cobrar.php"
)

foreach ($path in $required) {
    $full = Join-Path $Root $path
    if (-not (Test-Path $full)) {
        Fail "Missing required file: $path"
    }
}

$contractDir = Join-Path $Root "project/contracts/forms"
if (-not (Test-Path $contractDir)) {
    Fail "Missing contracts dir: project/contracts/forms"
}

$contractFiles = Get-ChildItem -Path $contractDir -Filter "*.json" -File
if ($contractFiles.Count -eq 0) {
    Fail "No form contracts found in project/contracts/forms"
}

$builtins = @("SUM","IF","MIN","MAX","ROUND")

foreach ($file in $contractFiles) {
    Write-Host "Check: $($file.Name)"
    $json = $null
    try {
        $json = Get-Content $file.FullName -Raw | ConvertFrom-Json -ErrorAction Stop
    } catch {
        Fail "Invalid JSON: $($file.FullName)"
    }

    if (-not $json.name) { Warn "Missing name in $($file.Name)" }
    if (-not $json.type) { Warn "Missing type in $($file.Name)" }

    $gridNames = @{}
    $colCount = @{}
    if ($json.grids) {
        foreach ($g in $json.grids) {
            if (-not $g.name) { Warn "Grid without name in $($file.Name)" ; continue }
            $gridNames[$g.name] = $true
            if ($g.columns) {
                foreach ($c in $g.columns) {
                    if (-not $c.name) { continue }
                    if (-not $colCount.ContainsKey($c.name)) { $colCount[$c.name] = 0 }
                    $colCount[$c.name] = $colCount[$c.name] + 1

                    if ($c.formula -and $c.formula.expression) {
                        $expr = [string]$c.formula.expression
                        $tokens = [regex]::Matches($expr, "[A-Za-z_][A-Za-z0-9_]*") | ForEach-Object { $_.Value }
                        foreach ($t in $tokens) {
                            if ($builtins -contains $t.ToUpper()) { continue }
                            if (-not ($g.columns | Where-Object { $_.name -eq $t })) {
                                Warn "Grid formula token not found in columns: $t (grid: $($g.name), file: $($file.Name))"
                            }
                        }
                    }
                }
            }
        }
    }

    $summaryNames = @{}
    if ($json.summary) {
        foreach ($s in $json.summary) {
            if ($s.name) { $summaryNames[$s.name] = $true }
        }
        foreach ($s in $json.summary) {
            if (-not $s.name) { Warn "Summary without name in $($file.Name)"; continue }
            $stype = [string]$s.type
            if ($stype -eq "sum") {
                if (-not $s.source -or -not $s.source.grid -or -not $s.source.field) {
                    Warn "Summary sum missing source in $($file.Name) ($($s.name))"
                } else {
                    if (-not $gridNames.ContainsKey($s.source.grid)) {
                        Warn "Summary sum grid not found: $($s.source.grid) in $($file.Name)"
                    }
                }
            }
            if ($stype -eq "formula") {
                if (-not $s.expression) {
                    Warn "Summary formula missing expression: $($s.name) in $($file.Name)"
                } else {
                    $expr = [string]$s.expression
                    $tokens = [regex]::Matches($expr, "[A-Za-z_][A-Za-z0-9_]*") | ForEach-Object { $_.Value }
                    foreach ($t in $tokens) {
                        if ($builtins -contains $t.ToUpper()) { continue }
                        if ($summaryNames.ContainsKey($t)) { continue }
                        if ($colCount.ContainsKey($t)) {
                            if ($colCount[$t] -gt 1) {
                                Warn "Summary formula token is ambiguous (multiple grids): $t in $($file.Name)"
                            }
                            continue
                        }
                        Warn "Summary formula token not found: $t in $($file.Name)"
                    }
                }
            }
        }
    }
}

Write-Host "Smoke checks completed."

