$ErrorActionPreference = 'Stop'

param(
    [string]$BaseUrl = $env:SUKI_TEST_BASE
)

if (-not $BaseUrl -or $BaseUrl.Trim() -eq '') {
    $BaseUrl = 'http://suki.test:8080/api'
}

function CallApi($route, $method = 'GET', $body = $null) {
    $uri = "$BaseUrl/$route"
    try {
        if ($method -eq 'GET') {
            return Invoke-RestMethod -Uri $uri -Method Get -TimeoutSec 15 -UseBasicParsing
        } else {
            $json = $null
            if ($body) { $json = ($body | ConvertTo-Json -Depth 8) }
            return Invoke-RestMethod -Uri $uri -Method $method -ContentType 'application/json' -Body $json -TimeoutSec 20 -UseBasicParsing
        }
    } catch {
        return @{ status = 'error'; message = $_.Exception.Message; uri = $uri }
    }
}

$results = @{}
$results.registry = CallApi 'registry/status'
$results.users = CallApi 'registry/users'
$results.llm = CallApi 'llm/health' 'POST' @{ mode = 'ping' }
$results.auth_register = CallApi 'auth/register' 'POST' @{ id = 'admin1'; password = 'admin123'; role = 'admin'; tenant_id = '1' }
$results.auth_login = CallApi 'auth/login' 'POST' @{ id = 'admin1'; password = 'admin123' }
$results.chat_status = CallApi 'chat/message' 'POST' @{ message = 'estado del proyecto'; mode = 'builder'; user_id = 'admin1'; role = 'admin'; tenant_id = '1'; session_id = 'sess_test'; channel = 'local' }
$results.chat_create_table = CallApi 'chat/message' 'POST' @{ message = 'crear tabla clientes nombre:texto nit:texto email:texto saldo:numero'; mode = 'builder'; user_id = 'admin1'; role = 'admin'; tenant_id = '1'; session_id = 'sess_test'; channel = 'local' }
$results.chat_create_form = CallApi 'chat/message' 'POST' @{ message = 'crear formulario clientes'; mode = 'builder'; user_id = 'admin1'; role = 'admin'; tenant_id = '1'; session_id = 'sess_test'; channel = 'local' }
$results.chat_create_record = CallApi 'chat/message' 'POST' @{ message = 'crear cliente nombre=Juan nit=123 email=juan@mail.com saldo=100'; mode = 'app'; user_id = 'seller1'; role = 'seller'; tenant_id = '1'; session_id = 'sess_app'; channel = 'local' }
$results.chat_list_t1 = CallApi 'chat/message' 'POST' @{ message = 'listar cliente'; mode = 'app'; user_id = 'seller1'; role = 'seller'; tenant_id = '1'; session_id = 'sess_app'; channel = 'local' }
$results.chat_list_t2 = CallApi 'chat/message' 'POST' @{ message = 'listar cliente'; mode = 'app'; user_id = 'seller2'; role = 'seller'; tenant_id = '2'; session_id = 'sess_app2'; channel = 'local' }

$results | ConvertTo-Json -Depth 8

$fails = @()
foreach ($key in $results.Keys) {
    if ($results[$key].status -eq 'error') { $fails += $key }
}
if ($fails.Count -gt 0) {
    Write-Host "FAIL: $($fails -join ', ')" -ForegroundColor Red
    exit 1
}
Write-Host "OK: acid test passed" -ForegroundColor Green
exit 0
