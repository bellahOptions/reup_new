# Triage: find files whose timestamps or types are outliers versus their siblings,
# and verify magic bytes of binary/archive-like files.
param([string]$Root = (Resolve-Path "$PSScriptRoot\..").Path)
$ErrorActionPreference = 'SilentlyContinue'

$SkipDirs = @('\node_modules\', '\.git\', '\vendor\')

$files = Get-ChildItem -Path $Root -Recurse -File -Force | Where-Object {
    $p = $_.FullName
    -not ($p -like '*\node_modules\*' -or $p -like '*\.git\*' -or $p -like '*\vendor\*')
}

Write-Output "== 1. TIMESTAMP CLUSTERS (all project files) =="
$files | Group-Object { $_.LastWriteTime.ToString('yyyy-MM-dd') } | Sort-Object Name |
  ForEach-Object { "{0}  {1,6} files" -f $_.Name, $_.Count }
Write-Output ""

Write-Output "== 2. TIMESTAMP CLUSTERS (hand-written CODE only, excl. storage/public build/dist) =="
$code = $files | Where-Object {
    $_.FullName -notlike "$Root\storage\*" -and $_.FullName -notlike "$Root\public\build\*" -and
    $_.FullName -notlike "$Root\dist\*" -and $_.FullName -notlike "$Root\public\images\*"
}
$code | Group-Object { $_.LastWriteTime.ToString('yyyy-MM-dd HH') } | Sort-Object Name |
  ForEach-Object { "{0}  {1,6} files" -f $_.Name, $_.Count }
Write-Output ""

Write-Output "== 3. CODE FILES WRITTEN OUTSIDE THE MAIN IMPORT CLUSTER (after 2026-10-07 16:00) =="
$code | Where-Object { $_.LastWriteTime -gt [datetime]'2026-10-07 16:00' } |
  Sort-Object LastWriteTime |
  ForEach-Object { "{0:yyyy-MM-dd HH:mm:ss} {1,9} {2}" -f $_.LastWriteTime, $_.Length, $_.FullName.Replace($Root,'.') }
Write-Output ""

Write-Output "== 4. NON-TEXT FILES: magic byte vs extension =="
$binExt = @('.ai','.jpg','.jpeg','.png','.gif','.woff','.woff2','.ico','.zip','.gz','.tar','.exe','.dll','.so','.phar','.pdf','.webp','.ttf','.eot','.psd','.mp4','.db','.sqlite')
foreach ($f in $files | Where-Object { $binExt -contains $_.Extension.ToLower() }) {
    $fs = [System.IO.File]::OpenRead($f.FullName)
    $buf = New-Object byte[] 8
    [void]$fs.Read($buf, 0, 8)
    $fs.Close()
    $hex = ($buf | ForEach-Object { $_.ToString('X2') }) -join ' '
    $sig = if ($hex -like 'FF D8 FF*') { 'JPEG' }
           elseif ($hex -like '89 50 4E 47*') { 'PNG' }
           elseif ($hex -like '47 49 46 38*') { 'GIF' }
           elseif ($hex -like '77 4F 46 46*' -or $hex -like '77 4F 46 32*') { 'WOFF' }
           elseif ($hex -like '25 50 44 46*') { 'PDF' }
           elseif ($hex -like '50 4B 03 04*') { 'ZIP/PHAR' }
           elseif ($hex -like '1F 8B*') { 'GZIP' }
           elseif ($hex -like '4D 5A*') { 'PE/EXE' }
           elseif ($hex -like '25 21 50 53*') { 'PostScript' }
           elseif ($hex -like '38 42 50 53*') { 'PSD' }
           elseif ($hex -like '52 49 46 46*') { 'RIFF' }
           elseif ($hex -like '53 51 4C 69*') { 'SQLite' }
           else { 'UNKNOWN' }
    if ($f.Extension -eq '.ai') { $sig = "$sig (Illustrator=PostScript/PDF)" }
    "{0,-12} {1,-28} {2,10}  {3}" -f $f.Extension, $sig, $f.Length, $f.FullName.Replace($Root,'.')
}
Write-Output ""
Write-Output "== 5. FILES WITH EXECUTABLE-OR-SCRIPT CONTENT BUT NON-SCRIPT EXTENSION =="
$scanExt = @('.jpg','.jpeg','.png','.gif','.svg','.txt','.ai','.ico','.woff','.woff2','.css','.json')
foreach ($f in $files | Where-Object { $scanExt -contains $_.Extension.ToLower() -and $_.Length -lt 12MB }) {
    $t = [System.IO.File]::ReadAllText($f.FullName)
    if ($t -match '<\?php|<script[^>]*>|eval\s*\(|base64_decode|shell_exec|passthru' ) {
        $which = @()
        if ($t -match '<\?php') { $which += 'PHP-TAG' }
        if ($t -match 'eval\s*\(') { $which += 'eval' }
        if ($t -match 'base64_decode') { $which += 'base64_decode' }
        if ($t -match 'shell_exec') { $which += 'shell_exec' }
        if ($t -match '<script') { $which += 'script-tag' }
        "{0,-8} {1,9}  [{2}]  {3}" -f $f.Extension, $f.Length, ($which -join ','), $f.FullName.Replace($Root,'.')
    }
}
Write-Output ""
Write-Output "== triage complete =="
