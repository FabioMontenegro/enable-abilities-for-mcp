# sync-to-svn.ps1
# Syncs plugin source to SVN trunk, respecting .svnignore exclusions.
# Usage: .\sync-to-svn.ps1

$src = "e:\Workspace\Custom Plugins\enable-abilities-for-mcp\"
$dst = "e:\Workspace\svn-enable-abilities-for-mcp\trunk\"

# Parse .svnignore — skip comment lines and blanks
$svnIgnorePath = Join-Path $src ".svnignore"
$ignorePatterns = Get-Content $svnIgnorePath |
    Where-Object { $_ -notmatch '^\s*#' -and $_.Trim() -ne '' } |
    ForEach-Object { $_.Trim() }

function Test-ShouldExclude {
    param([string]$name)
    foreach ($pattern in $ignorePatterns) {
        if ($pattern -like '*' -or $pattern.Contains('*')) {
            if ($name -like $pattern) { return $true }
        } elseif ($name -eq $pattern) {
            return $true
        }
    }
    return $false
}

Get-ChildItem -Path $src -Recurse | Where-Object {
    $rel   = $_.FullName.Substring($src.Length)
    $parts = $rel -split '[\\\/]'
    # Exclude if any path segment matches an svnignore pattern
    -not ($parts | Where-Object { Test-ShouldExclude $_ })
} | ForEach-Object {
    $target = Join-Path $dst $_.FullName.Substring($src.Length)
    if ($_.PSIsContainer) {
        New-Item -ItemType Directory -Path $target -Force | Out-Null
    } else {
        Copy-Item -Path $_.FullName -Destination $target -Force
    }
}

Write-Host "Sync OK - excluded patterns: $($ignorePatterns.Count)"
