# Dot-sourced check module. Importing defines functions only.

function Get-QaAppEnvFile {
    # Der QA-Owner braucht MYSQL_ROOT_PASSWORD fuer den MySQL-Service und
    # Schemawerkzeuge. PHP-Testprozesse bekommen dagegen eine zur Laufzeit aus
    # derselben SSoT abgeleitete Datei ohne das Rootsecret.
    $target = Join-Path $artifactDir 'qa-app-runtime.env'
    if (Test-Path -LiteralPath $target) { return $target }

    $lines = @(Get-Content -LiteralPath $qaEnvFile | Where-Object {
        $_ -notmatch '^\s*MYSQL_ROOT_PASSWORD='
    })
    if ($lines.Count -eq 0 -or @($lines | Where-Object { $_ -match '^\s*DB_PASS=' }).Count -ne 1) {
        throw 'QA-App-Umgebung konnte nicht eindeutig aus qa.env abgeleitet werden.'
    }
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllLines($target, [string[]]$lines, $utf8NoBom)
    return $target
}

function Invoke-QaCompose {
    param([string[]]$Arguments)
    return Invoke-Tool 'docker' (@('compose', '-p', $qaProject,
        '--env-file', $qaEnvFile,
        '-f', (Join-Path $repoRoot 'docker-compose.yml'),
        '-f', $qaComposeOverride,
        '--project-directory', $repoRoot) + $Arguments)
}

# Einen Wert aus qa.env lesen (die Datei ist eingecheckt und enthaelt nur
# QA-Wegwerf-Werte, keine Geheimnisse).
function Get-QaEnvValue {
    param([string]$Name)
    foreach ($line in (Get-Content -Path $qaEnvFile -ErrorAction SilentlyContinue)) {
        if ($line -match ('^' + [regex]::Escape($Name) + '=(.*)$')) { return $Matches[1] }
    }
    return ''
}

# --- Pruefstand im Volume qa-repo (OV1, 03.11.2026) ---------------------------
# Docker Desktop reicht einen Bind-Mount von C:\ als 9p-Freigabe in die
# Docker-VM: alle Repo-Dateien zu lesen dauerte dort 40 bis 50 s statt 0,25 s
# aus einem Volume, die Unit-Suite 884 statt 119 s, und jedes archivierte
# errno=12 lag auf diesem Weg (KB2). Der QA-Stack liest seinen Stand deshalb aus
# dem Volume qa-repo (Docker/qa/docker-compose.qa.yml). Diese Funktionen
# bestimmen den Pruefstand, lassen ihn vom Einmal-Dienst qa-source kopieren und
# belegen per SHA-256 je Datei, dass das Volume genau dem Checkout entspricht.

