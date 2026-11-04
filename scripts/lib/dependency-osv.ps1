# Dot-sourced library of the dependency-osv gate (scripts/check-dependency-osv.ps1).
# Importing defines functions only: no output, no environment change, no
# network. The entry script owns HTTP, progress, exit codes and the report.
# Nur ASCII (PS 5.1 liest UTF-8 ohne BOM als ANSI).

# --- JSON ----------------------------------------------------------------------
# ConvertFrom-Json in Windows PowerShell 5.1 rejects an empty property name, and
# tests/e2e/package-lock.json has one ("packages": {"": ...}). Both hosts
# therefore read JSON into dictionaries: pwsh through -AsHashtable, 5.1 through
# the JavaScriptSerializer with the size limit lifted.
function ConvertFrom-DependencyJson {
    param([string]$Text)
    if ($PSVersionTable.PSVersion.Major -ge 6) {
        return (ConvertFrom-Json -InputObject $Text -AsHashtable -Depth 100)
    }
    Add-Type -AssemblyName System.Web.Extensions
    $serializer = New-Object System.Web.Script.Serialization.JavaScriptSerializer
    $serializer.MaxJsonLength = [int]::MaxValue
    $serializer.RecursionLimit = 1000
    return $serializer.DeserializeObject($Text)
}

function Get-DependencyJsonValue {
    param($Object, [string]$Key)
    if ($null -eq $Object) { return $null }
    if ($Object -is [System.Collections.IDictionary]) {
        # ContainsKey: Dictionary[string,object] (JavaScriptSerializer, PS 5.1)
        # offers IDictionary.Contains only as an explicit interface member.
        if ($Object.ContainsKey($Key)) { return $Object[$Key] }
        return $null
    }
    return $null
}

function Get-DependencyJsonList {
    param($Value)
    $items = @()
    if ($null -eq $Value) { return ,$items }
    if (($Value -is [System.Collections.IEnumerable]) -and -not ($Value -is [string]) -and -not ($Value -is [System.Collections.IDictionary])) {
        foreach ($item in $Value) { $items += ,$item }
        return ,$items
    }
    return ,@($Value)
}

# --- Inventory: derived from the locks, never from a list -----------------------
function New-DependencyPackage {
    param([string]$Source, [string]$Ecosystem, [string]$Name, [string]$Version, [string]$Repository = '')
    return [pscustomobject]@{
        Source = $Source; Ecosystem = $Ecosystem; Name = $Name; Version = $Version; Repository = $Repository
    }
}

# owner/repo from a GitHub clone URL; empty for any other host.
function Get-GitHubRepositoryFromUrl {
    param([string]$Url)
    if ($Url -match '^(?:https?://|git@)github\.com[/:]([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$') {
        return ($Matches[1] + '/' + $Matches[2])
    }
    return ''
}

# Composer normalizes a leading "v" away; OSV accepts both spellings (measured
# 04.11.2026 with phpseclib v3.0.10 and 3.0.10), GitHub ranges never carry it.
function ConvertTo-ComposerVersion {
    param([string]$Version)
    if ($Version -match '^[vV](\d.*)$') { return $Matches[1] }
    return $Version
}

function Get-ComposerLockPackages {
    param([string]$Path, [ValidateSet('packages', 'packages-dev')] [string]$Section, [string]$Source)
    $lock = ConvertFrom-DependencyJson ([System.IO.File]::ReadAllText($Path))
    $result = @()
    foreach ($entry in (Get-DependencyJsonList (Get-DependencyJsonValue $lock $Section))) {
        $name = [string](Get-DependencyJsonValue $entry 'name')
        $version = ConvertTo-ComposerVersion ([string](Get-DependencyJsonValue $entry 'version'))
        $repository = Get-GitHubRepositoryFromUrl ([string](Get-DependencyJsonValue (Get-DependencyJsonValue $entry 'source') 'url'))
        $result += New-DependencyPackage $Source 'Packagist' $name $version $repository
    }
    return ,$result
}

# PEP 503: lower case, runs of - _ . collapse to one hyphen.
function ConvertTo-PypiName {
    param([string]$Name)
    return (($Name.ToLowerInvariant()) -replace '[-_.]+', '-')
}

# Every requirement line of a pip-compile lock. Options (--hash, -r, -c),
# comments and continuation lines are skipped; a requirement without "==" is
# returned with an empty version so the caller reports it as unpinned instead
# of silently auditing nothing.
function Get-PipRequirementPins {
    param([string]$Path, [string]$Source)
    $result = @()
    foreach ($raw in [System.IO.File]::ReadAllLines($Path)) {
        $line = $raw.Trim()
        if ($line -eq '' -or $line.StartsWith('#') -or $line.StartsWith('-')) { continue }
        if ($raw -match '^\s') { continue }
        $line = ($line -replace '\s*\\$', '')
        $line = ($line -split ';')[0].Trim()
        if ($line -match '^([A-Za-z0-9][A-Za-z0-9._-]*)(\[[^\]]*\])?\s*==\s*([^\s;#]+)$') {
            $result += New-DependencyPackage $Source 'PyPI' (ConvertTo-PypiName $Matches[1]) $Matches[3]
        } elseif ($line -match '^([A-Za-z0-9][A-Za-z0-9._-]*)') {
            $result += New-DependencyPackage $Source 'PyPI' (ConvertTo-PypiName $Matches[1]) ''
        }
    }
    return ,$result
}

