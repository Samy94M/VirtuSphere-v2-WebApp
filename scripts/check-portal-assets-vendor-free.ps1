#Requires -Version 5.1
<#
.SYNOPSIS
    Guard portal-assets-vendor-free (scripts/check.ps1, alle Lanes): unter den
    ausgelieferten Portal-Assets liegt keine Fremdbibliothek ohne Lock und
    Advisory-Pruefung.

.DESCRIPTION
    Die Advisory-Gates (composer-audit, npm-audit, dependency-osv) sehen nur,
    was in einer Lockdatei steht. Eine in Docker/WebAPI/portal/assets
    abgelegte Bibliothek (jQuery, Chart, Editor) waere fuer alle drei
    unsichtbar (Schwachstellenpruefung 04.11.2026, F9.4). Der Guard prueft
    jede Datei unter dem Asset-Baum, abgeleitet und ohne Dateiliste, auf die
    Merkmale fremden, gebauten Codes:

      [assets.minified]       Dateiname mit .min. oder eine JS/CSS-Zeile ueber 1000 Zeichen
      [assets.source-map]     .map-Datei oder sourceMappingURL-Verweis
      [assets.license-banner] @license, @preserve, /*!-Banner oder Copyright-Kopf
      [assets.bundle]         UMD-/AMD-/webpack-Huelle
      [assets.vendor-dir]     Unterordner vendor, node_modules, dist, third-party u. a.
      [assets.font]           Schriftdatei (woff, woff2, ttf, otf, eot)
      [assets.external-url]   Laden von einer http(s)-Adresse aus JS/CSS
      [assets.unknown-type]   Dateityp ausserhalb von JS, CSS und Bildern

    Eine Fremdbibliothek gehoert nicht hierher, sondern in ein eigenes Paket
    mit Lockdatei und Advisory-Quelle; erst danach darf dieser Guard sie
    zulassen.

    Exitcodes: 0 sauber | 1 Befund | 2 Zero-Match oder Asset-Baum fehlt.
    Liest VIRTUSPHERE_CHECK_ROOT (Guard-Harness). Nur ASCII in dieser Datei.
#>
[CmdletBinding()]
param()

Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'

$root = $env:VIRTUSPHERE_CHECK_ROOT
if (-not $root) { $root = Split-Path $PSScriptRoot -Parent }
$root = ($root -replace '\\', '/').TrimEnd('/')
$assetRoot = Join-Path $root 'Docker/WebAPI/portal/assets'

if (-not (Test-Path -LiteralPath $assetRoot -PathType Container)) {
    Write-Host '[assets.zero-match] Docker/WebAPI/portal/assets fehlt unter dem Pruef-Root'
    exit 2
}
$files = @(Get-ChildItem -LiteralPath $assetRoot -File -Recurse -Force | Sort-Object FullName)
$scripts = @($files | Where-Object { $_.Extension -ieq '.js' -or $_.Extension -ieq '.mjs' })
$styles = @($files | Where-Object { $_.Extension -ieq '.css' })
if ($scripts.Count -eq 0 -or $styles.Count -eq 0) {
    Write-Host ('[assets.zero-match] {0} JS- und {1} CSS-Datei(en) unter portal/assets; ein leerer Scan beweist nichts' -f $scripts.Count, $styles.Count)
    exit 2
}

$imageTypes = @('.png', '.svg', '.ico', '.gif', '.jpg', '.jpeg', '.webp')
$textTypes = @('.js', '.mjs', '.css', '.svg')
$fontTypes = @('.woff', '.woff2', '.ttf', '.otf', '.eot')
$vendorDirs = @('vendor', 'vendors', 'node_modules', 'bower_components', 'dist', 'third-party', 'third_party', 'thirdparty', 'external')
$contentRules = @(
    @{ Id = 'source-map'; Pattern = 'sourceMappingURL\s*='; Text = 'verweist auf eine Source-Map (Build-Ausgabe)' },
    @{ Id = 'license-banner'; Pattern = '@license\b|@preserve\b|/\*!|(?im)^\s*(?:/?\*+|//)\s*(?:\(c\)|copyright\b)'; Text = 'traegt einen Lizenz- oder Copyright-Kopf' },
    @{ Id = 'bundle'; Pattern = 'typeof\s+exports\s*===?\s*["'']object["'']\s*&&\s*typeof\s+module|define\.amd|__webpack_require__|webpackBootstrap|\bSystem\.register\('; Text = 'enthaelt eine UMD-/AMD-/Bundler-Huelle' },
    @{ Id = 'external-url'; Pattern = 'url\(\s*["'']?\s*(?:https?:)?//|@import\s+(?:url\()?\s*["'']?\s*(?:https?:)?//|\bimport\b\s*\(?[^;\n]*["''](?:https?:)?//|\bfrom\s+["''](?:https?:)?//'; Text = 'laedt von einer externen Adresse' }
)

$findings = @()
foreach ($file in $files) {
    $rel = (($file.FullName -replace '\\', '/').Substring($root.Length + 1))
    $inside = (($file.FullName -replace '\\', '/').Substring(($assetRoot -replace '\\', '/').TrimEnd('/').Length + 1))
    $segments = @($inside.Split('/') | Select-Object -SkipLast 1)
    foreach ($segment in $segments) {
        if ($vendorDirs -contains $segment.ToLowerInvariant()) { $findings += ('[assets.vendor-dir] {0}: liegt in einem Fremdcode-Ordner ({1})' -f $rel, $segment) }
    }
    $extension = $file.Extension.ToLowerInvariant()
    if ($file.Name -match '\.min\.') { $findings += ('[assets.minified] {0}: minifizierter Dateiname' -f $rel) }
    if ($extension -eq '.map') { $findings += ('[assets.source-map] {0}: Source-Map-Datei' -f $rel); continue }
    if ($fontTypes -contains $extension) { $findings += ('[assets.font] {0}: Schriftdatei ohne Lock' -f $rel); continue }
    if (($textTypes -notcontains $extension) -and ($imageTypes -notcontains $extension)) {
        $findings += ('[assets.unknown-type] {0}: Dateityp {1} ist weder Portal-Code noch Bild' -f $rel, $extension)
        continue
    }
    if ($textTypes -notcontains $extension) { continue }
    $content = [System.IO.File]::ReadAllText($file.FullName)
    foreach ($rule in $contentRules) {
        if ($content -match $rule.Pattern) { $findings += ('[assets.{0}] {1}: {2}' -f $rule.Id, $rel, $rule.Text) }
    }
    if ($extension -ne '.svg') {
        $longest = 0
        foreach ($line in ($content -split "`n")) { if ($line.Length -gt $longest) { $longest = $line.Length } }
        if ($longest -gt 1000) { $findings += ('[assets.minified] {0}: Zeile mit {1} Zeichen (minifizierter Code)' -f $rel, $longest) }
    }
}

if ($findings.Count -gt 0) {
    foreach ($f in $findings) { Write-Host $f }
    Write-Host ('portal-assets-vendor-free: {0} Befund(e) in {1} Datei(en). Fremdbibliotheken nur mit Lock und Advisory-Pruefung in einem eigenen Paket.' -f $findings.Count, $files.Count)
    exit 1
}
Write-Host ('portal-assets-vendor-free: {0} Datei(en) geprueft ({1} JS, {2} CSS), keine Fremdbibliothek' -f $files.Count, $scripts.Count, $styles.Count)
exit 0
