#Requires -Version 5.1
<#
.SYNOPSIS
    Erzeugt die Ansichtsseite aller Ablaufdiagramme aus docs/operations.

.DESCRIPTION
    Die Mermaid-Bloecke der Betriebsdokumente sind die einzige Quelle der
    Diagramme; diese Seite wird daraus erzeugt, nie umgekehrt. Aufgenommen wird
    jedes Dokument unter docs/operations, das mindestens einen Mermaid-Block
    enthaelt. Die Liste wird abgeleitet, nicht gefuehrt: ein neues
    Ablaufdokument erscheint ohne Aenderung an diesem Skript.

    Mermaid-Bloecke landen statisch als <pre class="mermaid"> in der Seite,
    damit die Artifact-Umgebung sie beim Laden rendert. Fliesstext und Tabellen
    gehen als unveraenderter Markdown-Text mit; die Seite rendert ihn im
    Browser. Relative Links zeigen auf den Stand-Commit auf GitHub, Links
    zwischen den Ablaufdokumenten springen in den passenden Tab.

.PARAMETER OutFile
    Zieldatei. Standard: qa-artifacts\flow-viewer\index.html (gitignoriert).
#>
param(
    [string]$RepoRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$OutFile = ''
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 1.0

if ([string]::IsNullOrWhiteSpace($OutFile)) {
    $OutFile = Join-Path $RepoRoot 'qa-artifacts\flow-viewer\index.html'
}
$templatePath = Join-Path $PSScriptRoot 'flow-viewer\template.html'
$docsDir = Join-Path $RepoRoot 'docs\operations'
$utf8 = New-Object System.Text.UTF8Encoding($false)

function ConvertTo-GitHubSlug {
    param([string]$Text)
    $plain = $Text.Replace('`', '').Trim().ToLowerInvariant()
    return (($plain -replace '[^\p{L}\p{N}\s_-]', '') -replace '\s', '-')
}

function ConvertTo-SafeId {
    param([string]$Text)
    # Datei bleibt ASCII: Windows PowerShell 5.1 liest BOM-lose Skripte als ANSI.
    $slug = ConvertTo-GitHubSlug $Text
    foreach ($pair in @(@(0x00E4, 'ae'), @(0x00F6, 'oe'), @(0x00FC, 'ue'), @(0x00DF, 'ss'))) {
        $slug = $slug.Replace([string][char]$pair[0], $pair[1])
    }
    $value = $slug -replace '[^a-z0-9_-]', ''
    if ([string]::IsNullOrEmpty($value)) { $value = 'abschnitt' }
    return $value
}

function Invoke-Git {
    param([string[]]$Arguments)
    $output = & git -C $RepoRoot @Arguments 2>$null
    if ($LASTEXITCODE -ne 0) { throw ("git {0} fehlgeschlagen." -f ($Arguments -join ' ')) }
    return $output
}

# --- Quellen ableiten ------------------------------------------------------
$files = @(Get-ChildItem -LiteralPath $docsDir -Filter '*.md' -File | Sort-Object Name | Where-Object {
    [IO.File]::ReadAllText($_.FullName, $utf8) -match '(?m)^```mermaid[ \t]*\r?$'
})
if ($files.Count -eq 0) { throw 'Kein Dokument unter docs/operations enthaelt einen Mermaid-Block.' }

$commit = [string](Invoke-Git @('rev-parse', 'HEAD'))
$commitShort = $commit.Substring(0, 7)
$origin = [string](Invoke-Git @('remote', 'get-url', 'origin'))
$repoUrl = ($origin -replace '^git@github\.com:', 'https://github.com/') -replace '\.git$', ''
$blob = '{0}/blob/{1}/' -f $repoUrl, $commit
$relativeFiles = @($files | ForEach-Object { 'docs/operations/' + $_.Name })
$dirty = @(Invoke-Git (@('status', '--porcelain', '--') + $relativeFiles)).Count -gt 0

# --- Dokumente in Abschnitte zerlegen --------------------------------------
$mdStrings = New-Object System.Collections.Generic.List[string]
$docModels = New-Object System.Collections.Generic.List[object]
$panelHtml = New-Object System.Text.StringBuilder
$usedIds = New-Object 'System.Collections.Generic.HashSet[string]'
$diagramTotal = 0

foreach ($file in $files) {
    $key = ConvertTo-SafeId ([IO.Path]::GetFileNameWithoutExtension($file.Name))
    $relative = 'docs/operations/' + $file.Name
    $lines = [IO.File]::ReadAllLines($file.FullName, $utf8)
    $title = $null
    $sections = New-Object System.Collections.Generic.List[object]
    $current = [pscustomobject]@{ Label = ([string][char]0x00DC + 'berblick'); Group = $null; Child = $false; Heading = $null; Parts = (New-Object System.Collections.Generic.List[object]) }
    $prose = New-Object System.Collections.Generic.List[string]
    $mermaid = $null
    $inFence = $false
    $h2 = $null

    $flushProse = {
        if (($prose -join '').Trim().Length -gt 0) {
            $current.Parts.Add([pscustomobject]@{ Kind = 'md'; Text = ($prose -join "`n").Trim() })
        }
        $prose.Clear()
    }
    $closeSection = {
        & $flushProse
        if ($current.Parts.Count -gt 0) { $sections.Add($current) }
    }

    foreach ($line in $lines) {
        if ($inFence) {
            if ($line -match '^```[ \t]*$') {
                $inFence = $false
                if ($null -ne $mermaid) {
                    $current.Parts.Add([pscustomobject]@{ Kind = 'mermaid'; Text = ($mermaid -join "`n") })
                    $mermaid = $null
                } else { $prose.Add($line) }
            } elseif ($null -ne $mermaid) { $mermaid.Add($line) } else { $prose.Add($line) }
            continue
        }
        if ($line -match '^```mermaid[ \t]*$') {
            & $flushProse
            $inFence = $true
            $mermaid = New-Object System.Collections.Generic.List[string]
            continue
        }
        if ($line -match '^```') { $inFence = $true; $prose.Add($line); continue }
        if ($null -eq $title -and $line -match '^# (.+)$') { $title = $Matches[1].Trim(); continue }
        if ($line -match '^## (.+)$') {
            & $closeSection
            $h2 = $Matches[1].Trim()
            $current = [pscustomobject]@{ Label = $h2; Group = $h2; Child = $false; Heading = $h2; Parts = (New-Object System.Collections.Generic.List[object]) }
            continue
        }
        if ($line -match '^### (.+)$') {
            & $closeSection
            $h3 = $Matches[1].Trim()
            $current = [pscustomobject]@{ Label = $h3; Group = $h2; Child = ($null -ne $h2); Heading = $h3; Parts = (New-Object System.Collections.Generic.List[object]) }
            continue
        }
        $prose.Add($line)
    }
    if ($inFence) { throw ("Offener Codeblock in {0}." -f $relative) }
    & $closeSection
    if ($null -eq $title) { $title = $file.BaseName }

    # Eine ##-Gruppe ohne ###-Kinder ist ein einfacher Eintrag.
    foreach ($section in $sections) {
        if (-not $section.Child -and $null -ne $section.Group) {
            $group = $section.Group
            $hasChildren = @($sections | Where-Object { $_.Child -and $_.Group -eq $group }).Count -gt 0
            if (-not $hasChildren) { $section.Group = $null }
        }
    }

    $sectionModels = New-Object System.Collections.Generic.List[object]
    $docDiagrams = 0
    foreach ($section in $sections) {
        $id = '{0}--{1}' -f $key, (ConvertTo-SafeId $section.Label)
        $n = 2
        $base = $id
        while (-not $usedIds.Add($id)) { $id = '{0}-{1}' -f $base, $n; $n++ }
        $diagrams = @($section.Parts | Where-Object { $_.Kind -eq 'mermaid' }).Count
        $docDiagrams += $diagrams
        $sectionModels.Add([ordered]@{ id = $id; label = $section.Label; group = $section.Group; child = [bool]$section.Child; diagrams = $diagrams })

        $plainLabel = $section.Label.Replace('`', '')
        $eyebrow = if ($section.Child -and $section.Group) { '{0} {1} {2}' -f $relative, [char]0x00B7, $section.Group.Replace('`', '') } else { $relative }
        $anchor = if ($null -ne $section.Heading) { '#' + (ConvertTo-GitHubSlug $section.Heading) } else { '' }
        [void]$panelHtml.AppendLine(('      <section class="panel" id="p-{0}" role="tabpanel" aria-labelledby="tab-{0}" tabindex="-1">' -f $id))
        [void]$panelHtml.AppendLine('        <header class="panel-head">')
        [void]$panelHtml.AppendLine(('          <p class="eyebrow">{0}</p>' -f [Net.WebUtility]::HtmlEncode($eyebrow)))
        [void]$panelHtml.AppendLine(('          <h2>{0}</h2>' -f [Net.WebUtility]::HtmlEncode($plainLabel)))
        [void]$panelHtml.AppendLine(('          <a class="src" href="{0}" target="_blank" rel="noopener">Diesen Abschnitt in der Doku auf GitHub &ouml;ffnen</a>' -f [Net.WebUtility]::HtmlEncode($blob + $relative + $anchor)))
        [void]$panelHtml.AppendLine('        </header>')
        foreach ($part in $section.Parts) {
            if ($part.Kind -eq 'md') {
                [void]$panelHtml.AppendLine(('        <div class="md" data-md="{0}" data-file="{1}"></div>' -f $mdStrings.Count, [Net.WebUtility]::HtmlEncode($relative)))
                $mdStrings.Add($part.Text)
            } else {
                [void]$panelHtml.AppendLine(('        <div class="diagram"><pre class="mermaid">{0}</pre></div>' -f [Net.WebUtility]::HtmlEncode($part.Text)))
            }
        }
        [void]$panelHtml.AppendLine('      </section>')
    }
    $diagramTotal += $docDiagrams
    $docModels.Add([ordered]@{ key = $key; title = $title; file = $relative; diagrams = $docDiagrams; sections = $sectionModels.ToArray() })
}

# --- Seite schreiben --------------------------------------------------------
$data = [ordered]@{ blob = $blob; docs = $docModels.ToArray(); md = $mdStrings.ToArray() }
# ConvertTo-Json maskiert < > & als < usw.; der Block kann damit kein
# </script> enthalten.
$json = ConvertTo-Json -InputObject $data -Depth 8 -Compress
$template = [IO.File]::ReadAllText($templatePath, $utf8)
$dirtyNote = if ($dirty) { ' <em>mit lokalen &Auml;nderungen</em>' } else { '' }
$page = $template.Replace('{{PANELS}}', $panelHtml.ToString().TrimEnd())
$page = $page.Replace('{{DATA_JSON}}', $json)
$page = $page.Replace('{{COMMIT}}', $commitShort)
$page = $page.Replace('{{DIRTY}}', $dirtyNote)
$page = $page.Replace('{{GENERATED}}', (Get-Date).ToString('yyyy-MM-dd HH:mm'))
$page = $page.Replace('{{DOC_COUNT}}', [string]$docModels.Count)
$page = $page.Replace('{{DIAGRAM_COUNT}}', [string]$diagramTotal)
if ($page -match '\{\{[A-Z_]+\}\}') { throw ('Platzhalter nicht ersetzt: {0}' -f $Matches[0]) }

$outDir = Split-Path -Parent $OutFile
if (-not (Test-Path -LiteralPath $outDir)) { New-Item -ItemType Directory -Path $outDir -Force | Out-Null }
[IO.File]::WriteAllText($OutFile, $page, $utf8)
Write-Output ('{0} Dokumente, {1} Abschnitte, {2} Diagramme, Stand {3}{4} -> {5}' -f $docModels.Count,
    (@($docModels | ForEach-Object { $_.sections.Count }) | Measure-Object -Sum).Sum, $diagramTotal, $commitShort,
    $(if ($dirty) { ' (lokal geaendert)' } else { '' }), $OutFile)