# Wheelhouse of the offline bundle: name and version from the file names that
# pip download actually wrote (wheel: name-version-...whl, sdist:
# name-version.tar.gz/.zip). This is what the Ansible host installs, including
# the transitive packages that no lock pins (B-dependencies F3).
function Get-WheelhousePins {
    param([string]$Directory, [string]$Source)
    $result = @()
    foreach ($file in @(Get-ChildItem -LiteralPath $Directory -File -ErrorAction Stop | Sort-Object Name)) {
        $name = $null
        $version = $null
        if ($file.Name -match '^([A-Za-z0-9][A-Za-z0-9._]*)-([0-9][^-]*)-.+\.whl$') {
            $name = $Matches[1]; $version = $Matches[2]
        } elseif ($file.Name -match '^(.+)-([0-9][A-Za-z0-9.!+]*)\.(?:tar\.gz|zip)$') {
            $name = $Matches[1]; $version = $Matches[2]
        }
        if ($null -ne $name) { $result += New-DependencyPackage $Source 'PyPI' (ConvertTo-PypiName $name) $version }
    }
    return ,$result
}

# npm lockfile v2/v3: every installed node_modules entry; links and the root
# package ("") carry no published version.
function Get-NpmLockPackages {
    param([string]$Path, [string]$Source)
    $lock = ConvertFrom-DependencyJson ([System.IO.File]::ReadAllText($Path))
    $result = @()
    $packages = Get-DependencyJsonValue $lock 'packages'
    if ($null -eq $packages) { return ,$result }
    foreach ($key in @($packages.Keys | Sort-Object)) {
        if ($key -eq '') { continue }
        $entry = $packages[$key]
        if ((Get-DependencyJsonValue $entry 'link') -eq $true) { continue }
        $index = $key.LastIndexOf('node_modules/')
        if ($index -lt 0) { continue }
        $name = $key.Substring($index + 'node_modules/'.Length)
        $result += New-DependencyPackage $Source 'npm' $name ([string](Get-DependencyJsonValue $entry 'version'))
    }
    return ,$result
}

# A version the advisory sources can match. Branch aliases (dev-main,
# 1.x-dev) and empty versions are not; the caller reports them.
function Test-DependencyVersionQueryable {
    param([string]$Version)
    if ([string]::IsNullOrWhiteSpace($Version)) { return $false }
    if ($Version -match '^dev-' -or $Version -match '-dev$') { return $false }
    return ($Version -match '^\d')
}

# --- Severity -------------------------------------------------------------------
# Rank: 4 critical, 3 high, 2 moderate/medium, 1 low, 0 unknown. Unknown
# blocks like high: an advisory nobody rated is not evidence of harmlessness.
function ConvertTo-SeverityRank {
    param([string]$Label)
    switch -Regex (([string]$Label).ToLowerInvariant()) {
        '^critical$' { return 4 }
        '^high$' { return 3 }
        '^(moderate|medium)$' { return 2 }
        '^low$' { return 1 }
        default { return 0 }
    }
}

function ConvertFrom-SeverityRank {
    param([int]$Rank)
    switch ($Rank) { 4 { 'critical' } 3 { 'high' } 2 { 'moderate' } 1 { 'low' } default { 'unknown' } }
}

function Test-SeverityBlocking {
    param([int]$Rank)
    return (($Rank -ge 3) -or ($Rank -eq 0))
}

# CVSS v3.0/v3.1 base score (FIRST specification, section 7). Returns $null for
# anything that is not a complete v3 base vector.
function Get-CvssV3BaseScore {
    param([string]$Vector)
    if ($Vector -notmatch '^CVSS:3\.[01]/') { return $null }
    $metrics = @{}
    foreach ($part in ($Vector.Split('/') | Select-Object -Skip 1)) {
        $pair = $part.Split(':')
        if ($pair.Count -eq 2) { $metrics[$pair[0]] = $pair[1] }
    }
    foreach ($key in @('AV', 'AC', 'PR', 'UI', 'S', 'C', 'I', 'A')) {
        if (-not $metrics.ContainsKey($key)) { return $null }
    }
    $av = @{ N = 0.85; A = 0.62; L = 0.55; P = 0.2 }[$metrics.AV]
    $ac = @{ L = 0.77; H = 0.44 }[$metrics.AC]
    $ui = @{ N = 0.85; R = 0.62 }[$metrics.UI]
    $scopeChanged = ($metrics.S -eq 'C')
    if ($scopeChanged) { $pr = @{ N = 0.85; L = 0.68; H = 0.5 }[$metrics.PR] } else { $pr = @{ N = 0.85; L = 0.62; H = 0.27 }[$metrics.PR] }
    $cia = @{ H = 0.56; L = 0.22; N = 0.0 }
    $c = $cia[$metrics.C]; $i = $cia[$metrics.I]; $a = $cia[$metrics.A]
    foreach ($value in @($av, $ac, $ui, $pr, $c, $i, $a)) { if ($null -eq $value) { return $null } }
    $iss = 1 - ((1 - $c) * (1 - $i) * (1 - $a))
    if ($scopeChanged) {
        $impact = 7.52 * ($iss - 0.029) - 3.25 * [math]::Pow(($iss - 0.02), 15)
    } else {
        $impact = 6.42 * $iss
    }
    if ($impact -le 0) { return 0.0 }
    $exploitability = 8.22 * $av * $ac * $pr * $ui
    if ($scopeChanged) { $raw = [math]::Min(1.08 * ($impact + $exploitability), 10) } else { $raw = [math]::Min($impact + $exploitability, 10) }
    # Roundup as defined in CVSS v3.1 Appendix A (integer arithmetic).
    $intInput = [math]::Round($raw * 100000)
    if (($intInput % 10000) -eq 0) { return ($intInput / 100000.0) }
    return (([math]::Floor($intInput / 10000) + 1) / 10.0)
}

