param(
  [string]$Title = "TUSTOCK",
  [string]$Message = "Notificacion desde TUSTOCK",
  [string]$Priority = "3",
  [string]$Tags = "tustock"
)

$body = @{
  topic    = "TU_STOCK_IA"
  message  = $Message
  title    = $Title
  priority = [int]$Priority
  tags     = $Tags -split ","
}

try {
  $json = $body | ConvertTo-Json
  Invoke-RestMethod -Uri "https://ntfy.sh" -Method Post -Body $json -ContentType "application/json" -ErrorAction Stop
} catch {
  Write-Warning "ntfy notification failed: $_"
}
