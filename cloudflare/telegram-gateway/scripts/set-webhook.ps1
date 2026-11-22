$ErrorActionPreference = "Stop"

function Read-Secret([string]$Prompt) {
    $secure = Read-Host -Prompt $Prompt -AsSecureString
    return [System.Net.NetworkCredential]::new("", $secure).Password
}

$botToken = Read-Secret "Telegram bot token (input hidden)"
$webhookSecret = Read-Secret "Telegram webhook secret (input hidden)"
$workerUrl = Read-Host "Worker URL (for example https://zevnull-telegram-gateway.volt7459.workers.dev)"
$workerUrl = $workerUrl.Trim().TrimEnd('/')

if ([string]::IsNullOrWhiteSpace($botToken) -or [string]::IsNullOrWhiteSpace($webhookSecret) -or [string]::IsNullOrWhiteSpace($workerUrl)) {
    throw "All values are required."
}
if ($webhookSecret -notmatch '^[A-Za-z0-9_-]{1,256}$') {
    throw "Webhook secret must contain only A-Z, a-z, 0-9, underscore or hyphen."
}
if ($workerUrl -notmatch '^https://[A-Za-z0-9.-]+\.workers\.dev$') {
    throw "Use the HTTPS workers.dev URL shown by Cloudflare."
}

$body = @{
    url = "$workerUrl/telegram"
    secret_token = $webhookSecret
    allowed_updates = @("message")
    max_connections = 10
    drop_pending_updates = $false
} | ConvertTo-Json -Depth 5

try {
    $setResult = Invoke-RestMethod -Uri "https://api.telegram.org/bot$botToken/setWebhook" -Method Post -ContentType "application/json" -Body $body
    $setResult | ConvertTo-Json -Depth 8
    if (-not $setResult.ok) { throw "Telegram rejected setWebhook." }

    Write-Host "`nWebhook state:" -ForegroundColor Cyan
    $info = Invoke-RestMethod -Uri "https://api.telegram.org/bot$botToken/getWebhookInfo" -Method Get
    [pscustomobject]@{
        url = $info.result.url
        pending_update_count = $info.result.pending_update_count
        last_error_date = $info.result.last_error_date
        last_error_message = $info.result.last_error_message
        allowed_updates = ($info.result.allowed_updates -join ",")
    } | Format-List
}
finally {
    $botToken = $null
    $webhookSecret = $null
    $body = $null
}
