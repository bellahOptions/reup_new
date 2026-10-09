# Security scan: hunt for webshells, backdoors, obfuscated payloads, and unexpected executables.
param(
    [string]$Root = (Resolve-Path "$PSScriptRoot\..").Path
)

$ErrorActionPreference = 'SilentlyContinue'

$SkipDirs = @('\node_modules\', '\.git\', '\vendor\')
$TextExt  = @('.php','.js','.mjs','.cjs','.ts','.jsx','.tsx','.json','.html','.htm',
              '.blade.php','.htaccess','.env','.txt','.md','.yml','.yaml','.xml','.sh',
              '.bat','.ps1','.py','.css','.sql','.ini','.conf','.twig','.tpl','.phtml','.phar')

$files = Get-ChildItem -Path $Root -Recurse -File -Force | Where-Object {
    $p = $_.FullName
    $skip = $false
    foreach ($s in $SkipDirs) { if ($p -like "*$s*") { $skip = $true; break } }
    -not $skip
}

Write-Output "== SCANNING $($files.Count) FILES (excluding node_modules/.git/vendor) =="
Write-Output ""

# ---- 1. Files that are unexpected / high risk by name or extension ----
Write-Output "== 1. HIGH-RISK FILE NAMES / EXTENSIONS =="
$files | Where-Object {
    $_.Name -match '(?i)^(shell|cmd|backdoor|hack|evil|b374k|c99|r57|wso|adminer|webshell|0x|uploader|defaces?)' -or
    $_.Extension -in @('.phar','.exe','.dll','.scr','.com','.pif','.vbs','.jse','.wsf','.hta','.lnk','.jar','.apk','.ps1','.sh','.bat','.cmd','.py')
} | ForEach-Object {
    $h = (Get-FileHash $_.FullName -Algorithm SHA256).Hash
    "{0,-10} {1,10} {2}  [{3}]" -f $_.Extension, $_.Length, $_.FullName.Replace($Root,'.'), $h
}
Write-Output ""

# ---- 2. Dangerous PHP function usage in project code ----
Write-Output "== 2. DANGEROUS FUNCTION CALLS IN PROJECT PHP =="
$danger = 'eval\s*\(|assert\s*\(|preg_replace\s*\(\s*["''][^"'']*e["'']|create_function\s*\(|' +
          'call_user_func\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)|' +
          'base64_decode\s*\(|gzinflate\s*\(|gzuncompress\s*\(|str_rot13\s*\(|' +
          'system\s*\(|passthru\s*\(|shell_exec\s*\(|popen\s*\(|proc_open\s*\(|' +
          'exec\s*\(|pcntl_exec|`[^`]+`|' +
          '\$_(GET|POST|REQUEST|COOKIE|SERVER)\s*\[[^\]]+\]\s*\(|' +
          'file_put_contents\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)' 
Get-ChildItem -Path $Root -Recurse -File -Force -Include *.php,*.phtml,*.inc |
  Where-Object { $p=$_.FullName; -not ($p -like '*\node_modules\*' -or $p -like '*\.git\*' -or $p -like '*\vendor\*') } |
  Select-String -Pattern $danger -AllMatches |
  ForEach-Object { "{0}:{1}: {2}" -f $_.Path.Replace($Root,'.'), $_.LineNumber, $_.Line.Trim() }
Write-Output ""

# ---- 3. Obfuscation markers ----
Write-Output "== 3. OBFUSCATION / ENCODED PAYLOAD MARKERS =="
Get-ChildItem -Path $Root -Recurse -File -Force |
  Where-Object { $p=$_.FullName; -not ($p -like '*\node_modules\*' -or $p -like '*\.git\*' -or $p -like '*\vendor\*') } |
  Select-String -Pattern 'eval\s*\(\s*(base64_decode|gzinflate|str_rot13|urldecode)|fromCharCode|atob\s*\(|String\.fromCharCode|\\x[0-9a-f]{2}\\x[0-9a-f]{2}\\x[0-9a-f]{2}|\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}' |
  ForEach-Object { "{0}:{1}: {2}" -f $_.Path.Replace($Root,'.'), $_.LineNumber, $_.Line.Trim().Substring(0,[Math]::Min(160,$_.Line.Trim().Length)) }
Write-Output ""

# ---- 4. Remote code fetch / exfil endpoints ----
Write-Output "== 4. SUSPICIOUS REMOTE URLS / IPs IN PROJECT CODE =="
Get-ChildItem -Path $Root -Recurse -File -Force -Include *.php,*.js,*.html,*.blade.php,*.json |
  Where-Object { $p=$_.FullName; -not ($p -like '*\node_modules\*' -or $p -like '*\.git\*' -or $p -like '*\vendor\*' -or $p -like '*\dist\*' -or $p -like '*\public\build\*') } |
  Select-String -Pattern 'https?://\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}|curl_exec\s*\(\s*\$|file_get_contents\s*\(\s*["'']https?://|fsockopen|stream_socket_client|wp-content|/tmp/[a-z0-9]{8,}\.(php|sh)' |
  ForEach-Object { "{0}:{1}: {2}" -f $_.Path.Replace($Root,'.'), $_.LineNumber, $_.Line.Trim().Substring(0,[Math]::Min(160,$_.Line.Trim().Length)) }
Write-Output ""

# ---- 5. Recently modified files in sensitive dirs ----
Write-Output "== 5. 25 MOST RECENTLY MODIFIED PROJECT FILES =="
$files | Sort-Object LastWriteTime -Descending | Select-Object -First 25 |
  ForEach-Object { "{0:yyyy-MM-dd HH:mm} {1,10} {2}" -f $_.LastWriteTime, $_.Length, $_.FullName.Replace($Root,'.') }
Write-Output ""

# ---- 6. PHP files with mismatched/none content, super-short files, or hidden names ----
Write-Output "== 6. HIDDEN OR ANOMALOUSLY-NAMED FILES =="
$files | Where-Object { $_.Attributes -match 'Hidden' -or $_.Name -match '^\.' -or $_.Name -match '\.(php|js|html)\.(bak|old|orig|txt)$' } |
  ForEach-Object { "{0,10} {1:yyyy-MM-dd} {2}" -f $_.Length, $_.LastWriteTime, $_.FullName.Replace($Root,'.') }
Write-Output ""
Write-Output "== SCAN COMPLETE =="
