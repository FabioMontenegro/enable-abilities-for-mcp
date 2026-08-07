# sync-to-svn.ps1
# Syncs plugin source to SVN trunk, respecting .svnignore exclusions.
#
# Since v2.1.0 the plugin has RUNTIME Composer dependencies (wp-media/mcp-oauth),
# so this script also builds a production vendor (composer install --no-dev)
# and installs it into trunk. vendor/ is svn:ignored on trunk, so new files
# are svn-added explicitly; files that disappeared upstream are svn-removed.
#
# Usage: .\sync-to-svn.ps1 [-SkipVendor]
param([switch]$SkipVendor)

$src = "e:\Workspace\Custom Plugins\enable-abilities-for-mcp\"
$dst = "e:\Workspace\svn-enable-abilities-for-mcp\trunk\"

# ── 1. Filtered copy: repo → trunk ─────────────────────────────────────
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

# ── 2. Production vendor (runtime deps, no phpcs/wpcs) ────────────────
if (-not $SkipVendor) {
    $build = Join-Path $env:TEMP "ewpa-svn-vendor-build"
    if (Test-Path $build) { Remove-Item -Recurse -Force $build }
    New-Item -ItemType Directory -Path $build | Out-Null
    Copy-Item (Join-Path $src "composer.json") $build -Force
    Copy-Item (Join-Path $src "composer.lock") $build -Force

    composer install --no-dev --optimize-autoloader --no-interaction --quiet --working-dir="$build"
    if ($LASTEXITCODE -ne 0) {
        Write-Error "composer install --no-dev failed - trunk vendor NOT updated"
        exit 1
    }

    $vendorDst = Join-Path $dst "vendor"
    if (Test-Path $vendorDst) { Remove-Item -Recurse -Force $vendorDst }
    Copy-Item -Recurse (Join-Path $build "vendor") $vendorDst
    Remove-Item -Recurse -Force $build
    Write-Host "Production vendor installed into trunk"
}

# ── 3. SVN housekeeping: version new files, remove vanished ones ──────
$svnCmd = Get-Command svn -ErrorAction SilentlyContinue
if ($svnCmd) {
    # vendor/ is svn:ignored on trunk — --force adds it (and any other
    # unversioned file the filtered copy produced) explicitly
    svn add --force $dst 2>$null | Out-Null

    $missing = @( svn status $dst | Where-Object { $_ -match '^!' } | ForEach-Object { $_.Substring(8).Trim() } )
    foreach ($m in $missing) {
        svn rm --force $m | Out-Null
    }
    if ($missing.Count -gt 0) {
        Write-Host "svn rm: $($missing.Count) vanished file(s)"
    }

    $changes = (svn status $dst | Measure-Object -Line).Lines
    Write-Host "svn status: $changes pending change(s) - review and commit with:"
    Write-Host '  svn commit trunk -m "feat: vX.Y.Z - ..."'
    Write-Host '  svn cp trunk tags\X.Y.Z; svn commit tags\X.Y.Z -m "tag: X.Y.Z"'
} else {
    Write-Warning "svn.exe not found - remember to svn add trunk\vendor manually"
}