# Pruefstand = was git als Stand dieses Checkouts fuehrt (getrackt samt
# Aenderungen, dazu neue, nicht ignorierte Dateien) plus der Composer-Baum
# Docker/WebAPI/vendor, ohne dessen ignoriertes vendor/bin weder phpunit noch
# die App laufen. Alles uebrige Ignorierte bleibt draussen (qa-artifacts,
# node_modules, .env, var/, logs/), ebenso geloeschte Dateien und Unterordner
# mit eigenem .git, die git als 'pfad/' meldet (andere Arbeitsbaeume). Die
# Ordner kommen mit, damit ihr Modus erhalten bleibt.
function Get-QaSourceSelection {
    param([string]$Root)
    $previousEncoding = $null
    try {
        $previousEncoding = [Console]::OutputEncoding
        [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
    } catch { $previousEncoding = $null }
    try {
        $listed = Invoke-Tool 'git' @('-C', $Root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard')
    } finally {
        if ($null -ne $previousEncoding) { try { [Console]::OutputEncoding = $previousEncoding } catch { Write-Debug $_ } }
    }
    if ($listed.StartFailed -or ($listed.ExitCode -ne 0)) {
        return @{ Ok = $false; Detail = 'git ls-files fuer den Pruefstand nicht ausfuehrbar'; Output = $listed.Output }
    }
    $files = New-Object 'System.Collections.Generic.SortedSet[string]' ([System.StringComparer]::Ordinal)
    foreach ($entry in ((@($listed.Output) -join "`n").Split([char]0))) {
        $relative = $entry.Trim("`r", "`n")
        if ((-not $relative) -or $relative.EndsWith('/')) { continue }
        if (Test-Path -LiteralPath (Join-Path $Root $relative) -PathType Leaf) { [void]$files.Add($relative) }
    }
    $vendor = Join-Path (Join-Path (Join-Path $Root 'Docker') 'WebAPI') 'vendor'
    if (Test-Path -LiteralPath $vendor -PathType Container) {
        $rootFull = (Get-Item -LiteralPath $Root -Force).FullName.TrimEnd('\', '/')
        foreach ($file in @(Get-ChildItem -LiteralPath $vendor -Recurse -File -Force)) {
            [void]$files.Add(($file.FullName.Substring($rootFull.Length + 1) -replace '\\', '/'))
        }
    }
    $directories = New-Object 'System.Collections.Generic.SortedSet[string]' ([System.StringComparer]::Ordinal)
    foreach ($relative in $files) {
        $parent = $relative
        $slash = $parent.LastIndexOf('/')
        while ($slash -gt 0) {
            $parent = $parent.Substring(0, $slash)
            if (-not $directories.Add($parent)) { break }
            $slash = $parent.LastIndexOf('/')
        }
    }
    return @{ Ok = $true; Files = @($files); Directories = @($directories); Output = @() }
}

# Dasselbe Zeilenformat wie sha256sum im Container: "<hex>  <pfad>".
function Get-QaSourceHashLines {
    param([string]$Root, [string[]]$Files)
    $sha = [System.Security.Cryptography.SHA256]::Create()
    $lines = New-Object 'System.Collections.Generic.List[string]'
    try {
        foreach ($relative in $Files) {
            $stream = [System.IO.File]::OpenRead((Join-Path $Root $relative))
            try { $digest = $sha.ComputeHash($stream) } finally { $stream.Dispose() }
            $lines.Add((([System.BitConverter]::ToString($digest) -replace '-', '').ToLowerInvariant() + '  ' + $relative))
        }
    } finally { $sha.Dispose() }
    return ,($lines.ToArray())
}

# Ein Digest ueber die sortierte Liste: dieselbe Zahl heisst dieselben Pfade mit
# denselben Inhalten.
function Get-QaSourceDigest {
    param([string[]]$HashLines)
    $sorted = New-Object 'System.Collections.Generic.List[string]'
    foreach ($line in $HashLines) { $sorted.Add($line) }
    $sorted.Sort([System.StringComparer]::Ordinal)
    $bytes = (New-Object System.Text.UTF8Encoding($false)).GetBytes((($sorted.ToArray() -join "`n") + "`n"))
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try { return (([System.BitConverter]::ToString($sha.ComputeHash($bytes)) -replace '-', '').ToLowerInvariant()) } finally { $sha.Dispose() }
}

# Vergleicht Checkout und Volume Datei fuer Datei. Zeilen, die kein
# sha256sum-Ergebnis sind (Meldungen von compose run), zaehlen nicht mit; eine
# fehlende Datei faellt trotzdem auf, weil die Checkout-Seite sie nennt.
function Compare-QaSourceHashes {
    param([string[]]$Expected, [string[]]$Actual)
    $want = New-Object 'System.Collections.Generic.Dictionary[string,string]' ([System.StringComparer]::Ordinal)
    $have = New-Object 'System.Collections.Generic.Dictionary[string,string]' ([System.StringComparer]::Ordinal)
    foreach ($pair in @(@{ Lines = $Expected; Map = $want }, @{ Lines = $Actual; Map = $have })) {
        foreach ($line in @($pair.Lines)) {
            if ("$line" -match '^([0-9a-f]{64})  (?:\./)?(.+?)\r?$') { $pair.Map[$Matches[2]] = $Matches[1] }
        }
    }
    $differences = New-Object 'System.Collections.Generic.List[string]'
    foreach ($path in $want.Keys) {
        if (-not $have.ContainsKey($path)) { $differences.Add('fehlt im Volume: ' + $path) }
        elseif ($have[$path] -ne $want[$path]) { $differences.Add('Inhalt weicht ab: ' + $path) }
    }
    foreach ($path in $have.Keys) {
        if (-not $want.ContainsKey($path)) { $differences.Add('nur im Volume: ' + $path) }
    }
    if ($want.Count -eq 0) { $differences.Add('Checkout-Liste leer') }
    return ,($differences.ToArray())
}

# Herkunft fuer die Metadaten: Commit, geaenderte Dateien (git status) und der
# Digest. Sie liegt im Laufverzeichnis und im Volume (meta/).
function Get-QaSourceOrigin {
    param([string]$Root, [string[]]$HashLines)
    $head = Invoke-Tool 'git' @('-C', $Root, 'rev-parse', 'HEAD')
    $status = Invoke-Tool 'git' @('-C', $Root, 'status', '--porcelain=v1', '--untracked-files=all')
    if ($head.StartFailed -or ($head.ExitCode -ne 0) -or $status.StartFailed -or ($status.ExitCode -ne 0)) {
        return @{ Ok = $false; Output = (@($head.Output) + @($status.Output)) }
    }
    $changed = @(@($status.Output) | Where-Object { "$_".Trim() } | ForEach-Object { "$_" })
    $origin = [ordered]@{
        commit         = ((@($head.Output) -join '').Trim())
        changed        = $changed
        files          = @($HashLines).Count
        manifestSha256 = (Get-QaSourceDigest $HashLines)
        createdAt      = (Get-Date).ToString('o')
    }
    return @{ Ok = $true; Origin = $origin; Json = (ConvertTo-Json -InputObject $origin -Depth 3); Output = @() }
}

function Format-QaSourceOrigin {
    param($Origin)
    $commit = [string]$Origin.commit
    if ($commit.Length -gt 12) { $commit = $commit.Substring(0, 12) }
    return ('Pruefstand {0} mit {1} geaenderten/neuen Dateien, {2} Dateien, Manifest sha256:{3}' -f
        $commit, @($Origin.changed).Count, $Origin.files, ([string]$Origin.manifestSha256).Substring(0, 16))
}

# Erster Schritt von qa-stack: Pruefstand bestimmen, kopieren, Volume gegen
# den Checkout pruefen. Jede Abweichung ist infrastructure_error, denn dann
# wuerde der Stack etwas anderes beweisen als diesen Checkout.
#
# Mit -Volume fuellt dieselbe Funktion statt qa-repo das Volume der Fast-Gates
# dieses Laufs (C1, Get-FastGateSource): gleiche Auswahl, gleiches Skript
# qa-source-sync.sh, gleicher Vergleich, nur das Ziel und der Ort der
# Nachweise (Unterordner fast-source/) sind andere. Das Merkmal
# qaSourceManifest bleibt dann unberuehrt, denn es heisst "qa-repo des
# QA-Stacks ist in diesem Lauf geprueft".
function Sync-QaSourceVolume {
    param([string]$Volume = '')
    $evidenceDir = $artifactDir
    $target = 'qa-repo'
    if ($Volume) {
        $evidenceDir = Join-Path $artifactDir 'fast-source'
        New-Item -ItemType Directory -Force -Path $evidenceDir | Out-Null
        $target = $Volume
    }
    $selection = Get-QaSourceSelection $repoRoot
    if (-not $selection.Ok) { return New-InfraResult $selection.Detail $selection.Output }
    $hostLines = Get-QaSourceHashLines $repoRoot $selection.Files
    $origin = Get-QaSourceOrigin $repoRoot $hostLines
    if (-not $origin.Ok) { return New-InfraResult 'Commit oder git status fuer den Pruefstand nicht lesbar' $origin.Output }

    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    $entries = @($selection.Directories) + @($selection.Files)
    [System.IO.File]::WriteAllText((Join-Path $evidenceDir 'qa-source.files'), (($entries -join [string][char]0) + [string][char]0), $utf8NoBom)
    [System.IO.File]::WriteAllText((Join-Path $evidenceDir 'qa-source.origin.json'), $origin.Json, $utf8NoBom)
    [System.IO.File]::WriteAllLines((Join-Path $evidenceDir 'qa-source.checkout.sha256'), [string[]]$hostLines, $utf8NoBom)

    if ($Volume) {
        $copy = Invoke-FastSourceCopy -Volume $Volume -EvidenceDir $evidenceDir
        if ($copy.StartFailed -or ($copy.ExitCode -ne 0)) { return New-InfraResult ('qa-source-sync.sh hat {0} nicht gefuellt' -f $Volume) $copy.Output }
    } else {
        # qa-source nutzt das Runtime-Image des php-Dienstes; vor dem ersten Lauf
        # existiert es noch nicht.
        $build = Invoke-QaCompose @('build', 'php')
        if ($build.ExitCode -ne 0) { return New-InfraResult 'PHP-Runtime-Image fuer qa-source nicht baubar' $build.Output }
        $copy = Invoke-QaCompose @('run', '--rm', '--no-deps', '-T',
            '-v', (($artifactDir -replace '\\', '/') + ':/qa-evidence'), 'qa-source')
        if ($copy.ExitCode -ne 0) { return New-InfraResult 'qa-source hat qa-repo nicht gefuellt' $copy.Output }
    }
    $volumeList = Join-Path $evidenceDir 'qa-source.volume.sha256'
    if (-not (Test-Path -LiteralPath $volumeList)) { return New-InfraResult 'qa-source hat keine Pruefsummen des Volumes geschrieben' $copy.Output }
    $differences = Compare-QaSourceHashes $hostLines @(Get-Content -LiteralPath $volumeList -Encoding UTF8)
    if ($differences.Count -gt 0) {
        return New-InfraResult ('{0} weicht vom Checkout ab ({1} Dateien)' -f $target, $differences.Count) @($differences | Select-Object -First 20)
    }
    if (-not $Volume) { $script:qaSourceManifest = $origin.Origin.manifestSha256 }
    return New-PassResult ((Format-QaSourceOrigin $origin.Origin) + ('; {0} Datei fuer Datei identisch' -f $target)) @($copy.Output)
}

# --- Pruefstand der Fast-Gates (C1, 08.11.2026) -------------------------------
# A26 auf die Fast-Gates: php-lint (Container-Weg), phpunit-unit, phpstan und
# die ansible-*-Gates lesen nicht mehr die 9p-Freigabe, sondern eine
# gepruefte Kopie (vorher: Unit-Suite 470 bis 528 s ueber 9p gegen 126 s fuer
# phpunit-full aus qa-repo). Die Kopie liegt in einem Volume NUR DIESES Laufs,
# nicht in qa-repo: qa-repo gehoert dem einen QA-Projekt, und Fast-Laeufe
# duerfen ohne Sperre nebeneinander laufen. Der Name kommt aus dem
# Laufverzeichnis (New-CheckArtifactDirectory, je Lauf eindeutig), das
# Volume entsteht beim ersten Gate, das es braucht, und check.ps1 entfernt es
# im finally (Remove-FastGateSource). Zwei gleichzeitige Laeufe, auch aus
# demselben Checkout, teilen so nie eine Kopie.

# Derselbe Einmal-Container wie der Compose-Dienst qa-source (ohne Netz,
# Wurzel nur lesend, alle Capabilities ab ausser den drei fuer tar,
# no-new-privileges, Checkout nur lesend), aber als docker run gegen das
# Volume dieses Laufs: der Compose-Dienst ist an qa-repo gebunden.
# VirtuSphere.QaSourceVolume.Tests.ps1 leitet die Optionen aus dem
# Compose-Block ab. Das Tooling-Image baut auf dem Runtime-Image auf und
# bringt dieselben Werkzeuge (sh, tar, find, sha256sum) mit; die Fast-Lane
# setzt es ohnehin voraus.
function Invoke-FastSourceCopy {
    param([string]$Volume, [string]$EvidenceDir)
    return Invoke-Tool 'docker' @('run', '--rm', '--network', 'none', '--read-only', '--tmpfs', '/tmp',
        '--cap-drop', 'ALL', '--cap-add', 'CHOWN', '--cap-add', 'DAC_OVERRIDE', '--cap-add', 'FOWNER',
        '--security-opt', 'no-new-privileges:true',
        '-v', ($repoRoot + ':/qa-source:ro'),
        '--mount', ('type=volume,source={0},target=/qa-repo,volume-nocopy=true' -f $Volume),
        '-v', (($EvidenceDir -replace '\\', '/') + ':/qa-evidence'),
        '--entrypoint', 'sh', $toolImages.php, '/qa-source/Docker/qa/qa-source-sync.sh')
}

# Liefert den Pruefstand fuer ein Fast-Gate. Beim ersten Aufruf des Laufs
# legt sie das Volume an und fuellt und prueft es; jeder spaetere Aufruf
# bekommt dasselbe Ergebnis (auch dasselbe infrastructure_error). Note traegt
# nur der erste Aufruf: das Gate, das die Kopie bezahlt hat, nennt sie.
#
# Ein Pruef-Root ausserhalb eines Git-Arbeitsbaums (die Fixtures von
# scripts/test-guards.ps1 unter %TEMP%) hat keine Auswahl; dort bleibt der
# Bind-Mount wie bisher. Fehlt git selbst, ist das infrastructure_error.
function Get-FastGateSource {
    if ((Test-Path variable:script:fastSource) -and $script:fastSource) {
        $known = $script:fastSource.Clone()
        $known.Note = ''
        return $known
    }
    $inside = Invoke-Tool 'git' @('-C', $repoRoot, 'rev-parse', '--is-inside-work-tree')
    if ($inside.StartFailed) {
        $script:fastSource = @{ Outcome = (New-InfraResult 'git fehlt; ohne git keine Auswahl fuer den Pruefstand der Fast-Gates' $inside.Output); Volume = ''; Note = '' }
    } elseif (($inside.ExitCode -ne 0) -or ((@($inside.Output) -join '').Trim() -ne 'true')) {
        $script:fastSource = @{ Outcome = $null; Volume = ''; Note = 'Pruef-Root ohne Git: Bind-Mount' }
    } else {
        $volume = (Split-Path -Leaf $artifactDir) + '-src'
        # Zuerst merken, dann anlegen: das finally raeumt auch ein halb
        # angelegtes Volume weg.
        $script:fastSourceVolume = $volume
        $watch = [System.Diagnostics.Stopwatch]::StartNew()
        $created = Invoke-Tool 'docker' @('volume', 'create', '--label', 'virtusphere.check=fast-source', $volume)
        if ($created.StartFailed -or ($created.ExitCode -ne 0)) {
            $script:fastSource = @{ Outcome = (New-InfraResult ('Volume {0} fuer den Pruefstand nicht anlegbar' -f $volume) $created.Output); Volume = ''; Note = '' }
        } else {
            $sync = Sync-QaSourceVolume -Volume $volume
            $watch.Stop()
            if ($sync.class -ne 'pass') {
                $script:fastSource = @{ Outcome = $sync; Volume = ''; Note = '' }
            } else {
                $seconds = ([math]::Round($watch.Elapsed.TotalSeconds, 1)).ToString([System.Globalization.CultureInfo]::InvariantCulture)
                $script:fastSource = @{ Outcome = $null; Volume = $volume; Note = ('Pruefstand kopiert in {0}s: {1}' -f $seconds, $sync.detail) }
            }
        }
    }
    return $script:fastSource.Clone()
}

# Mount-Argumente fuer docker run: die Kopie (tree/ des Laufvolumes) oder im
# Rueckfall der Bind-Mount des Pruef-Roots. Schreibgeschuetzt ist der
# Normalfall; nur phpunit-unit liest beschreibbar wie phpunit-full, weil
# Docker die Mountpunkte fuer tmpfs und .env darin anlegen muss.
function Get-FastSourceMountArgs {
    param($Source, [string]$Target, [switch]$Writable)
    if ($Source.Volume) {
        $spec = 'type=volume,source={0},target={1},volume-subpath=tree,volume-nocopy=true' -f $Source.Volume, $Target
        if (-not $Writable) { $spec += ',readonly' }
        return @('--mount', $spec)
    }
    $bind = $repoRoot + ':' + $Target
    if (-not $Writable) { $bind += ':ro' }
    return @('-v', $bind)
}

# Haengt die Kopie-Notiz an das Ergebnis des Gates, das sie ausgeloest hat.
function Add-FastSourceNote {
    param($Outcome, $Source)
    if ($Source -and $Source.Note -and $Outcome) {
        $Outcome.detail = ('{0} [{1}]' -f $Outcome.detail, $Source.Note)
    }
    return $Outcome
}

function Remove-FastGateSource {
    if (-not ((Test-Path variable:script:fastSourceVolume) -and $script:fastSourceVolume)) { return }
    $volume = $script:fastSourceVolume
    $removed = Invoke-Tool 'docker' @('volume', 'rm', '--force', $volume)
    if ($removed.StartFailed -or ($removed.ExitCode -ne 0)) {
        Write-Host ('Pruefstand-Volume {0} nicht entfernbar; von Hand: docker volume rm {0}' -f $volume) -ForegroundColor Yellow
    }
    $script:fastSourceVolume = $null
}

# --- PHPStan-Ergebniscache (C1, 08.11.2026) ------------------------------------
# PHPStan haelt seinen Ergebniscache in tmpDir (Vorgabe sys_get_temp_dir() .
# '/phpstan', phpstan.neon.dist setzt weder tmpDir noch resultCachePath).
# Ausserhalb der Release-Lane liegt dort ein Volume je Checkout, sonst wuerde
# jeder Lauf kalt beginnen. Ungueltig macht den Cache PHPStan selbst
# (2.2.5, ResultCacheManager::getMeta und restore): Config samt Baseline,
# composer.lock und installed.php, PHP- und PHPStan-Version, Erweiterungen,
# Level, Stub- und Bootstrap-Dateien vergleicht es als Metadaten, jede
# analysierte Datei per SHA-256, und Abhaengige geaenderter Dateien
# analysiert es neu. Die Kopie liegt in jedem Lauf unter /repo; derselbe Pfad
# heisst derselbe Schluessel fuer PHPStan.
#
# Zwei Laeufe aus demselben Checkout teilen das Volume. PHPStan schreibt die
# Cachedatei nicht atomar (fopen, dann Stueck fuer Stueck); flock auf
# /tmp/phpstan/.lock laesst deshalb immer nur einen PHPStan-Prozess je Volume
# arbeiten. Ein abgebrochener Schreiber hinterlaesst eine unvollstaendige
# Datei; PHPStan verwirft sie beim naechsten require und analysiert voll.
# Ein Cache aus einem anderen Stand desselben Checkouts ist kein Risiko: er
# wird gegen die Hashes des aktuellen Standes geprueft.
function Get-CheckoutCacheKey {
    param([string]$Root)
    $full = ([System.IO.Path]::GetFullPath($Root) -replace '\\', '/').TrimEnd('/')
    if ($env:OS -eq 'Windows_NT') { $full = $full.ToLowerInvariant() }
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $digest = $sha.ComputeHash((New-Object System.Text.UTF8Encoding($false)).GetBytes($full))
    } finally { $sha.Dispose() }
    return (([System.BitConverter]::ToString($digest) -replace '-', '').ToLowerInvariant()).Substring(0, 16)
}

function Get-PhpStanCacheSetup {
    param([string]$Lane)
    if ($Lane -eq 'Release') {
        # Release-Nachweise stehen nie auf einem Cache: ohne Volume ist tmpDir
        # das leere /tmp des Wegwerfcontainers.
        return @{ Outcome = $null; Mounts = @(); Command = @('composer'); Mode = 'kalt' }
    }
    $volume = 'virtusphere-phpstan-cache-' + (Get-CheckoutCacheKey $repoRoot)
    $created = Invoke-Tool 'docker' @('volume', 'create', '--label', 'virtusphere.check=phpstan-cache', $volume)
    if ($created.StartFailed -or ($created.ExitCode -ne 0)) {
        return @{ Outcome = (New-InfraResult ('PHPStan-Cache-Volume {0} nicht anlegbar' -f $volume) $created.Output) }
    }
    return @{ Outcome = $null
        Mounts = @('--mount', ('type=volume,source={0},target=/tmp/phpstan' -f $volume))
        Command = @('flock', '/tmp/phpstan/.lock', 'composer'); Mode = $volume }
}

# Was PHPStan mit -vv ueber den Cache sagt, als Teil des Gate-Details. Das
# Urteil haengt nie daran; es macht nur sichtbar, worauf ein Gruen steht.
function Get-PhpStanCacheState {
    param([string[]]$Output = @(), [string]$Mode)
    if ($Mode -eq 'kalt') { return 'Ergebniscache aus (Release-Lane, kalt)' }
    foreach ($line in @($Output)) {
        if ("$line" -match 'Result cache restored(?: in [0-9.]+ seconds)?\. (\d+) files? will be reanalysed') {
            return ('Ergebniscache genutzt, {0} Datei(en) neu analysiert' -f $Matches[1])
        }
        if ("$line" -match 'Result cache not used because (.+?)\.?\s*$') {
            return ('Ergebniscache nicht genutzt: {0}' -f $Matches[1])
        }
    }
    return 'Ergebniscache-Zustand nicht gemeldet'
}

# Vor einem Gate gegen einen Stack, den nicht dieser Lauf aufgebaut hat (etwa
# nach -KeepArtifacts oder im Baseline-Writer): Ist der Arbeitsbaum noch der
# Stand in qa-repo? Bis OV1 sah der Stack jede Aenderung sofort; jetzt fiele
# sie still unter den Tisch. $null heisst weiter, sonst infrastructure_error.
function Confirm-QaSourceCurrent {
    if ((Test-Path variable:script:qaSourceManifest) -and $script:qaSourceManifest) { return $null }
    $selection = Get-QaSourceSelection $repoRoot
    if (-not $selection.Ok) { return New-InfraResult $selection.Detail $selection.Output }
    $hostLines = Get-QaSourceHashLines $repoRoot $selection.Files
    $stored = Invoke-QaCompose @('run', '--rm', '--no-deps', '-T', '--entrypoint', 'cat', 'qa-source', '/qa-repo/meta/qa-source.sha256')
    if ($stored.StartFailed -or ($stored.ExitCode -ne 0)) {
        return New-InfraResult 'stehender QA-Stack ohne gepruefte Quellliste in qa-repo; zuerst -Gate qa-stack' $stored.Output
    }
    $differences = Compare-QaSourceHashes $hostLines @($stored.Output)
    if ($differences.Count -gt 0) {
        $detail = ('Arbeitsbaum weicht vom Stand des stehenden QA-Stacks ab ({0} Dateien seit qa-stack); zuerst -Gate qa-stack neu laufen lassen' -f $differences.Count)
        return New-InfraResult $detail @($differences | Select-Object -First 20)
    }
    $script:qaSourceManifest = Get-QaSourceDigest $hostLines
    return $null
}

# Snapshot and pause only workers carrying the exact throwaway QA Compose
# labels. A similarly named shared/dev container is refused before any stop.
function Get-QaWorkerSnapshot {
    $states = @()
    foreach ($service in @('deploy-worker', 'maintenance-worker')) {
        $container = $qaProject + '-' + $service + '-1'
        $format = '{{json .Config.Labels}}|{{.State.Running}}'
        $probe = Invoke-Tool 'docker' @('inspect', '--format', $format, $container)
        if ($probe.ExitCode -ne 0) {
            return @{ Ok = $false; Detail = ('QA-Worker nicht inspizierbar: ' + $container); Output = $probe.Output }
        }
        $line = ((@($probe.Output) -join '').Trim())
        $separator = $line.LastIndexOf('|')
        $labels = $null
        if ($separator -gt 0) {
            try { $labels = $line.Substring(0, $separator) | ConvertFrom-Json } catch { $labels = $null }
        }
        $runningText = if ($separator -gt 0) { $line.Substring($separator + 1) } else { '' }
        if (($null -eq $labels) -or ($labels.'com.docker.compose.project' -ne $qaProject) -or ($labels.'com.docker.compose.service' -ne $service)) {
            return @{ Ok = $false; Detail = ('Worker-Isolation verweigert: unerwartete Compose-Labels an ' + $container); Output = $probe.Output }
        }
        $states += @{ Service = $service; Container = $container; Running = ($runningText -eq 'true') }
    }
    return @{ Ok = $true; States = $states; Output = @() }
}

function Get-QaActiveJobCount {
    $database = Get-QaEnvValue 'DB_NAME'
    $rootPassword = Get-QaEnvValue 'MYSQL_ROOT_PASSWORD'
    if ((-not $database) -or (-not $rootPassword)) { return $null }
    $query = "SELECT COUNT(*) FROM deploy_jobs WHERE status IN ('queued','running','cancelling')"
    $probe = Invoke-Tool 'docker' @('exec', '-e', ('MYSQL_PWD=' + $rootPassword),
        $qaMysqlContainer, 'mysql', '--batch', '--skip-column-names', '-uroot', $database, '-e', $query)
    if ($probe.ExitCode -ne 0) { return $null }
    $value = 0
    if (-not [int]::TryParse(((@($probe.Output) -join '').Trim()), [ref]$value)) { return $null }
    return $value
}

# Der Dienststatus, den deploy.php rendert, kommt aus deploy_service_health_snapshot()
# und haengt an Heartbeat-Zeilen, die nur die Worker schreiben. Waehrend der
# Pause altern sie; nach der Produktgrenze kippt die Seite von "bereit" auf
# "nicht erreichbar", und jede spaetere Aufnahme zeigt einen Zustand, den kein
# Sollbild beschreibt (RH1, 03.11.2026: unter worker_v1 nach rund 150 s, das
# waren die 3639/427468/318982 px der dritten Versuche). Die Grenze wird hier
# nicht nachgebaut: Der Snapshot nimmt die Uhrzeit als Parameter, also fragt
# die Pruefung das Produkt fuer jede Sekunde des Fensters, ob es denselben
# Status rendern wuerde wie am Fensteranfang. Uhr ist die des PHP-Containers,
# das Fenster eine Dauer der Host-Stoppuhr; so stoert ein abweichender
# Docker-VM-Takt nicht. Nur Lesen, keine Sitzung, kein Bootstrap.
function Get-QaDeployServiceDrift {
    param([int]$Seconds)
    $window = [Math]::Max(0, $Seconds)
    $php = @(
        "require_once '/var/www/html/lib/constants.php';"
        "require_once '/var/www/html/lib/db.php';"
        "require_once '/var/www/html/lib/repo/deploy_runtime_identity.php';"
        "require_once '/var/www/html/lib/deploy_service_health.php';"
        '$db = db();'
        '$to = time();'
        ('$from = $to - {0};' -f $window)
        '$state = static function (int $t) use ($db): string {'
        '  $s = deploy_service_health_snapshot($db, $t);'
        "  return implode('/', [`$s['availability'], `$s['claim_state'], `$s['recovery_attention'], `$s['badge']]);"
        '};'
        '$before = $state($from); $after = $before; $flip = null;'
        'for ($t = $from + 1; $t <= $to; $t++) {'
        '  $now = $state($t);'
        '  if ($now !== $before) { $flip = $t - $from; $after = $now; break; }'
        '}'
        "echo 'RH1DRIFT' . json_encode(['before' => `$before, 'after' => `$after, 'flip' => `$flip]) . 'RH1DRIFT';"
    ) -join ' '
    $probe = Invoke-Tool 'docker' @('exec', $qaPhpContainer, 'php', '-r', $php)
    $text = (@($probe.Output) -join "`n")
    if (($probe.ExitCode -ne 0) -or ($text -notmatch 'RH1DRIFT(.*)RH1DRIFT')) {
        return @{ Ok = $false; Output = $probe.Output }
    }
    try { $parsed = $Matches[1] | ConvertFrom-Json } catch { return @{ Ok = $false; Output = $probe.Output } }
    return @{ Ok = $true; Before = [string]$parsed.before; After = [string]$parsed.after; FlipAfterSeconds = $parsed.flip; Seconds = $window; Output = $probe.Output }
}

# Ein pass oder fail des Bodys gilt nur, wenn die Pause den gerenderten
# Dienststatus nicht veraendert hat. Sonst ist das Urteil nicht zuzuordnen:
# rot statt still falsch, als infrastructure_error, mit dem Body-Urteil im Text.
function Confirm-QaPauseKeptServiceState {
    param($Outcome, [int]$Seconds)
    if (($null -eq $Outcome) -or ($Outcome.class -eq 'infrastructure_error')) { return $Outcome }
    $verdict = ('{0}: {1}' -f $Outcome.class, $Outcome.detail)
    $drift = Get-QaDeployServiceDrift -Seconds $Seconds
    if (-not $drift.Ok) {
        return New-InfraResult ('Deploy-Dienststatus im Pausenfenster nicht pruefbar; Body-Urteil nicht verwertbar ({0})' -f $verdict) (@($Outcome.output) + @($drift.Output))
    }
    if ($null -ne $drift.FlipAfterSeconds) {
        $detail = ('Deploy-Dienststatus kippte nach {0} s der {1} s langen Worker-Pause von {2} auf {3}; Aufnahmen danach zeigen nicht den Zustand der Sollbilder. Body-Urteil nicht verwertbar ({4})' -f $drift.FlipAfterSeconds, $drift.Seconds, $drift.Before, $drift.After, $verdict)
        return New-InfraResult $detail $Outcome.output
    }
    return $Outcome
}

function Invoke-WithPausedQaWorkers {
    param([scriptblock]$Body)
    # Der Visual-Gate und der Baseline-Writer nehmen Bilder vom Stand in qa-repo;
    # er muss der Arbeitsbaum sein, sonst entstehen Sollbilder von altem Code.
    $current = Confirm-QaSourceCurrent
    if ($null -ne $current) { return $current }
    $before = Get-QaWorkerSnapshot
    if (-not $before.Ok) { return New-InfraResult $before.Detail $before.Output }
    $activeJobs = Get-QaActiveJobCount
    if ($null -eq $activeJobs) { return New-InfraResult 'aktive QA-Jobs vor Worker-Pause nicht pruefbar' }
    if ($activeJobs -ne 0) { return New-InfraResult ('Worker-Pause verweigert: {0} aktive QA-Job(s)' -f $activeJobs) }

    $running = @($before.States | Where-Object { $_.Running } | ForEach-Object { $_.Service })
    $outcome = $null
    $restoreFailure = $null
    # Das Fenster beginnt vor dem stop: Bezug ist der Status, den die laufenden
    # Worker hinterlassen, nicht der nach einem langsamen stop.
    $pauseClock = [System.Diagnostics.Stopwatch]::StartNew()
    try {
        if ($running.Count -gt 0) {
            $stopped = Invoke-QaCompose (@('stop', '--timeout', '30') + $running)
            if ($stopped.ExitCode -ne 0) {
                $outcome = New-InfraResult 'isolierte QA-Worker nicht pausierbar' $stopped.Output
            }
        }
        if ($null -eq $outcome) {
            $paused = Get-QaWorkerSnapshot
            if ((-not $paused.Ok) -or (@($paused.States | Where-Object { $_.Running }).Count -ne 0)) {
                $outcome = New-InfraResult 'isolierte QA-Worker nach stop nicht vollstaendig pausiert' $paused.Output
            } else {
                try { $outcome = & $Body } catch { $outcome = New-InfraResult ('Visual-Harness warf eine Exception: ' + $_.Exception.Message) }
                # Vor dem Neustart pruefen: ein frisch gestarteter Worker schreibt
                # seine Zeile neu und wuerde das Kippen verdecken.
                $outcome = Confirm-QaPauseKeptServiceState -Outcome $outcome -Seconds ([int][Math]::Ceiling($pauseClock.Elapsed.TotalSeconds))
            }
        }
    } finally {
        if ($running.Count -gt 0) {
            $restarted = Invoke-QaCompose (@('up', '-d', '--wait') + $running)
            if ($restarted.ExitCode -ne 0) {
                $restoreFailure = New-InfraResult 'QA-Worker-Ausgangszustand nicht wiederherstellbar' $restarted.Output
            }
        }
        if ($null -eq $restoreFailure) {
            $after = Get-QaWorkerSnapshot
            if (-not $after.Ok) {
                $restoreFailure = New-InfraResult $after.Detail $after.Output
            } else {
                foreach ($state in $before.States) {
                    $restored = @($after.States | Where-Object { $_.Service -eq $state.Service })[0]
                    if (($null -eq $restored) -or ($restored.Running -ne $state.Running)) {
                        $restoreFailure = New-InfraResult ('QA-Worker-Ausgangszustand weicht ab: ' + $state.Service)
                        break
                    }
                }
            }
        }
    }
    if ($null -ne $restoreFailure) { return $restoreFailure }
    return $outcome
}

# --- Ergebnis-Helfer ----------------------------------------------------------
function New-GateOutcome {
    param([string]$Class, [string]$Detail = '', [string[]]$Output = @())
    return @{ class = $Class; detail = $Detail; output = $Output }
}
function New-PassResult  { param([string]$Detail = '', [string[]]$Output = @()) New-GateOutcome 'pass' $Detail $Output }
function New-FailResult  { param([string]$Detail = '', [string[]]$Output = @()) New-GateOutcome 'fail' $Detail $Output }
function New-InfraResult { param([string]$Detail = '', [string[]]$Output = @()) New-GateOutcome 'infrastructure_error' $Detail $Output }
function New-NaResult    { param([string]$Detail = '') New-GateOutcome 'not_applicable' $Detail }

# Native Kommandos so ausfuehren, dass stderr unter PS 5.1 nicht als
# NativeCommandError terminiert und der Exitcode erhalten bleibt.
function Invoke-Tool {
    param([string]$Exe, [string[]]$Arguments = @(), [switch]$Live)
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $lines = @()
    # StartFailed trennt "Werkzeug lief und meldete etwas" von "Werkzeug lief nie":
    # ein fehlendes Programm oder eine Kommandozeile ueber der Windows-Grenze
    # (32767 Zeichen, "Der Dateiname oder die Erweiterung ist zu lang") wirft hier,
    # und Exit 127 allein ist von einem echten Exit 127 des Werkzeugs nicht zu
    # unterscheiden (RH1, 03.11.2026: yaml-lint/shellcheck als "meldet Befunde").
    $startFailed = $false
    try {
        $lines = @(& $Exe @Arguments 2>&1 | ForEach-Object {
            $line = "$_"
            if ($Live) { Write-Host $line }
            $line
        })
        $code = $LASTEXITCODE
    } catch {
        $lines = @("$($_.Exception.Message)")
        $code = 127
        $startFailed = $true
    } finally {
        $ErrorActionPreference = $prev
    }
    return @{ ExitCode = $code; Output = $lines; StartFailed = $startFailed }
}

function Get-ToolStartFailureDetail {
    param($Result)
    return ('Werkzeug nicht gestartet, das Gate hat nichts geprueft: ' + ((@($Result.Output) -join ' ').Trim()))
}

function Test-Command { param([string]$Name) return [bool](Get-Command $Name -ErrorAction SilentlyContinue) }

function Test-DockerImage {
    param([string]$Image)
    $r = Invoke-Tool 'docker' @('image', 'inspect', '--format', 'ok', $Image)
    return ($r.ExitCode -eq 0)
}

function Test-Container {
    param([string]$Name)
    $r = Invoke-Tool 'docker' @('exec', $Name, 'true')
    return ($r.ExitCode -eq 0)
}

# Der Node-Resolver ist die einzige Browserauflosung fuer Runner und
# playwright.config.js. Er fragt die lockfile-gepinnte playwright-core-Revision
# ab; dieser PowerShell-Pfad scannt keinen Cache und traegt keinen Benutzerpfad.
function Resolve-PlaywrightBrowser {
    param([string]$Engine)
    if (-not (Test-Command 'node')) { return $null }
    $resolver = Join-Path (Join-Path (Join-Path $repoRoot 'tests') 'e2e') 'lib/browser-resolver.js'
    if (-not (Test-Path $resolver)) { return $null }
    $r = Invoke-Tool 'node' @($resolver, '--engine', $Engine)
    if ($r.ExitCode -ne 0) { return $null }
    try { return ((@($r.Output) -join "`n") | ConvertFrom-Json) } catch { return $null }
}

function Test-PlaywrightEngineCache {
    param([string]$Engine)
    return ($null -ne (Resolve-PlaywrightBrowser $Engine))
}

function Test-PlaywrightChromium {
    return (Test-PlaywrightEngineCache 'chromium')
}

# Git-Bash-sh finden (Windows) bzw. sh vom PATH (Linux/pwsh).
function Find-Sh {
    if (Test-Command 'sh') { return (Get-Command 'sh').Source }
    $git = Get-Command 'git' -ErrorAction SilentlyContinue
    if ($git) {
        $gitRoot = Split-Path (Split-Path $git.Source -Parent) -Parent
        foreach ($candidate in @('usr/bin/sh.exe', 'bin/sh.exe')) {
            $probe = Join-Path $gitRoot $candidate
            if (Test-Path $probe) { return $probe }
        }
    }
    return $null
}

# Ein Repo-Shellskript ausfuehren: Host-sh, sonst das explizite PHP-Tooling-Image.
function Invoke-CheckShell {
    param([string]$ScriptName, [string[]]$Arguments = @())
    $scriptPath = (Join-Path $scriptDir $ScriptName) -replace '\\', '/'
    if ($shExe) {
        # sh.exe direkt gestartet hat kein usr/bin im PATH (grep/sed/wc fehlen
        # sonst und Checks werden falsch gruen oder rot). Fuer den Kindprozess
        # das Verzeichnis von sh.exe voranstellen.
        $prevPath = $env:PATH
        $env:PATH = (Split-Path $shExe -Parent) + [System.IO.Path]::PathSeparator + $env:PATH
        try {
            return Invoke-Tool $shExe (@($scriptPath) + $Arguments)
        } finally {
            $env:PATH = $prevPath
        }
    }
    if ((Test-Command 'docker') -and (Test-DockerImage $toolImages.php)) {
        $dockerArgs = @('run', '--rm',
            '-v', ($scriptDir + ':/checker:ro'),
            '-v', ($repoRoot + ':/checkroot'),
            '-e', 'VIRTUSPHERE_CHECK_ROOT=/checkroot',
            $toolImages.php, 'sh', ('/checker/' + $ScriptName)) + $Arguments
        return Invoke-Tool 'docker' $dockerArgs
    }
    return $null
}

# Ein Repo-PHP-Skript ausfuehren: Host-php, sonst das PHP-Tooling-Image.
function Invoke-CheckPhp {
    param([string]$ScriptName, [string[]]$Arguments = @())
    if (Test-Command 'php') {
        return Invoke-Tool 'php' (@((Join-Path $scriptDir $ScriptName)) + $Arguments)
    }
    if ((Test-Command 'docker') -and (Test-DockerImage $toolImages.php)) {
        $dockerArgs = @('run', '--rm',
            '-v', ($scriptDir + ':/checker:ro'),
            '-v', ($repoRoot + ':/checkroot'),
            '-e', 'VIRTUSPHERE_CHECK_ROOT=/checkroot',
            $toolImages.php, 'php', ('/checker/' + $ScriptName)) + $Arguments
        return Invoke-Tool 'docker' $dockerArgs
    }
    return $null
}

# Composer im App-Kontext: primaer ein frisches docker run mit dem aktuellen
# Pruef-Root. Ein parallel laufender Dev-Container kann aus einem anderen
# Checkout gemountet sein und darf deshalb nie die bevorzugte Beweisquelle
# sein. Nur das Tooling-Target bringt Composer mit; vendor/ kommt aus dem Mount.
# Ein fehlendes Toolimage ist Infrastrukturfehler, kein Auftrag an den Dev-Stack.
#
# -Mounts ersetzt die Quell-Mounts (Arbeitsbaum, tmpfs, .env) durch die des
# Aufrufers, etwa den schreibgeschuetzten Pruefstand und das Cache-Volume von
# phpstan; -Command setzt einen Befehl vor composer (flock). Ohne beide bleibt
# alles wie bisher (composer-validate, composer-audit).
function Invoke-AppComposer {
    param([string[]]$Arguments, [string[]]$Mounts = $null, [string[]]$Command = @('composer'))
    if (Test-DockerImage $toolImages.php) {
        if ($null -eq $Mounts) {
            $qaAppEnvFile = Get-QaAppEnvFile
            $Mounts = @('-v', ($repoRoot + ':/repo'),
                '--tmpfs', '/repo/Docker/WebAPI/var:mode=1777', '--tmpfs', '/repo/Docker/WebAPI/logs:mode=1777',
                '-v', ($qaAppEnvFile + ':/repo/.env:ro'), '-v', ($qaAppEnvFile + ':/repo/Docker/WebAPI/.env:ro'))
        }
        return Invoke-Tool 'docker' (@('run', '--rm') + $Mounts + @('-w', '/repo/Docker/WebAPI',
            '-e', 'COMPOSER_CACHE_DIR=/tmp/composer-cache', '-e', 'COMPOSER_ALLOW_SUPERUSER=1',
            # Der Mount gehoert dem Host-User, composer laeuft als root: ohne
            # safe.directory verweigert git die Versionsermittlung und composer
            # verrauscht jeden Lauf mit "dubious ownership"-Fatals (CI-Lauf
            # 2026-07-16). Env-Config statt --global, das Image bleibt sauber.
            '-e', 'GIT_CONFIG_COUNT=1',
            '-e', 'GIT_CONFIG_KEY_0=safe.directory', '-e', 'GIT_CONFIG_VALUE_0=*',
            $toolImages.php) + $Command + $Arguments)
    }
    return $null
}

# Dateien unter dem Pruef-Root sammeln. Ausgabebaeume werden schon bei der
# Traversierung abgeschnitten, damit kopierte Quellen darin weder geparst noch
# als eigener Produktumfang gezaehlt werden. Ein Unterverzeichnis mit eigenem
# `.git` (Datei bei `git worktree`, Ordner bei einem eingebetteten Klon) ist ein
# anderer Arbeitsbaum: sein Inhalt ist fremder Stand, nicht dieser. Abgeleitet
# statt als Pfadliste, damit ein neuer Ablageort (etwa .claude/worktrees,
# .codex) nicht still wieder mitgezaehlt wird (RH1, 03.11.2026: 692 statt 35
# YAML-Dateien, docker-Kommandozeile ueber der Windows-Grenze).
function Test-NestedWorkingTree {
    param([System.IO.DirectoryInfo]$Directory)
    return (Test-Path -LiteralPath (Join-Path $Directory.FullName '.git'))
}

function Get-CheckFiles {
    param([string[]]$Patterns)
    $excludedDirectories = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    foreach ($name in @('vendor', 'node_modules', '.git', '.vs', 'var', 'bin', 'obj', 'qa-artifacts')) {
        [void]$excludedDirectories.Add($name)
    }
    $pending = New-Object 'System.Collections.Generic.Stack[System.IO.DirectoryInfo]'
    $pending.Push((Get-Item -LiteralPath $repoRoot -Force -ErrorAction Stop))
    $found = @()
    while ($pending.Count -gt 0) {
        $directory = $pending.Pop()
        foreach ($file in @(Get-ChildItem -LiteralPath $directory.FullName -File -Force -ErrorAction SilentlyContinue)) {
            foreach ($pattern in $Patterns) {
                if ($file.Name -like $pattern) {
                    $found += $file.FullName
                    break
                }
            }
        }
        foreach ($child in @(Get-ChildItem -LiteralPath $directory.FullName -Directory -Force -ErrorAction SilentlyContinue)) {
            if ($excludedDirectories.Contains($child.Name)) { continue }
            if (Test-NestedWorkingTree $child) { continue }
            $pending.Push($child)
        }
    }
    return @($found | Sort-Object -Unique)
}

function ConvertTo-RepoRelative {
    param([string[]]$Paths)
    return @($Paths | ForEach-Object { (($_ -replace '\\', '/') -replace [regex]::Escape($repoRoot + '/'), '') })
}

function Format-ToolResult {
    param($Result, [string]$OkDetail, [string]$FailDetail)
    if ($Result.StartFailed) { return New-InfraResult (Get-ToolStartFailureDetail $Result) $Result.Output }
    if ($Result.ExitCode -eq 0) { return New-PassResult $OkDetail $Result.Output }
    return New-FailResult ("{0} (exit {1})" -f $FailDetail, $Result.ExitCode) $Result.Output
}