function ConvertTo-CvssSeverityRank {
    param($Score)
    if ($null -eq $Score) { return 0 }
    if ($Score -ge 9.0) { return 4 }
    if ($Score -ge 7.0) { return 3 }
    if ($Score -ge 4.0) { return 2 }
    return 1
}

# Highest rank an OSV record states: database_specific.severity (GitHub
# reviewed records) and every CVSS v3 vector at record or affected level.
# CVSS v4 vectors are not scored here; a record that only has one stays
# unknown unless an alias of the same group carries a rating.
function Get-OsvRecordSeverityRank {
    param($Record)
    $rank = ConvertTo-SeverityRank ([string](Get-DependencyJsonValue (Get-DependencyJsonValue $Record 'database_specific') 'severity'))
    $vectors = @()
    foreach ($s in (Get-DependencyJsonList (Get-DependencyJsonValue $Record 'severity'))) { $vectors += [string](Get-DependencyJsonValue $s 'score') }
    foreach ($affected in (Get-DependencyJsonList (Get-DependencyJsonValue $Record 'affected'))) {
        foreach ($s in (Get-DependencyJsonList (Get-DependencyJsonValue $affected 'severity'))) { $vectors += [string](Get-DependencyJsonValue $s 'score') }
    }
    foreach ($vector in $vectors) {
        $cvssRank = ConvertTo-CvssSeverityRank (Get-CvssV3BaseScore $vector)
        if ($cvssRank -gt $rank) { $rank = $cvssRank }
    }
    return $rank
}

# Group the advisory ids OSV returned for one package by their aliases, so a
# GHSA and the PYSEC/CVE record of the same flaw count once. $Records maps
# id -> OSV record. Withdrawn records are dropped.
function Get-OsvAdvisoryGroups {
    param([string[]]$Ids, [hashtable]$Records)
    $groups = New-Object System.Collections.ArrayList
    foreach ($id in @($Ids | Sort-Object -Unique)) {
        $record = $Records[$id]
        if ($null -eq $record) { continue }
        if ($null -ne (Get-DependencyJsonValue $record 'withdrawn')) { continue }
        $names = @($id) + @((Get-DependencyJsonList (Get-DependencyJsonValue $record 'aliases')) | ForEach-Object { [string]$_ })
        $rank = Get-OsvRecordSeverityRank $record
        $target = $null
        foreach ($group in $groups) {
            if (@($names | Where-Object { $group.Names -contains $_ }).Count -gt 0) { $target = $group; break }
        }
        if ($null -eq $target) {
            $target = [pscustomobject]@{ Ids = @(); Names = @(); Rank = $rank }
            [void]$groups.Add($target)
        }
        $target.Ids = @($target.Ids + $id | Sort-Object -Unique)
        $target.Names = @($target.Names + $names | Sort-Object -Unique)
        if ($rank -gt $target.Rank) { $target.Rank = $rank }
    }
    return ,@($groups)
}

# Display id of a group: the GHSA if there is one, else the first id.
function Get-AdvisoryPrimaryId {
    param([string[]]$Ids)
    $ghsa = @($Ids | Where-Object { $_ -like 'GHSA-*' } | Sort-Object)
    if ($ghsa.Count -gt 0) { return $ghsa[0] }
    return (@($Ids | Sort-Object)[0])
}

# --- Version ranges of GitHub advisories ----------------------------------------
# Loose semantic comparison for Composer versions: numeric dot segments first,
# a pre-release suffix sorts before its release. Returns -1, 0 or 1.
function Compare-DependencyVersion {
    param([string]$Left, [string]$Right)
    $parse = {
        param([string]$Text)
        $t = ConvertTo-ComposerVersion $Text.Trim()
        $pre = ''
        if ($t -match '^([0-9][0-9.]*)[-+]?(.*)$') { $core = $Matches[1].TrimEnd('.'); $pre = $Matches[2] } else { $core = '0' }
        $numbers = @($core.Split('.') | ForEach-Object { [long]$_ })
        return @{ Numbers = $numbers; Pre = $pre }
    }
    $a = & $parse $Left
    $b = & $parse $Right
    $length = [math]::Max($a.Numbers.Count, $b.Numbers.Count)
    for ($k = 0; $k -lt $length; $k++) {
        $x = 0; $y = 0
        if ($k -lt $a.Numbers.Count) { $x = $a.Numbers[$k] }
        if ($k -lt $b.Numbers.Count) { $y = $b.Numbers[$k] }
        if ($x -lt $y) { return -1 }
        if ($x -gt $y) { return 1 }
    }
    if ($a.Pre -eq $b.Pre) { return 0 }
    if ($a.Pre -eq '') { return 1 }
    if ($b.Pre -eq '') { return -1 }
    return [math]::Sign([string]::CompareOrdinal($a.Pre, $b.Pre))
}

