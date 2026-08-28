# Dot-sourced check module. Importing defines functions only.

function Get-RuntimeImages {
    $r = Invoke-Tool 'docker' @('compose', '--project-directory', $repoRoot,
        '--profile', '*', 'config', '--format', 'json')
    if ($r.ExitCode -ne 0) { return $null }
    $config = $null
    try { $config = (@($r.Output) -join "`n") | ConvertFrom-Json } catch { return $null }
    $refs = @()
    $project = [string]$config.name
    foreach ($p in $config.services.PSObject.Properties) {
        $imgProp = $p.Value.PSObject.Properties['image']
        if ($imgProp -and $imgProp.Value) {
            $refs += [string]$imgProp.Value
        } elseif ($p.Value.PSObject.Properties['build']) {
            $refs += ($project + '-' + $p.Name)
        }
    }
    $byId = @{}
    $missing = @()
    foreach ($ref in @($refs | Sort-Object -Unique)) {
        $probe = Invoke-Tool 'docker' @('image', 'inspect', '--format', '{{.Id}}', $ref)
        if ($probe.ExitCode -ne 0) { $missing += $ref; continue }
        $id = [string]$probe.Output[0]
        if (-not $byId.ContainsKey($id)) { $byId[$id] = $ref }
    }
    return @{ Images = @($byId.Values | Sort-Object); Missing = @($missing) }
}
function Invoke-Trivy {
    param([string[]]$Arguments)
    return Invoke-Tool 'docker' (@('run', '--rm',
        '-v', '/var/run/docker.sock:/var/run/docker.sock',
        '-v', 'virtusphere-trivy-cache:/root/.cache',
        '-v', ($repoRoot + ':/repo:ro'),
        '-v', (($artifactDir -replace '\\', '/') + ':/out'),
        $toolImages.trivy) + $Arguments)
}

# Dot-sourced check module. Importing defines functions only.

