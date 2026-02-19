$path='c:\laragon\www\suki\framework\contracts\agents\conversation_training_base.json'
$content=Get-Content -Raw -Path $path
$helpPattern='"help"\s*:\s*\{[\s\S]*?\}\s*,\s*"fallback"'
$match=[regex]::Match($content,$helpPattern)
if ($match.Success) {
  $helpBlock=$match.Value
  $helpBlock=$helpBlock -replace '\s*"fallback"$',''
  $content=$content -replace $helpPattern,'"fallback"'
  if ($content -notmatch '"help"\s*:') {
    $insertPattern='("routing"\s*:\s*\{[\s\S]*?\}\s*),\s*"entities"'
    $replacement = '$1,' + "`r`n    " + $helpBlock + ",`r`n    " + '"entities"'
    $content=[regex]::Replace($content,$insertPattern,$replacement,1)
  }
  Set-Content -Path $path -Value $content -Encoding utf8
}