# Does a GitHub vulnerable_version_range ("< 1.2", ">= 3.0.0, <= 3.0.57",
# "= 2.0.1") contain $Version? Maintainer-written repository advisories also
# carry a bare version ("3.0.0") that means the first affected release; with a
# patched version above it the range is read as [bare, patched). Returns
# 'affected', 'unaffected' or 'unparsable'.
function Test-GitHubRangeAffects {
    param([string]$Version, [string]$Range, [string]$Patched = '')
    if ([string]::IsNullOrWhiteSpace($Range)) { return 'unparsable' }
    $constraints = @($Range.Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
    if ($constraints.Count -eq 0) { return 'unparsable' }
    if (($constraints.Count -eq 1) -and ($constraints[0] -match '^v?\d[\w.+-]*$')) {
        $firstPatched = (@($Patched.Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' }) | Select-Object -First 1)
        if ($firstPatched -and ((Compare-DependencyVersion $constraints[0] $firstPatched) -lt 0)) {
            $constraints = @(('>= ' + $constraints[0]), ('< ' + $firstPatched))
        } else {
            $constraints = @('= ' + $constraints[0])
        }
    }
    foreach ($constraint in $constraints) {
        if ($constraint -notmatch '^(>=|<=|>|<|=)?\s*(v?\d[\w.+-]*)$') { return 'unparsable' }
        $op = $Matches[1]
        if (-not $op) { $op = '=' }
        $cmp = Compare-DependencyVersion $Version $Matches[2]
        $ok = $false
        switch ($op) {
            '>=' { $ok = ($cmp -ge 0) }
            '<=' { $ok = ($cmp -le 0) }
            '>' { $ok = ($cmp -gt 0) }
            '<' { $ok = ($cmp -lt 0) }
            '=' { $ok = ($cmp -eq 0) }
        }
        if (-not $ok) { return 'unaffected' }
    }
    return 'affected'
}

# --- Exception file (same contract as .trivyignore.yaml) ------------------------
# Accepted shape, nothing else:
#   vulnerabilities: []
# or
#   vulnerabilities:
#     - id: GHSA-xxxx-xxxx-xxxx
#       statement: "why acceptable; Owner: who"
#       expired_at: 2026-12-31
# Returns @{ Entries; Errors }. Any error is a broken contract (exit 2), never
# a silently ignored line.
function Read-DependencyIgnoreFile {
    param([string]$Path)
    $entries = @()
    $errors = @()
    $current = $null
    $seenRoot = $false
    $lineNumber = 0
    foreach ($raw in [System.IO.File]::ReadAllLines($Path)) {
        $lineNumber++
        $line = $raw.TrimEnd()
        if ($line.Trim() -eq '' -or $line.Trim().StartsWith('#')) { continue }
        if ($line -match '^vulnerabilities:\s*\[\]\s*$') { $seenRoot = $true; continue }
        if ($line -match '^vulnerabilities:\s*$') { $seenRoot = $true; continue }
        if (-not $seenRoot) { $errors += ('Zeile {0}: erwartet "vulnerabilities:"' -f $lineNumber); continue }
        $key = $null; $value = $null
        if ($line -match '^\s+-\s+([a-z_]+):\s*(.*)$') {
            $current = @{ line = $lineNumber }
            $entries += $current
            $key = $Matches[1]; $value = $Matches[2]
        } elseif ($line -match '^\s+([a-z_]+):\s*(.*)$' -and $null -ne $current) {
            $key = $Matches[1]; $value = $Matches[2]
        } else {
            $errors += ('Zeile {0}: nicht im erlaubten Format' -f $lineNumber)
            continue
        }
        $value = $value.Trim()
        if ($value -match '^"(.*)"$' -or $value -match "^'(.*)'$") { $value = $Matches[1] }
        if (@('id', 'statement', 'expired_at') -notcontains $key) { $errors += ('Zeile {0}: unbekannter Schluessel {1}' -f $lineNumber, $key); continue }
        $current[$key] = $value
    }
    if (-not $seenRoot) { $errors += 'Schluessel "vulnerabilities" fehlt' }
    $valid = @()
    foreach ($entry in $entries) {
        $missing = @(@('id', 'statement', 'expired_at') | Where-Object { -not $entry.ContainsKey($_) -or [string]::IsNullOrWhiteSpace($entry[$_]) })
        if ($missing.Count -gt 0) { $errors += ('Eintrag ab Zeile {0}: es fehlt {1}' -f $entry.line, ($missing -join ', ')); continue }
        if ($entry.statement -notmatch 'Owner:\s*\S') { $errors += ('Eintrag {0}: statement ohne "Owner: <wer>"' -f $entry.id); continue }
        $date = [datetime]::MinValue
        if (-not [datetime]::TryParseExact($entry.expired_at, 'yyyy-MM-dd', [System.Globalization.CultureInfo]::InvariantCulture, [System.Globalization.DateTimeStyles]::None, [ref]$date)) {
            $errors += ('Eintrag {0}: expired_at ist kein Datum JJJJ-MM-TT' -f $entry.id); continue
        }
        $valid += [pscustomobject]@{ Id = $entry.id; Statement = $entry.statement; ExpiresAt = $date }
    }
    return @{ Entries = $valid; Errors = $errors }
}

# The exception that covers a finding: same id or alias, not yet expired.
# $Today is the date the run treats as today (the exception is valid through
# its expired_at day). Returns @{ Active; Expired } with the matching entries.
function Find-DependencyException {
    param([string[]]$Names, $Entries, [datetime]$Today)
    $active = @()
    $expired = @()
    foreach ($entry in @($Entries)) {
        if (@($Names | Where-Object { $_ -ieq $entry.Id }).Count -eq 0) { continue }
        if ($entry.ExpiresAt.Date -ge $Today.Date) { $active += $entry } else { $expired += $entry }
    }
    return @{ Active = $active; Expired = $expired }
}

# --- Lock discovery ------------------------------------------------------------
# Every lock below the check root is audited; nobody maintains a list of them.
# Output trees and nested working trees are skipped like Get-CheckFiles does in
# the runner. A manifest without its lock and a lock format this gate cannot
# read are findings: either would leave packages outside the audit.
function Find-DependencyLockFiles {
    param([string]$Root)
    $skip = @('vendor', 'node_modules', '.git', '.vs', 'var', 'bin', 'obj', 'qa-artifacts')
    $pending = New-Object 'System.Collections.Generic.Stack[System.IO.DirectoryInfo]'
    $pending.Push((Get-Item -LiteralPath $Root -Force -ErrorAction Stop))
    $locks = @()
    $problems = @()
    while ($pending.Count -gt 0) {
        $directory = $pending.Pop()
        $names = @(Get-ChildItem -LiteralPath $directory.FullName -File -Force -ErrorAction SilentlyContinue | ForEach-Object { $_.Name })
        foreach ($name in $names) {
            $full = Join-Path $directory.FullName $name
            if ($name -ceq 'composer.lock') { $locks += @{ Kind = 'composer'; Path = $full } }
            elseif ($name -ceq 'package-lock.json' -or $name -ceq 'npm-shrinkwrap.json') { $locks += @{ Kind = 'npm'; Path = $full } }
            elseif ($name -like 'requirements*.txt') { $locks += @{ Kind = 'pip'; Path = $full } }
            elseif (@('yarn.lock', 'pnpm-lock.yaml', 'Pipfile.lock', 'poetry.lock', 'uv.lock') -contains $name) {
                $problems += @{ Code = 'unsupported-lock'; Path = $full }
            }
        }
        if (($names -ccontains 'composer.json') -and -not ($names -ccontains 'composer.lock')) {
            $problems += @{ Code = 'unlocked-manifest'; Path = (Join-Path $directory.FullName 'composer.json') }
        }
        if (($names -ccontains 'package.json') -and -not (($names -ccontains 'package-lock.json') -or ($names -ccontains 'npm-shrinkwrap.json'))) {
            $problems += @{ Code = 'unlocked-manifest'; Path = (Join-Path $directory.FullName 'package.json') }
        }
        foreach ($child in @(Get-ChildItem -LiteralPath $directory.FullName -Directory -Force -ErrorAction SilentlyContinue)) {
            if ($skip -contains $child.Name) { continue }
            if (Test-Path -LiteralPath (Join-Path $child.FullName '.git')) { continue }
            $pending.Push($child)
        }
    }
    return @{ Locks = @($locks | Sort-Object { $_.Path }); Problems = $problems }
}

function Get-DependencyRelativePath {
    param([string]$Root, [string]$Path)
    $r = ($Root -replace '\\', '/').TrimEnd('/') + '/'
    $p = ($Path -replace '\\', '/')
    if ($p.StartsWith($r, [System.StringComparison]::OrdinalIgnoreCase)) { return $p.Substring($r.Length) }
    return $p
}

# Fixed versions OSV states for this package (affected[].ranges[].events[].fixed).
function Get-OsvFixedVersions {
    param($Record, [string]$Ecosystem, [string]$Name)
    $fixed = @()
    foreach ($affected in (Get-DependencyJsonList (Get-DependencyJsonValue $Record 'affected'))) {
        $package = Get-DependencyJsonValue $affected 'package'
        if ([string](Get-DependencyJsonValue $package 'ecosystem') -ne $Ecosystem) { continue }
        $affectedName = [string](Get-DependencyJsonValue $package 'name')
        if ($Ecosystem -eq 'PyPI') { $affectedName = ConvertTo-PypiName $affectedName }
        if ($affectedName -ine $Name) { continue }
        foreach ($range in (Get-DependencyJsonList (Get-DependencyJsonValue $affected 'ranges'))) {
            foreach ($rangeEvent in (Get-DependencyJsonList (Get-DependencyJsonValue $range 'events'))) {
                $value = Get-DependencyJsonValue $rangeEvent 'fixed'
                if ($null -ne $value) { $fixed += [string]$value }
            }
        }
    }
    return ,@($fixed | Sort-Object -Unique)
}

# One advisory as a finding of one locked package. Findings of the same
# package that share an id or alias merge into one (OSV and GitHub name the
# same flaw); the higher rating wins.
function Add-DependencyFinding {
    param([System.Collections.ArrayList]$Findings, $Package, [string[]]$Names, [int]$Rank, [string]$Via, [string[]]$Fixed)
    $Names = @($Names | Where-Object { $_ })
    $Fixed = @($Fixed | Where-Object { $_ })
    foreach ($f in $Findings) {
        if ($f.Ecosystem -eq $Package.Ecosystem -and $f.Name -ieq $Package.Name -and $f.Version -eq $Package.Version -and
            @($Names | Where-Object { $f.Names -contains $_ }).Count -gt 0) {
            $f.Names = @($f.Names + $Names | Sort-Object -Unique)
            $f.Id = Get-AdvisoryPrimaryId $f.Names
            if ($Rank -gt $f.Rank) { $f.Rank = $Rank }
            if ($f.Via -notcontains $Via) { $f.Via = @($f.Via + $Via) }
            if ($f.Sources -notcontains $Package.Source) { $f.Sources = @($f.Sources + $Package.Source) }
            $f.Fixed = @($f.Fixed + $Fixed | Sort-Object -Unique)
            return
        }
    }
    [void]$Findings.Add([pscustomobject]@{
        Ecosystem = $Package.Ecosystem; Name = $Package.Name; Version = $Package.Version
        Id = (Get-AdvisoryPrimaryId $Names); Names = @($Names | Sort-Object -Unique); Rank = $Rank; Via = @($Via)
        Sources = @($Package.Source); Fixed = @($Fixed | Sort-Object -Unique)
    })
}

# GitHub answer with the rate-limit case turned into a readable reason.
function Invoke-DependencyGitHubGet {
    param([scriptblock]$Http, [string]$Url, [hashtable]$Headers)
    $answer = & $Http 'GET' $Url '' $Headers
    if (($answer.Status -eq 403 -or $answer.Status -eq 429) -and ([string]$answer.Headers['x-ratelimit-remaining']) -eq '0') {
        $reset = [string]$answer.Headers['x-ratelimit-reset']
        $when = $reset
        if ($reset -match '^\d+$') { $when = ([datetime]'1970-01-01Z').AddSeconds([double]$reset).ToUniversalTime().ToString('yyyy-MM-dd HH:mm') + ' UTC' }
        throw ('[osv.github-rate-limit] GitHub-Ratenlimit erschoepft bis ' + $when + ' (ohne Token 60 Anfragen je Stunde; GITHUB_TOKEN hebt es an)')
    }
    if ($answer.Status -ne 200) { throw ('[osv.github-http] HTTP ' + $answer.Status + ' fuer ' + $Url) }
    return $answer
}

function Get-DependencyNextLink {
    param($Answer)
    $link = [string]$Answer.Headers['link']
    foreach ($part in $link.Split(',')) {
        if ($part -match '<([^>]+)>\s*;\s*rel="next"') { return $Matches[1] }
    }
    return ''
}

# Findings from one page of GitHub advisories (global list or repository list)
# for the given locked packages. The range is evaluated here even where GitHub
# filtered already, because one answer names several packages.
function Add-GitHubAdvisoryFindings {
    param([System.Collections.ArrayList]$Findings, [System.Collections.ArrayList]$Notes, $Advisories, $Packages, [string]$Via, [string]$PatchedField)
    foreach ($advisory in (Get-DependencyJsonList $Advisories)) {
        $state = Get-DependencyJsonValue $advisory 'state'
        if ($null -ne $state -and [string]$state -ne 'published') { continue }
        if ($null -ne (Get-DependencyJsonValue $advisory 'withdrawn_at')) { continue }
        $names = @([string](Get-DependencyJsonValue $advisory 'ghsa_id'))
        $cve = Get-DependencyJsonValue $advisory 'cve_id'
        if ($cve) { $names += [string]$cve }
        $rank = ConvertTo-SeverityRank ([string](Get-DependencyJsonValue $advisory 'severity'))
        foreach ($v in (Get-DependencyJsonList (Get-DependencyJsonValue $advisory 'vulnerabilities'))) {
            $vPackage = Get-DependencyJsonValue $v 'package'
            if ([string](Get-DependencyJsonValue $vPackage 'ecosystem') -ne 'composer') { continue }
            $vName = [string](Get-DependencyJsonValue $vPackage 'name')
            foreach ($p in @($Packages | Where-Object { $_.Name -ieq $vName })) {
                $patched = [string](Get-DependencyJsonValue $v $PatchedField)
                $verdict = Test-GitHubRangeAffects $p.Version ([string](Get-DependencyJsonValue $v 'vulnerable_version_range')) $patched
                if ($verdict -eq 'unaffected') { continue }
                if ($verdict -eq 'unparsable') { [void]$Notes.Add(('[osv.range-unparsable] {0} {1}: Bereich nicht auswertbar, als betroffen gewertet' -f $names[0], $p.Name)) }
                Add-DependencyFinding $Findings $p $names $rank $Via @($patched)
            }
        }
    }
}

# --- Audit flow ------------------------------------------------------------------
# $Http is the transport: & $Http <Method> <Url> <Body> <Headers> returns
# @{ Status = int; Body = string; Headers = hashtable with lower-case keys } or
# throws when no answer arrived. The entry script passes HttpClient; the Pester
# suite passes recorded answers. Progress follows the repository contract:
# "[n/total] RUN <unit>" before and "[n/total] <PASS|FAIL|INFRA> <unit>" after
# every unit, with the total known before the first unit starts.
function Invoke-DependencyOsvAudit {
    param(
        [string]$Root,
        [scriptblock]$Http,
        [datetime]$Today,
        [string]$Wheelhouse = '',
        [switch]$RequireWheelhouse,
        [string]$OsvBase = 'https://api.osv.dev',
        [string]$GitHubBase = 'https://api.github.com',
        [string]$GitHubToken = ''
    )
    $infra = New-Object System.Collections.ArrayList
    $failures = New-Object System.Collections.ArrayList
    $notes = New-Object System.Collections.ArrayList
    $findings = New-Object System.Collections.ArrayList
    $sources = @()

    # Exception contract first: a missing or broken file means nobody can tell
    # which finding was accepted, so nothing is evaluated as pass.
    $ignorePath = Join-Path $Root '.dependency-osv-ignore.yaml'
    $exceptions = @()
    if (-not (Test-Path -LiteralPath $ignorePath)) {
        [void]$infra.Add('[osv.ignore-missing] .dependency-osv-ignore.yaml fehlt (Ausnahme-Vertrag)')
    } else {
        $parsed = Read-DependencyIgnoreFile $ignorePath
        foreach ($e in $parsed.Errors) { [void]$infra.Add('[osv.ignore-invalid] ' + $e) }
        $exceptions = $parsed.Entries
    }

    # Inventory.
    $discovery = Find-DependencyLockFiles $Root
    foreach ($problem in $discovery.Problems) {
        [void]$failures.Add(('[osv.{0}] {1}' -f $problem.Code, (Get-DependencyRelativePath $Root $problem.Path)))
    }
    foreach ($lock in $discovery.Locks) {
        $rel = Get-DependencyRelativePath $Root $lock.Path
        $lockSources = @()
        try {
            switch ($lock.Kind) {
                'composer' {
                    $lockSources += @{ Name = ($rel + ' (prod)'); Shipped = $true; Packages = (Get-ComposerLockPackages $lock.Path 'packages' ($rel + ' (prod)')) }
                    $lockSources += @{ Name = ($rel + ' (dev)'); Shipped = $false; Packages = (Get-ComposerLockPackages $lock.Path 'packages-dev' ($rel + ' (dev)')) }
                }
                'npm' { $lockSources += @{ Name = $rel; Shipped = $false; Packages = (Get-NpmLockPackages $lock.Path $rel) } }
                'pip' { $lockSources += @{ Name = $rel; Shipped = $false; Packages = (Get-PipRequirementPins $lock.Path $rel) } }
            }
        } catch {
            [void]$infra.Add(('[osv.lock-unreadable] {0}: {1}' -f $rel, $_.Exception.Message))
            continue
        }
        $count = 0
        foreach ($s in $lockSources) { $count += @($s.Packages).Count }
        if ($count -eq 0) { [void]$infra.Add(('[osv.zero-match] {0} ohne ein einziges Paket' -f $rel)) }
        $sources += $lockSources
    }
    if (@($discovery.Locks).Count -eq 0) { [void]$infra.Add('[osv.zero-match] keine Lockdatei unter dem Pruef-Root') }
    if ($Wheelhouse -ne '' -and (Test-Path -LiteralPath $Wheelhouse -PathType Container)) {
        $pins = Get-WheelhousePins $Wheelhouse 'offline-bundle deps/wheels'
        if (@($pins).Count -eq 0) { [void]$infra.Add('[osv.wheelhouse-empty] Wheelhouse ohne Wheel: ' + $Wheelhouse) }
        else { $sources += @{ Name = 'offline-bundle deps/wheels'; Shipped = $true; Packages = $pins } }
    } elseif ($RequireWheelhouse) {
        [void]$infra.Add('[osv.wheelhouse-missing] Wheelhouse des Offline-Bundles fehlt: ' + $Wheelhouse)
    }
    foreach ($s in $sources) {
        foreach ($p in @($s.Packages)) {
            if (-not (Test-DependencyVersionQueryable $p.Version)) {
                [void]$failures.Add(('[osv.unpinned] {0}: {1} hat keine pruefbare Version ({2})' -f $s.Name, $p.Name, $p.Version))
            }
        }
    }

    $composerPackages = @()
    $repoTargets = @()
    foreach ($s in $sources) {
        foreach ($p in @($s.Packages)) {
            if ($p.Ecosystem -ne 'Packagist' -or -not (Test-DependencyVersionQueryable $p.Version)) { continue }
            $composerPackages += $p
            if ($s.Shipped -and $p.Repository -ne '' -and $repoTargets -notcontains $p.Repository) { $repoTargets += $p.Repository }
        }
    }
    $units = @()
    foreach ($s in $sources) { if (@($s.Packages).Count -gt 0) { $units += @{ Kind = 'osv'; Name = ('osv ' + $s.Name); Source = $s } } }
    if ($composerPackages.Count -gt 0) { $units += @{ Kind = 'github-global'; Name = 'github-advisories composer' } }
    foreach ($repo in $repoTargets) { $units += @{ Kind = 'github-repo'; Name = ('github-repository-advisories ' + $repo); Repository = $repo } }

    $records = @{}
    $githubHeaders = @{ 'Accept' = 'application/vnd.github+json'; 'X-GitHub-Api-Version' = '2022-11-28' }
    if ($GitHubToken -ne '') { $githubHeaders['Authorization'] = 'Bearer ' + $GitHubToken }

    $total = $units.Count
    $position = 0
    foreach ($unit in $units) {
        $position++
        Write-Host ('[{0}/{1}] RUN {2}' -f $position, $total, $unit.Name)
        $before = $findings.Count
        $state = 'PASS'
        try {
            switch ($unit.Kind) {
                'osv' {
                    $packages = @($unit.Source.Packages | Where-Object { Test-DependencyVersionQueryable $_.Version })
                    for ($offset = 0; $offset -lt $packages.Count; $offset += 500) {
                        $chunk = @($packages[$offset..([math]::Min($offset + 499, $packages.Count - 1))])
                        $queries = @($chunk | ForEach-Object { @{ package = @{ ecosystem = $_.Ecosystem; name = $_.Name }; version = $_.Version } })
                        $body = ConvertTo-Json -InputObject @{ queries = $queries } -Depth 6 -Compress
                        $answer = & $Http 'POST' ($OsvBase + '/v1/querybatch') $body @{}
                        if ($answer.Status -ne 200) { throw ('[osv.http] HTTP ' + $answer.Status + ' von querybatch') }
                        $results = Get-DependencyJsonList (Get-DependencyJsonValue (ConvertFrom-DependencyJson $answer.Body) 'results')
                        if ($results.Count -ne $chunk.Count) { throw ('[osv.http] querybatch lieferte ' + $results.Count + ' statt ' + $chunk.Count + ' Ergebnisse') }
                        for ($k = 0; $k -lt $chunk.Count; $k++) {
                            if ($null -ne (Get-DependencyJsonValue $results[$k] 'next_page_token')) {
                                throw ('[osv.pagination] mehr als eine Seite Advisories fuer ' + $chunk[$k].Name + '; das Gate liest keine Folgeseiten und meldet deshalb kein Ergebnis')
                            }
                            $ids = @((Get-DependencyJsonList (Get-DependencyJsonValue $results[$k] 'vulns')) | ForEach-Object { [string](Get-DependencyJsonValue $_ 'id') })
                            if ($ids.Count -eq 0) { continue }
                            foreach ($id in $ids) {
                                if ($records.ContainsKey($id)) { continue }
                                $detail = & $Http 'GET' ($OsvBase + '/v1/vulns/' + [uri]::EscapeDataString($id)) '' @{}
                                if ($detail.Status -ne 200) { throw ('[osv.http] HTTP ' + $detail.Status + ' fuer ' + $id) }
                                $records[$id] = ConvertFrom-DependencyJson $detail.Body
                            }
                            foreach ($group in (Get-OsvAdvisoryGroups $ids $records)) {
                                $fixed = @()
                                foreach ($id in $group.Ids) { $fixed += Get-OsvFixedVersions $records[$id] $chunk[$k].Ecosystem $chunk[$k].Name }
                                Add-DependencyFinding $findings $chunk[$k] $group.Names $group.Rank 'osv' $fixed
                            }
                        }
                    }
                }
                'github-global' {
                    # One request answers up to 100 packages (affects=name@version,...);
                    # GitHub filters by version on the server.
                    for ($offset = 0; $offset -lt $composerPackages.Count; $offset += 100) {
                        $chunk = @($composerPackages[$offset..([math]::Min($offset + 99, $composerPackages.Count - 1))])
                        $affects = (@($chunk | ForEach-Object { $_.Name + '@' + $_.Version }) -join ',')
                        $url = $GitHubBase + '/advisories?ecosystem=composer&per_page=100&affects=' + [uri]::EscapeDataString($affects)
                        while ($url -ne '') {
                            $answer = Invoke-DependencyGitHubGet $Http $url $githubHeaders
                            Add-GitHubAdvisoryFindings $findings $notes (ConvertFrom-DependencyJson $answer.Body) $chunk 'github-advisories' 'first_patched_version'
                            $url = Get-DependencyNextLink $answer
                        }
                    }
                }
                'github-repo' {
                    # Maintainer-published repository advisories reach the global
                    # database and OSV days later (phpseclib GHSA-w3p9-g4p2-h3rr,
                    # published 02.11.2026, still missing from both on 04.11.).
                    $url = $GitHubBase + '/repos/' + $unit.Repository + '/security-advisories?state=published&per_page=100'
                    $targets = @($composerPackages | Where-Object { $_.Repository -eq $unit.Repository })
                    while ($url -ne '') {
                        $answer = Invoke-DependencyGitHubGet $Http $url $githubHeaders
                        Add-GitHubAdvisoryFindings $findings $notes (ConvertFrom-DependencyJson $answer.Body) $targets 'github-repository-advisories' 'patched_versions'
                        $url = Get-DependencyNextLink $answer
                    }
                }
            }
        } catch {
            $state = 'INFRA'
            $message = $_.Exception.Message
            # The transport labels its own failures [osv.network]; anything
            # else is an unexpected answer shape, still never a pass.
            if ($message -notmatch '^\[osv\.') { $message = '[osv.error] ' + $message }
            [void]$infra.Add(('{0}: {1}' -f $unit.Name, $message))
        }
        if ($state -eq 'PASS') {
            for ($k = $before; $k -lt $findings.Count; $k++) {
                if (Test-SeverityBlocking $findings[$k].Rank) { $state = 'FAIL' }
            }
        }
        Write-Host ('[{0}/{1}] {2} {3}' -f $position, $total, $state, $unit.Name)
    }

    # Findings against the exceptions.
    $usedExceptions = @()
    $blocking = @()
    foreach ($f in @($findings | Sort-Object -Property @{ Expression = 'Rank'; Descending = $true }, Name, Id)) {
        $label = (ConvertFrom-SeverityRank $f.Rank).ToUpperInvariant()
        $fixText = 'keiner bekannt'
        if (@($f.Fixed).Count -gt 0) { $fixText = ($f.Fixed -join ', ') }
        $line = ('{0} {1} {2}@{3} [{4}] via {5}; Fix: {6}; Lock: {7}' -f $label, $f.Id, $f.Name, $f.Version, $f.Ecosystem,
            ($f.Via -join '+'), $fixText, ($f.Sources -join ', '))
        $match = Find-DependencyException $f.Names $exceptions $Today
        foreach ($e in $match.Expired) { [void]$notes.Add(('[osv.exception-expired] {0} seit {1:yyyy-MM-dd} abgelaufen; der Fund zaehlt wieder' -f $e.Id, $e.ExpiresAt)) }
        if (@($match.Active).Count -gt 0) {
            $usedExceptions += @($match.Active | ForEach-Object { $_.Id })
            [void]$notes.Add(('[osv.excepted] {0} (Ausnahme bis {1:yyyy-MM-dd})' -f $line, $match.Active[0].ExpiresAt))
            continue
        }
        if (Test-SeverityBlocking $f.Rank) { $blocking += ('[osv.finding] ' + $line) }
        else { [void]$notes.Add('[osv.note] ' + $line) }
    }
    foreach ($e in @($exceptions)) {
        if ($usedExceptions -notcontains $e.Id -and $e.ExpiresAt.Date -ge $Today.Date) {
            [void]$notes.Add(('[osv.exception-unused] {0} trifft keinen Fund mehr; Eintrag entfernen' -f $e.Id))
        }
    }

    $packageCount = 0
    foreach ($s in $sources) { $packageCount += @($s.Packages).Count }
    $exitCode = 0
    if ($infra.Count -gt 0) { $exitCode = 2 }
    if ($blocking.Count -gt 0 -or $failures.Count -gt 0) { $exitCode = 1 }
    return [pscustomobject]@{
        ExitCode = $exitCode
        Blocking = @($blocking)
        Failures = @($failures)
        Infra = @($infra)
        Notes = @($notes)
        Findings = @($findings)
        Sources = @($sources | ForEach-Object { [pscustomobject]@{ Name = $_.Name; Shipped = $_.Shipped; Packages = @($_.Packages).Count } })
        PackageCount = $packageCount
        UnitCount = $total
    }
}