function Register-ReleaseCheckGates {
    # --- Release-Lane -------------------------------------------------------------

    # Volle Browser-Matrix (ADR-0028-Revision): dieselbe Suite wie e2e-portal auf
    # den Engines, die die Integration-Lane nicht faehrt. Firefox/WebKit sind
    # plattformneutral aus dem Playwright-Cache; Edge haengt am installierten
    # Windows-Edge und ist deshalb ein eigenes windows-only-Gate, damit es auf
    # einem Linux-Release-Runner sichtbar not_applicable wird statt still zu fehlen.
    Add-Gate -Name 'e2e-browser-matrix' -Lanes @('Release') -Kind 'native' -Network $true -Body {
        foreach ($engine in @('firefox', 'webkit')) {
            if (-not (Test-PlaywrightEngineCache $engine)) {
                return New-InfraResult ('kein ' + $engine + ' fuer Playwright: npx playwright install ' + $engine)
            }
        }
        Invoke-PlaywrightSuite @('firefox', 'webkit') 'Playwright-Suite gruen auf Firefox und WebKit (QA-Stack)' 'Browser-Matrix rot'
    }

    Add-Gate -Name 'e2e-msedge' -Lanes @('Release') -Kind 'windows-only' -Network $true -Body {
        if (-not $isWindowsHost) { return New-NaResult 'windows-only: der msedge-Channel braucht ein installiertes Windows-Edge' }
        $edgeFound = $false
        foreach ($root in @(${env:ProgramFiles(x86)}, $env:ProgramFiles)) {
            if (-not $root) { continue }
            if (Test-Path (Join-Path $root 'Microsoft\Edge\Application\msedge.exe')) {
                $edgeFound = $true
                break
            }
        }
        if (-not $edgeFound) { return New-InfraResult 'msedge.exe nicht gefunden (installiertes Edge noetig fuer den msedge-Channel)' }
        Invoke-PlaywrightSuite @('msedge') 'Playwright-Suite gruen auf Windows-Edge (QA-Stack)' 'Edge-Lauf rot'
    }

    Add-Gate -Name 'restore-drill' -Lanes @('Release') -Kind 'container' -Body {
        $r = Invoke-CheckShell 'restore_test.sh' @()
        if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
        if ($r.ExitCode -eq 2) { return New-InfraResult 'Restore-Drill ohne Umgebung (Stack/Backup fehlt)' $r.Output }
        Format-ToolResult $r 'Backup-/Restore-Drill gruen' 'Restore-Drill rot'
    }

    Add-Gate -Name 'secret-scan' -Lanes @('Release') -Kind 'container' -Network $true -Body {
        # Volle Git-Historie, nicht nur der Worktree: git rm entfernt keine
        # Vergangenheit, und die zwei bekannten Altfunde sind in .gitleaks.toml
        # als Entscheidungen dokumentiert. Erwartung: null Funde; jeder neue Fund
        # ist rot, bis er rotiert oder als Fixture begruendet ist.
        if (-not (Test-Path (Join-Path $repoRoot '.gitleaks.toml'))) { return New-InfraResult '.gitleaks.toml fehlt (Allowlist-Vertrag)' }
        if (-not (Test-DockerImage $toolImages.gitleaks)) {
            if ($NoNetwork) { return New-InfraResult ('Tool-Image {0} fehlt lokal (NoNetwork: kein Pull)' -f $toolImages.gitleaks) }
        }
        $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo'),
            $toolImages.gitleaks, 'detect', '--source', '/repo',
            '--config', '/repo/.gitleaks.toml', '--redact', '--exit-code', '1')
        if ($r.ExitCode -gt 1 -and (@($r.Output) -join ' ') -match 'pull|not found|no such') {
            return New-InfraResult ('Tool-Image {0} nicht verfuegbar' -f $toolImages.gitleaks) $r.Output
        }
        Format-ToolResult $r 'Secret-Scan ueber die volle Historie: null Funde' 'gitleaks meldet Secrets'
    }

    Add-Gate -Name 'sbom' -Lanes @('Release') -Kind 'container' -Network $true -Body {
        $found = Get-RuntimeImages
        if ($null -eq $found) { return New-InfraResult 'docker compose config nicht lesbar' }
        if ($found.Missing.Count -gt 0) {
            return New-InfraResult ('Runtime-Image(s) fehlen lokal: ' + ($found.Missing -join ', ') + ' (erst docker compose build bzw. pull)')
        }
        if ($found.Images.Count -eq 0) { return New-InfraResult 'keine Runtime-Images gefunden (Zero-Match)' }
        $written = @()
        foreach ($img in $found.Images) {
            $safe = ($img -replace '[^A-Za-z0-9._-]', '_')
            $r = Invoke-Trivy @('image', '--format', 'spdx-json',
                '--output', ('/out/sbom-' + $safe + '.spdx.json'), $img)
            if ($r.ExitCode -ne 0) { return New-FailResult ('SBOM-Erzeugung fuer ' + $img + ' fehlgeschlagen (exit ' + $r.ExitCode + ')') $r.Output }
            $written += ('sbom-' + $safe + '.spdx.json')
        }
        New-PassResult ('SPDX-SBOM fuer {0} Image(s) unter {1}' -f $found.Images.Count, $artifactDir) $written
    }

    Add-Gate -Name 'image-cve' -Lanes @('Release') -Kind 'container' -Network $true -Body {
        # Critical/High blockieren; Ausnahmen nur befristet ueber .trivyignore.yaml
        # (expired_at laesst eine abgelaufene Ausnahme automatisch wieder rot werden).
        if (-not (Test-Path (Join-Path $repoRoot '.trivyignore.yaml'))) {
            return New-InfraResult '.trivyignore.yaml fehlt (CVE-Ausnahme-Vertrag)'
        }
        $found = Get-RuntimeImages
        if ($null -eq $found) { return New-InfraResult 'docker compose config nicht lesbar' }
        if ($found.Missing.Count -gt 0) {
            return New-InfraResult ('Runtime-Image(s) fehlen lokal: ' + ($found.Missing -join ', ') + ' (erst docker compose build bzw. pull)')
        }
        if ($found.Images.Count -eq 0) { return New-InfraResult 'keine Runtime-Images gefunden (Zero-Match)' }
        $bad = @()
        $reportLines = @()
        foreach ($img in $found.Images) {
            $safe = ($img -replace '[^A-Za-z0-9._-]', '_')
            # Zwei Sichten (dokumentierte Politik, .trivyignore.yaml): der volle
            # Bericht inklusive unfixed geht als Artefakt raus; blockiert wird nur,
            # wofuer es eine Handlungsoption gibt (--ignore-unfixed), sonst waere
            # das Gate an Debian-will_not_fix-Eintraegen dauerrot und wertlos.
            $full = Invoke-Trivy @('image', '--quiet', '--scanners', 'vuln', '--severity', 'CRITICAL,HIGH',
                '--ignorefile', '/repo/.trivyignore.yaml',
                '--output', ('/out/cve-' + $safe + '.txt'), $img)
            if ($full.ExitCode -ne 0) {
                return New-InfraResult ('trivy-Bericht fuer ' + $img + ' nicht erzeugbar (exit ' + $full.ExitCode + ')') $full.Output
            }
            $r = Invoke-Trivy @('image', '--quiet', '--scanners', 'vuln', '--severity', 'CRITICAL,HIGH',
                '--ignore-unfixed', '--ignorefile', '/repo/.trivyignore.yaml', '--exit-code', '1', $img)
            if ($r.ExitCode -eq 1) {
                $bad += $img
                $reportLines += ('--- ' + $img + ' (voller Bericht: cve-' + $safe + '.txt) ---')
                $reportLines += @($r.Output | Select-Object -Last 25)
            } elseif ($r.ExitCode -ne 0) {
                return New-InfraResult ('trivy-Scan fuer ' + $img + ' nicht ausfuehrbar (exit ' + $r.ExitCode + ')') $r.Output
            }
        }
        if ($bad.Count -gt 0) {
            return New-FailResult ('fixbare Critical/High-CVEs offen in: ' + ($bad -join ', ') + ' (Berichte unter ' + $artifactDir + '; Ausnahmen nur befristet via .trivyignore.yaml)') $reportLines
        }
        New-PassResult ('{0} Image(s) ohne fixbare Critical/High-CVEs (volle Berichte unter {1})' -f $found.Images.Count, $artifactDir)
    }

    Add-Gate -Name 'offline-bundle' -Lanes @('Release') -Kind 'container' -Network $true -Body {
        # Baut das Offline-Release-Bundle (Images, vendor, Collections, SBOM,
        # CVE-Berichte, Digest-Manifest) und laesst es sich am Ende selbst offline
        # verifizieren (verify.sh). -KeepArtifacts behaelt das Bundle.
        if (-not $shExe) {
            return New-InfraResult 'kein Host-sh (Git Bash): das Bundle-Skript orchestriert docker und kann nicht in den PHP-Container ausweichen'
        }
        $bundleDir = ((Join-Path $artifactDir 'offline-bundle') -replace '\\', '/')
        $r = Invoke-CheckShell 'build-offline-bundle.sh' @('--release', $bundleDir)
        if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar' }
        if ($r.ExitCode -eq 2) { return New-InfraResult 'Bundle-Umgebung unvollstaendig' $r.Output }
        Format-ToolResult $r ('Offline-Bundle gebaut und offline verifiziert: ' + $bundleDir) 'Offline-Bundle fehlgeschlagen'
    }

    Add-Gate -Name 'npm-audit' -Lanes @('Release') -Kind 'native' -Network $true -Body {
        # QA-Tooling-Abhaengigkeiten (Playwright/axe in tests/e2e): Advisory-Bericht
        # als Artefakt, blockiert ab high - das Pendant zu composer-audit.
        if (-not (Test-Command 'npm')) { return New-InfraResult 'npm nicht gefunden' }
        $e2eDir = Join-Path (Join-Path $repoRoot 'tests') 'e2e'
        if (-not (Test-Path (Join-Path $e2eDir 'package-lock.json'))) {
            return New-InfraResult 'tests/e2e/package-lock.json fehlt (Zero-Match)'
        }
        $r = Invoke-Tool 'npm' @('--prefix', $e2eDir, 'audit', '--audit-level=high', '--json')
        $reportPath = Join-Path $artifactDir 'npm-audit.json'
        [System.IO.File]::WriteAllLines($reportPath, [string[]]@($r.Output))
        if ($r.ExitCode -eq 0) { return New-PassResult ('keine high/critical-Advisories; Bericht: ' + $reportPath) }
        New-FailResult ('npm audit meldet Advisories ab high (Bericht: ' + $reportPath + ')') @($r.Output | Select-Object -First 40)
    }
}
