# update-release.ps1 — bump pinned upstream releases in hermes.plg
#
# Usage:
#   .\scripts\update-release.ps1 -Latest
#   .\scripts\update-release.ps1 -AgentTag v2026.6.19 -WebuiTag v0.51.701
#
# Computes tarball SHA256s from GitHub archive URLs, patches hermes.plg,
# bumps the .plg version to today's date, and writes a formatted summary.
# After running, review + commit manually.
#
param(
    [string]$AgentTag,
    [string]$WebuiTag,
    [switch]$Latest,
    [switch]$Push
)

$ErrorActionPreference = "Stop"
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$plgPath   = Resolve-Path (Join-Path $scriptDir "..\hermes.plg")

function Get-LatestReleaseTag {
    param([string]$repo)
    $url = "https://api.github.com/repos/$repo/releases/latest"
    $response = Invoke-RestMethod -Uri $url -Method Get
    return $response.tag_name
}

function Get-RemoteSha256 {
    param([string]$url)
    $tmp = [System.IO.Path]::GetTempFileName()
    try {
        # Use curl.exe — straight binary copy, no PowerShell response-string overhead
        curl.exe -fsSL "$url" -o "$tmp"
        $hash = Get-FileHash -Path $tmp -Algorithm SHA256
        return $hash.Hash.ToLower()
    } finally {
        Remove-Item -Path $tmp -Force
    }
}

# Resolve tags
if ($Latest) {
    Write-Host "[info] Querying GitHub for latest releases..."
    $AgentTag   = if ($AgentTag) { $AgentTag } else { Get-LatestReleaseTag "NousResearch/hermes-agent" }
    $WebuiTag   = if ($WebuiTag) { $WebuiTag } else { Get-LatestReleaseTag "nesquena/hermes-webui" }
    Write-Host "[info] Agent:  $AgentTag"
    Write-Host "[info] WebUI:  $WebuiTag"
}

if (-not $AgentTag -or -not $WebuiTag) {
    Write-Host "Error: both -AgentTag and -WebuiTag are required (or use -Latest)"
    exit 1
}

# Compute SHA256s
Write-Host "[info] Downloading + hashing agent tarball..."
$agentSha   = Get-RemoteSha256 "https://github.com/NousResearch/hermes-agent/archive/refs/tags/${AgentTag}.tar.gz"

Write-Host "[info] Downloading + hashing webui tarball..."
$webuiSha   = Get-RemoteSha256 "https://github.com/nesquena/hermes-webui/archive/refs/tags/${WebuiTag}.tar.gz"

$newVersion = Get-Date -Format "yyyy.MM.dd"

# Patch .plg — read/write via .NET APIs to keep BOMless UTF-8 and preserve Unicode
$utf8Bomless = New-Object System.Text.UTF8Encoding($false)
$plg = [System.IO.File]::ReadAllText($plgPath, [System.Text.Encoding]::UTF8)

$plg = [regex]::Replace($plg, '(?<=<!ENTITY version\s+")[^"]+', $newVersion)
$plg = [regex]::Replace($plg, '(?<=<!ENTITY agentTAG\s+")[^"]+', $AgentTag)
$agentUrl = "https://github.com/NousResearch/hermes-agent/archive/refs/tags/&agentTAG;.tar.gz"
$plg = [regex]::Replace($plg, '(?<=<!ENTITY agentURL\s+")[^"]+', $agentUrl)
$plg = [regex]::Replace($plg, '(?<=<!ENTITY agentTARSHA\s+")[^"]+', $agentSha)
$plg = [regex]::Replace($plg, '(?<=<!ENTITY webuiTAG\s+")[^"]+', $WebuiTag)
$webuiUrl = "https://github.com/nesquena/hermes-webui/archive/refs/tags/&webuiTAG;.tar.gz"
$plg = [regex]::Replace($plg, '(?<=<!ENTITY webuiURL\s+")[^"]+', $webuiUrl)
$plg = [regex]::Replace($plg, '(?<=<!ENTITY webuiTARSHA\s+")[^"]+', $webuiSha)

[System.IO.File]::WriteAllText($plgPath, $plg, $utf8Bomless)

Write-Host ""
Write-Host "Updated ${plgPath}:"
Write-Host "  version: $newVersion"
Write-Host "  agent:   $AgentTag  ($agentSha)"
Write-Host "  webui:   $WebuiTag  ($webuiSha)"
Write-Host ""

if ($Push) {
    Write-Host "[info] Staging, committing, and pushing..."
    git add hermes.plg
    git commit -m "release: bump upstreams ($newVersion)"
    git push origin master
    Write-Host "Pushed to origin/master."
} else {
    Write-Host "Next steps:"
    Write-Host "  git diff hermes.plg"
    Write-Host "  git add hermes.plg"
    Write-Host "  git commit -m `"release: bump upstreams ($newVersion)`""
    Write-Host "  git push origin master"
}
Write-Host ""
