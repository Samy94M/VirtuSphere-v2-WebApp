#Requires -Version 5.1
<#
.SYNOPSIS
    Advisory-Pruefung aller Lockdateien gegen OSV und GitHub (Gate
    dependency-osv in scripts/check.ps1, Release-Lane).

.DESCRIPTION
    Leitet die Pakete aus den Locks unter dem Pruef-Root ab (composer.lock
    getrennt nach prod/dev, requirements*.txt, package-lock.json) und, wenn
    uebergeben, aus dem Wheelhouse des Offline-Bundles. Fragt OSV
    (POST /v1/querybatch, Details ueber GET /v1/vulns/<id>) und fuer Composer
    zusaetzlich GitHub: die globale Advisory-Datenbank und die
    Repository-Advisories der ausgelieferten Pakete, weil Maintainer-Advisories
    dort Tage vor Packagist und OSV stehen.

    Blockierend sind critical, high und unbewertete Advisories; moderate/low
    stehen als Hinweis in der Ausgabe. Ausnahmen nur ueber
    .dependency-osv-ignore.yaml (id, statement mit Owner, expired_at), Vertrag
    wie .trivyignore.yaml.

    Exitcodes: 0 keine blockierenden Funde | 1 Fund (dominiert) |
    2 Pruefumgebung unvollstaendig (kein Netz, Ratenlimit, Lock unlesbar,
    Zero-Match, Ausnahmedatei fehlt oder ist kaputt) | 3 ungueltiger Aufruf.
    Fehlendes Netz ist nie ein Pass.

    Nur ASCII in dieser Datei (PS 5.1 liest UTF-8 ohne BOM als ANSI).

.PARAMETER Wheelhouse
    Verzeichnis deps/wheels eines gebauten Offline-Bundles.

.PARAMETER RequireWheelhouse
    Ein fehlendes Wheelhouse ist infrastructure_error (die Release-Lane hat
    das Bundle im selben Lauf gebaut).

.PARAMETER ReportPath
    Maschinenlesbarer Bericht (UTF-8 JSON, ohne Token).

.PARAMETER Today
    Stichtag fuer Ausnahmen (JJJJ-MM-TT); Standard ist das heutige Datum.
#>
[CmdletBinding()]
param(
    [string]$Wheelhouse = '',
    [switch]$RequireWheelhouse,
    [string]$ReportPath = '',
    [string]$Today = ''
)

Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'

$scriptDir = $PSScriptRoot
$root = $env:VIRTUSPHERE_CHECK_ROOT
if (-not $root) { $root = Split-Path $scriptDir -Parent }
$root = ($root -replace '\\', '/').TrimEnd('/')

$stichtag = (Get-Date).Date
if ($Today -ne '') {
    $parsedDay = [datetime]::MinValue
    if (-not [datetime]::TryParseExact($Today, 'yyyy-MM-dd', [System.Globalization.CultureInfo]::InvariantCulture, [System.Globalization.DateTimeStyles]::None, [ref]$parsedDay)) {
        Write-Host ('check-dependency-osv: -Today erwartet JJJJ-MM-TT, nicht {0}' -f $Today)
        exit 3
    }
    $stichtag = $parsedDay
}

. (Join-Path (Join-Path $scriptDir 'lib') 'dependency-osv.ps1')

# HttpClient on both hosts: status, headers and the raw UTF-8 body without
# Invoke-WebRequest's per-version differences (5.1 throws on 4xx, decodes by
# guessing). One retry for transport errors and 5xx; everything else is the
# answer the caller classifies.
if ($PSVersionTable.PSVersion.Major -lt 6) {
    Add-Type -AssemblyName System.Net.Http
    [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor [System.Net.SecurityProtocolType]::Tls12
}
$client = New-Object System.Net.Http.HttpClient
$client.Timeout = [TimeSpan]::FromSeconds(60)
$userAgent = 'VirtuSphere-dependency-osv'
$transport = {
    param([string]$Method, [string]$Url, [string]$Body, [hashtable]$Headers)
    $attempt = 0
    while ($true) {
        $attempt++
        $request = New-Object System.Net.Http.HttpRequestMessage ((New-Object System.Net.Http.HttpMethod $Method), $Url)
        [void]$request.Headers.TryAddWithoutValidation('User-Agent', $userAgent)
        foreach ($key in $Headers.Keys) { [void]$request.Headers.TryAddWithoutValidation($key, [string]$Headers[$key]) }
        if ($Method -eq 'POST') {
            $request.Content = New-Object System.Net.Http.StringContent ($Body, [System.Text.Encoding]::UTF8, 'application/json')
        }
        try {
            $response = $client.SendAsync($request).GetAwaiter().GetResult()
        } catch {
            $inner = $_.Exception
            while ($null -ne $inner.InnerException) { $inner = $inner.InnerException }
            if ($attempt -lt 2) { Start-Sleep -Seconds 3; continue }
            throw ('[osv.network] keine Antwort von ' + ([uri]$Url).Host + ': ' + $inner.Message)
        }
        $status = [int]$response.StatusCode
        if ($status -ge 500 -and $attempt -lt 2) { Start-Sleep -Seconds 3; continue }
        $bytes = $response.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
        $headerMap = @{}
        foreach ($h in $response.Headers) { $headerMap[$h.Key.ToLowerInvariant()] = ($h.Value -join ', ') }
        return @{ Status = $status; Body = [System.Text.Encoding]::UTF8.GetString($bytes); Headers = $headerMap }
    }
}

$token = $env:GITHUB_TOKEN
if (-not $token) { $token = $env:GH_TOKEN }
if (-not $token) { $token = '' }

Write-Host ('==> dependency-osv: Root {0}' -f $root)
$result = Invoke-DependencyOsvAudit -Root $root -Http $transport -Today $stichtag -Wheelhouse $Wheelhouse `
    -RequireWheelhouse:$RequireWheelhouse -GitHubToken $token
$client.Dispose()

foreach ($s in $result.Sources) {
    Write-Host ('    Quelle {0}: {1} Paket(e){2}' -f $s.Name, $s.Packages, $(if ($s.Shipped) { ', ausgeliefert' } else { '' }))
}
foreach ($line in $result.Blocking) { Write-Host $line }
foreach ($line in $result.Failures) { Write-Host $line }
foreach ($line in $result.Infra) { Write-Host $line }
foreach ($line in $result.Notes) { Write-Host $line }
Write-Host ('dependency-osv: {0} Paket(e) aus {1} Quelle(n), {2} blockierend, {3} Lock-Befund(e), {4} Infrastrukturfehler, {5} Hinweis(e) => Exit {6}' -f `
    $result.PackageCount, @($result.Sources).Count, @($result.Blocking).Count, @($result.Failures).Count, @($result.Infra).Count, @($result.Notes).Count, $result.ExitCode)

if ($ReportPath -ne '') {
    $report = [ordered]@{
        version = 1
        generated = (Get-Date).ToString('s')
        today = $stichtag.ToString('yyyy-MM-dd')
        githubToken = ($token -ne '')
        exitCode = $result.ExitCode
        sources = @($result.Sources | ForEach-Object { [ordered]@{ name = $_.Name; shipped = $_.Shipped; packages = $_.Packages } })
        findings = @($result.Findings | ForEach-Object {
            [ordered]@{ ecosystem = $_.Ecosystem; name = $_.Name; version = $_.Version; id = $_.Id; aliases = @($_.Names)
                severity = (ConvertFrom-SeverityRank $_.Rank); blocking = (Test-SeverityBlocking $_.Rank); via = @($_.Via); locks = @($_.Sources); fixed = @($_.Fixed) }
        })
        blocking = @($result.Blocking)
        lockFindings = @($result.Failures)
        infrastructure = @($result.Infra)
        notes = @($result.Notes)
    }
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($ReportPath, (ConvertTo-Json -InputObject $report -Depth 6), $utf8NoBom)
}

exit $result.ExitCode
