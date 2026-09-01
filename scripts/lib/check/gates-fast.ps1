# Dot-sourced check module. Importing defines functions only.

function Register-FastCheckGates {
    Add-Gate -Name 'compose-config' -Lanes $allLanes -Kind 'container' -Body {
        $r = Invoke-Tool 'docker' @('compose', '--project-directory', $repoRoot, 'config', '--quiet')
        Format-ToolResult $r 'docker-compose.yml valide' 'docker compose config meldet Fehler'
    }

    Add-Gate -Name 'compose-hardening' -Lanes $allLanes -Kind 'container' -Body {
        # Haertungs-Contract (AP8): read_only/cap_drop/cap_add/no-new-privileges/
        # Limits/Healthchecks/service_healthy/tools-Profil/Digest-Pins, semantisch
        # ueber docker compose config. Das Skript liest VIRTUSPHERE_CHECK_ROOT
        # selbst (vererbt sich an den Kindprozess, so beweisen es die Guards).
        $hostExe = 'powershell'
        if ($PSVersionTable.PSEdition -eq 'Core') { $hostExe = 'pwsh' }
        $r = Invoke-Tool $hostExe @('-NoProfile', '-ExecutionPolicy', 'Bypass',
            '-File', (Join-Path $scriptDir 'check-compose-hardening.ps1'))
        if ($r.ExitCode -eq 0) { return New-PassResult 'Container-Haertung, Runtime-Tags und Basis-Digests gepinnt' $r.Output }
        if ($r.ExitCode -eq 2) { return New-InfraResult 'check-compose-hardening: Pruefumgebung unvollstaendig' $r.Output }
        New-FailResult ('Haertungs-Contract verletzt (exit ' + $r.ExitCode + ')') $r.Output
    }

    Add-Gate -Name 'php-lint' -Lanes $allLanes -Kind 'container' -Body {
        $r = $null
        if ((Test-Command 'php') -and $shExe) {
            $r = Invoke-CheckShell 'php-lint-all.sh' @()
        } elseif ((Test-Command 'docker') -and (Test-DockerImage $toolImages.php)) {
            $r = Invoke-Tool 'docker' @('run', '--rm',
                '-v', ($scriptDir + ':/checker:ro'),
                '-v', ($repoRoot + ':/checkroot:ro'),
                '-e', 'VIRTUSPHERE_CHECK_ROOT=/checkroot',
                $toolImages.php, 'sh', '/checker/php-lint-all.sh')
        } else {
            return New-InfraResult 'weder Host-PHP+sh noch Projekt-Image verfuegbar'
        }
        if ($r.ExitCode -eq 9) { return New-InfraResult 'php-lint fand keine Dateien oder kein php (Zero-Match)' $r.Output }
        Format-ToolResult $r (@($r.Output) -join '; ') 'php -l meldet Syntaxfehler'
    }

    Add-Gate -Name 'phpunit-unit' -Lanes $allLanes -Kind 'container' -Body {
        if (-not (Test-DockerImage $toolImages.php)) { return New-InfraResult ('Projekt-Image {0} fehlt' -f $toolImages.php) }
        # docker run mit vollem Repo-Mount statt exec in den App-Container: die
        # Repo-Level-Contract-Tests (nginx/php-Config) sehen sonst ihre Dateien
        # nicht und wuerden skippen; die Fast-Lane laeuft ohne Skips.
        $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo'), '-w', '/repo/Docker/WebAPI',
            $toolImages.php, 'php', 'vendor/bin/phpunit', '--testsuite', 'unit', '--fail-on-skipped')
        Format-ToolResult $r 'Unit/Static-Suite gruen (ohne Skips)' 'PHPUnit Unit/Static rot oder geskippt'
    }

    Add-Gate -Name 'phpstan' -Lanes $allLanes -Kind 'container' -Body {
        $r = Invoke-AppComposer @('run', 'stan')
        if ($null -eq $r) { return New-InfraResult ('weder Container {0} noch Image {1} verfuegbar' -f $phpContainer, $toolImages.php) }
        Format-ToolResult $r 'PHPStan gruen (Baseline-Ratchet)' 'PHPStan meldet neue Befunde'
    }

    Add-Gate -Name 'composer-validate' -Lanes $allLanes -Kind 'container' -Body {
        $v = Invoke-AppComposer @('validate', '--strict', '--no-check-publish')
        if ($null -eq $v) { return New-InfraResult ('weder Container {0} noch Image {1} verfuegbar' -f $phpContainer, $toolImages.php) }
        if ($v.ExitCode -ne 0) { return New-FailResult 'composer validate --strict rot' $v.Output }
        $p = Invoke-AppComposer @('check-platform-reqs')
        Format-ToolResult $p 'composer.json valide, Plattform-Anforderungen erfuellt' 'composer check-platform-reqs rot'
    }

    Add-Gate -Name 'composer-audit' -Lanes $allLanes -Kind 'container' -Network $true -Body {
        $r = Invoke-AppComposer @('audit', '--locked')
        if ($null -eq $r) { return New-InfraResult ('weder Container {0} noch Image {1} verfuegbar' -f $phpContainer, $toolImages.php) }
        Format-ToolResult $r 'keine bekannten Advisories' 'composer audit meldet Advisories'
    }

    Add-Gate -Name 'lang-parity' -Lanes $allLanes -Kind 'native' -Body {
        $r = Invoke-CheckPhp 'lang-audit.php' @('--ci')
        if ($null -eq $r) { return New-InfraResult 'weder Host-PHP noch Projekt-Image verfuegbar' }
        Format-ToolResult $r 'DE/EN-Paritaet und Placeholder synchron' 'Lang-Audit meldet Paritaets-/Placeholder-Luecken'
    }

    Add-Gate -Name 'enum-sync' -Lanes $allLanes -Kind 'native' -Body {
        $r = Invoke-CheckShell 'check-enum-sync.sh' @('--ci')
        if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
        Format-ToolResult $r 'ENUM-Spiegel synchron' 'ENUM-SSoT-Drift'
    }

    Add-Gate -Name 'php-version-sync' -Lanes $allLanes -Kind 'native' -Body {
        $r = Invoke-CheckShell 'check-php-version-sync.sh' @('--ci')
        if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
        Format-ToolResult $r 'PHP-Version ueberall synchron' 'PHP-Version-Drift'
    }

    Add-Gate -Name 'bounds-sync' -Lanes $allLanes -Kind 'native' -Body {
        $r = Invoke-CheckPhp 'check-bounds-sync.php' @('--ci')
        if ($null -eq $r) { return New-InfraResult 'weder Host-PHP noch Projekt-Image verfuegbar' }
        Format-ToolResult $r 'keine ausgeschriebenen Grenzwerte' 'Grenzwert-Drift in Portal-Texten'
    }

    Add-Gate -Name 'file-size' -Lanes $allLanes -Kind 'native' -Body {
        $r = Invoke-CheckPhp 'check-file-size.php' @('--ci')
        if ($null -eq $r) { return New-InfraResult 'weder Host-PHP noch Projekt-Image verfuegbar' }
        Format-ToolResult $r 'ADR-0006-Budget eingehalten' 'PHP-Datei ueber Budget oder Ausnahme veraltet'
    }

    # Etappe 10C: persistierte Ereignisse haben genau einen Owner. Der Guard findet
    # freie Producer, unbekannte Codes, Registry-Umgehungen, verbotene Kontextfelder,
    # wiedereingefuehrte Freitextsinks und Token-faehige Logsignaturen.
    Add-Gate -Name 'audit-contract' -Lanes $allLanes -Kind 'native' -Body {
        $r = Invoke-CheckPhp 'check-audit-contract.php' @('--ci')
        if ($null -eq $r) { return New-InfraResult 'weder Host-PHP noch Projekt-Image verfuegbar' }
        Format-ToolResult $r 'Audit-SSoT eingehalten' 'Auditproducer ausserhalb der Registry'
    }

    Add-Gate -Name 'doc-hygiene' -Lanes $allLanes -Kind 'native' -Body {
        $r = Invoke-CheckShell 'check-doc-hygiene.sh' @('--ci')
        if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
        Format-ToolResult $r 'Agenten-Dokus sauber' 'Doku-Hygiene verletzt'
    }

    Add-Gate -Name 'doc-semantics' -Lanes $allLanes -Kind 'native' -Body {
        $r = Invoke-CheckShell 'check-doc-semantics.sh' @('--ci')
        if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
        Format-ToolResult $r 'Betriebsdoku ohne veraltbare Staende' 'Doku behauptet einen veralteten Stand'
    }

    Add-Gate -Name 'csp-patterns' -Lanes $allLanes -Kind 'native' -Body {
        $r = Invoke-CheckShell 'lint-csp-patterns.sh' @('--worktree')
        if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
        if ((@($r.Output) -join ' ') -match '\[csp\.no-git\]') { return New-InfraResult 'CSP-Scan ohne Git-Repo/git im PATH' $r.Output }
        if ($r.ExitCode -eq 0) {
            $warnCount = @($r.Output | Where-Object { $_ -match '^WARN:' }).Count
            return New-PassResult ("keine harten CSP-Befunde ({0} Warnung(en))" -f $warnCount) $r.Output
        }
        return New-FailResult 'harte CSP-Pattern-Befunde im Worktree' $r.Output
    }

    Add-Gate -Name 'js-syntax' -Lanes $allLanes -Kind 'native' -Body {
        if (-not (Test-Command 'node')) { return New-InfraResult 'node nicht gefunden' }
        $assets = Join-Path (Join-Path (Join-Path (Join-Path $repoRoot 'Docker') 'WebAPI') 'portal') 'assets'
        $files = @(Get-ChildItem -Path $assets -Filter '*.js' -File -ErrorAction SilentlyContinue | ForEach-Object { $_.FullName })
        if ($files.Count -eq 0) { return New-InfraResult 'keine Portal-JS-Dateien gefunden (Zero-Match)' }
        $bad = @()
        foreach ($f in $files) {
            $r = Invoke-Tool 'node' @('--check', $f)
            if ($r.ExitCode -ne 0) { $bad += $r.Output }
        }
        if ($bad.Count -gt 0) { return New-FailResult 'node --check meldet Syntaxfehler' $bad }
        New-PassResult ("{0} Portal-Skript(e) syntaktisch sauber" -f $files.Count)
    }

    Add-Gate -Name 'powershell-syntax' -Lanes $allLanes -Kind 'native' -Body {
        $files = Get-CheckFiles @('*.ps1', '*.psm1', '*.psd1')
        if ($files.Count -eq 0) { return New-InfraResult 'keine PowerShell-Dateien gefunden (Zero-Match)' }
        $bad = @()
        foreach ($f in $files) {
            $tokens = $null
            $parseErrors = $null
            [void][System.Management.Automation.Language.Parser]::ParseFile($f, [ref]$tokens, [ref]$parseErrors)
            if ($parseErrors -and $parseErrors.Count -gt 0) {
                foreach ($e in $parseErrors) { $bad += ('{0}:{1} {2}' -f $f, $e.Extent.StartLineNumber, $e.Message) }
            }
        }
        if ($bad.Count -gt 0) { return New-FailResult 'PowerShell-Parserfehler' $bad }
        New-PassResult ("{0} PowerShell-Datei(en) geparst" -f $files.Count)
    }

    Add-Gate -Name 'powershell-tests' -Lanes $allLanes -Kind 'native' -Body {
        $hostExe = 'powershell'
        if ($PSVersionTable.PSEdition -eq 'Core') { $hostExe = 'pwsh' }
        $r = Invoke-Tool $hostExe @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $scriptDir 'run-pester.ps1'))
        # Exitcode-Vertrag von run-pester.ps1 (3 = Modul fehlt), niemals ein
        # Textmuster: der CI-Lauf 2026-07-16 klassifizierte 44 rote Tests als
        # "Module fehlen", weil ein Testname das Wort "fehlt" enthielt.
        if ($r.ExitCode -eq 3) {
            return New-InfraResult 'Pester/PSScriptAnalyzer-Module fehlen (PSGallery)' $r.Output
        }
        Format-ToolResult $r 'PSScriptAnalyzer und Pester gruen' 'PowerShell-Pruefungen rot'
    }

    Add-Gate -Name 'yaml-lint' -Lanes $allLanes -Kind 'container' -Body {
        $files = Get-CheckFiles @('*.yml', '*.yaml')
        if ($files.Count -eq 0) { return New-InfraResult 'keine YAML-Dateien gefunden (Zero-Match)' }
        if (-not (Test-DockerImage $toolImages.yamllint)) {
            if ($NoNetwork) { return New-InfraResult ('Tool-Image {0} fehlt lokal (NoNetwork: kein Pull)' -f $toolImages.yamllint) }
        }
        $rel = ConvertTo-RepoRelative $files
        # new-lines deaktiviert: die Zeilenenden haengen am Checkout (CRLF unter
        # Windows via .gitattributes/autocrlf), nicht am Dateiinhalt.
        $dockerArgs = @('run', '--rm', '-v', ($repoRoot + ':/data:ro'), '-w', '/data', $toolImages.yamllint,
            '-s', '-d', '{extends: relaxed, rules: {line-length: disable, new-lines: disable}}') + $rel
        $r = Invoke-Tool 'docker' $dockerArgs
        if ($r.ExitCode -gt 1 -and (@($r.Output) -join ' ') -match 'pull|not found|no such') {
            return New-InfraResult ('Tool-Image {0} nicht verfuegbar' -f $toolImages.yamllint) $r.Output
        }
        Format-ToolResult $r ("{0} YAML-Datei(en) sauber" -f $rel.Count) 'yamllint meldet Befunde'
    }

    Add-Gate -Name 'actionlint' -Lanes $allLanes -Kind 'container' -Body {
        $wfDir = Join-Path (Join-Path $repoRoot '.github') 'workflows'
        if (-not (Test-Path $wfDir)) { return New-InfraResult 'kein .github/workflows unter dem Pruef-Root (Zero-Match)' }
        if (-not (Test-DockerImage $toolImages.actionlint)) {
            if ($NoNetwork) { return New-InfraResult ('Tool-Image {0} fehlt lokal (NoNetwork: kein Pull)' -f $toolImages.actionlint) }
        }
        $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo', $toolImages.actionlint)
        if ($r.ExitCode -gt 1 -and (@($r.Output) -join ' ') -match 'pull|not found|no such') {
            return New-InfraResult ('Tool-Image {0} nicht verfuegbar' -f $toolImages.actionlint) $r.Output
        }
        Format-ToolResult $r 'GitHub-Workflows sauber' 'actionlint meldet Befunde'
    }

    Add-Gate -Name 'ansible-syntax' -Lanes $allLanes -Kind 'container' -Body {
        $playbooks = @(Get-ChildItem -Path (Join-Path $repoRoot 'Ansible') -Filter '*_playbook.yml' -File -ErrorAction SilentlyContinue | ForEach-Object { $_.Name })
        if ($playbooks.Count -eq 0) { return New-InfraResult 'keine Playbooks unter Ansible/ gefunden (Zero-Match)' }
        if (-not (Test-DockerImage $toolImages.ansible)) {
            return New-InfraResult ('QA-Ansible-Image {0} fehlt (docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .)' -f $toolImages.ansible)
        }
        $bad = @()
        foreach ($pb in $playbooks) {
            $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo/Ansible', $toolImages.ansible, 'ansible-playbook', '--syntax-check', $pb)
            if ($r.ExitCode -ne 0) { $bad += $r.Output }
        }
        if ($bad.Count -gt 0) { return New-FailResult 'Playbook-Syntaxcheck rot' $bad }
        New-PassResult ("{0} Playbook(s) syntaktisch sauber" -f $playbooks.Count)
    }

    Add-Gate -Name 'ansible-lint' -Lanes $allLanes -Kind 'container' -Body {
        if (-not (Test-Path (Join-Path $repoRoot 'Ansible'))) { return New-InfraResult 'kein Ansible/ unter dem Pruef-Root (Zero-Match)' }
        if (-not (Test-DockerImage $toolImages.ansible)) {
            return New-InfraResult ('QA-Ansible-Image {0} fehlt (docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .)' -f $toolImages.ansible)
        }
        $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo', $toolImages.ansible, 'ansible-lint', '--strict', 'Ansible/')
        Format-ToolResult $r 'ansible-lint --strict sauber' 'ansible-lint meldet Befunde'
    }

    Add-Gate -Name 'ansible-module-contract' -Lanes $allLanes -Kind 'container' -Body {
        # ansible-syntax und ansible-lint lesen die Playbooks; keiner der beiden ruft
        # ein Modul auf. Genau dort lag der Befund: jedes benutzte community.vmware-
        # Modul importiert vmware_rest_client, der ohne die Python-Bibliothek
        # `requests` abbricht - und die stand weder in der QA-Toolchain noch in den
        # dokumentierten Host-Voraussetzungen. Auf einem nach Doku aufgesetzten
        # Ansible-Host war damit der komplette ESXi-Teil funktionslos, waehrend
        # ansible-lint --strict gruen meldete. Sechs der sieben Inventar-Abfragen
        # laufen unter ignore_errors und haetten "0 Datastores" gemeldet.
        #
        # Dieses Gate ruft jedes Modul mit gueltigen Argumenten gegen 127.0.0.1:443:
        # der Verbindungsfehler ist der Gutfall, eine fehlende Bibliothek, ein
        # Argumentfehler oder ein verschwundenes Modul sind Befunde. Dazu haelt es die
        # Deprecations der INSTALLIERTEN Collection gegen die eingecheckte Liste,
        # damit eine Frist im Repo steht, ohne dass Prosa sie spiegeln muss.
        $contract = Join-Path $repoRoot 'Docker/qa-ansible/module-contract.sh'
        if (-not (Test-Path $contract)) { return New-InfraResult 'Docker/qa-ansible/module-contract.sh fehlt unter dem Pruef-Root (Zero-Match)' }
        if (-not (Test-DockerImage $toolImages.ansible)) {
            return New-InfraResult ('QA-Ansible-Image {0} fehlt (docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .)' -f $toolImages.ansible)
        }
        $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo',
            $toolImages.ansible, 'sh', '/repo/Docker/qa-ansible/module-contract.sh')
        Format-ToolResult $r 'Jedes benutzte Modul laedt gegen die gepinnte Collection' 'Modulvertrag der gepinnten Collection verletzt'
    }

    Add-Gate -Name 'ansible-powercycle-selection' -Lanes $allLanes -Kind 'container' -Body {
        # Wen der Power-Cycle schalten darf, ist reine Jinja-Filterei und damit ohne
        # ESXi-Host beweisbar: das Fixture-Playbook rechnet die ZEICHENGLEICHEN
        # Ketten des Produktions-Playbooks (PowercyclePlaybookContractTest pinnt
        # beide aufeinander) gegen an/aus/suspendiert, fehlendes needs_mac, leere
        # Eingaben und zwei kaputte Modulantwort-Formen und assertet die
        # dokumentierte Auswahl. Der erste Lauf fand einen echten Fehlschluss: ein
        # dotted selectattr WIRFT auf einer Antwort ohne das Attribut, statt sie zu
        # filtern - deshalb prueft der Wachhund im Playbook mit map+default VOR der
        # Auswahl.
        $fixture = Join-Path $repoRoot 'Docker/qa-ansible/powercycle-selection-fixtures.yml'
        if (-not (Test-Path $fixture)) { return New-InfraResult 'powercycle-selection-fixtures.yml fehlt unter dem Pruef-Root (Zero-Match)' }
        if (-not (Test-DockerImage $toolImages.ansible)) {
            return New-InfraResult ('QA-Ansible-Image {0} fehlt (docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .)' -f $toolImages.ansible)
        }
        $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo',
            $toolImages.ansible, 'ansible-playbook', '/repo/Docker/qa-ansible/powercycle-selection-fixtures.yml')
        Format-ToolResult $r 'Powercycle-Auswahl gegen Fixtures bewiesen (an/aus/suspendiert/kaputt/leer)' 'Powercycle-Auswahl weicht vom Vertrag ab'
    }

    Add-Gate -Name 'ansible-output-buffering' -Lanes $allLanes -Kind 'container' -Body {
        # Warum ein Gate fuer eine Umgebungsvariable: Der Create-Auftrag vom
        # 13.08.2026 endete mit "no output for 1800 seconds", waehrend vierzehn
        # von fuenfzehn VMs auf ESXi entstanden. Nicht der Task war zu Ende,
        # sondern der Ausgabestrom: Python puffert stdout blockweise, sobald er
        # kein Terminal ist, und der Worker liest ueber eine SSH-Pipe. Ein
        # Fortschrittsmarker im Playbook repariert das nicht, solange die Zeile
        # im Puffer steht.
        #
        # Die Probe misst deshalb Ankunftszeiten, nicht Text, und misst BEIDE
        # Faelle. Der Kontrollfall ohne PYTHONUNBUFFERED muss das Puffern
        # weiterhin zeigen; ohne ihn liesse eine Laufzeit, die ohnehin nie
        # puffert, jede Unbuffered-Behauptung durchgehen und das Gate bewachte
        # nichts. Sie braucht kein ESXi und kein Netz, nur die drei sleep-Items
        # der Fixture.
        $probe = Join-Path $repoRoot 'Docker/qa-ansible/output-buffering-probe.py'
        if (-not (Test-Path $probe)) { return New-InfraResult 'output-buffering-probe.py fehlt unter dem Pruef-Root (Zero-Match)' }
        if (-not (Test-DockerImage $toolImages.ansible)) {
            return New-InfraResult ('QA-Ansible-Image {0} fehlt (docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .)' -f $toolImages.ansible)
        }
        $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo',
            $toolImages.ansible, 'python3', '/repo/Docker/qa-ansible/output-buffering-probe.py')
        if ($r.ExitCode -eq 2) { return New-InfraResult 'Buffering-Probe ohne brauchbare Umgebung (Fixture/ansible-playbook)' $r.Output }
        Format-ToolResult $r 'Ausgabe kommt ohne PYTHONUNBUFFERED gesammelt, mit ihr laufend' 'Buffering-Vertrag der Playbookausgabe verletzt'
    }

    Add-Gate -Name 'yaml-roundtrip' -Lanes $allLanes -Kind 'container' -Body {
        # Golden-Mission semantisch durch den echten PyYAML-Loader (AP5): PHP
        # rendert die feindliche Fixture mit den Produktions-Generatoren,
        # roundtrip_verify.py laedt beide Artefakte mit yaml.safe_load (YAML 1.1,
        # wie Ansible) und deep-vergleicht gegen den expected-Vertrag der Fixture.
        $fixture = Join-Path $repoRoot 'Docker/WebAPI/tests/fixtures/golden-mission.json'
        if (-not (Test-Path $fixture)) { return New-InfraResult 'golden-mission.json fehlt unter dem Pruef-Root (Zero-Match)' }
        if (-not (Test-DockerImage $toolImages.php)) { return New-InfraResult ('Projekt-Image {0} fehlt' -f $toolImages.php) }
        if (-not (Test-DockerImage $toolImages.ansible)) {
            return New-InfraResult ('QA-Ansible-Image {0} fehlt (docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .)' -f $toolImages.ansible)
        }
        $outDir = Join-Path $artifactDir 'yaml-roundtrip'
        New-Item -ItemType Directory -Force -Path $outDir | Out-Null
        $outMount = ($outDir -replace '\\', '/')
        $render = Invoke-Tool 'docker' @('run', '--rm',
            '-v', ($repoRoot + ':/repo:ro'), '-v', ($outMount + ':/out'),
            $toolImages.php, 'php', '/repo/Docker/WebAPI/tests/tools/render-golden-serverlist.php',
            '/repo/Docker/WebAPI/tests/fixtures/golden-mission.json', '/out')
        if ($render.ExitCode -eq 2) { return New-InfraResult 'Golden-Renderer ohne brauchbare Umgebung (Fixture/Outdir)' $render.Output }
        if ($render.ExitCode -ne 0) { return New-FailResult 'Golden-Serverlist-Rendering rot (Generatorfehler)' $render.Output }
        foreach ($artifact in @('serverlist.yml', 'accounts.yml')) {
            if (-not (Test-Path (Join-Path $outDir $artifact))) {
                return New-InfraResult ('Renderer meldete Erfolg, aber {0} fehlt (Zero-Match)' -f $artifact)
            }
        }
        $verify = Invoke-Tool 'docker' @('run', '--rm',
            '-v', ($repoRoot + ':/repo:ro'), '-v', ($outMount + ':/out:ro'),
            $toolImages.ansible, 'python', '/repo/Ansible/tests/roundtrip_verify.py',
            '/repo/Docker/WebAPI/tests/fixtures/golden-mission.json', '/out')
        if ($verify.ExitCode -eq 2) { return New-InfraResult 'PyYAML-Verifier ohne brauchbare Umgebung' $verify.Output }
        Format-ToolResult $verify 'serverlist/accounts ueberleben den PyYAML-Roundtrip semantisch' 'PyYAML-Roundtrip weicht vom expected-Vertrag ab'
    }

    Add-Gate -Name 'shellcheck' -Lanes $allLanes -Kind 'container' -Body {
        $files = Get-CheckFiles @('*.sh')
        if ($files.Count -eq 0) { return New-InfraResult 'keine Shellskripte gefunden (Zero-Match)' }
        if (-not (Test-DockerImage $toolImages.shellcheck)) {
            if ($NoNetwork) { return New-InfraResult ('Tool-Image {0} fehlt lokal (NoNetwork: kein Pull)' -f $toolImages.shellcheck) }
        }
        $rel = ConvertTo-RepoRelative $files
        $r = Invoke-Tool 'docker' (@('run', '--rm', '-v', ($repoRoot + ':/mnt:ro'), $toolImages.shellcheck, '-S', 'warning') + $rel)
        if ($r.ExitCode -gt 1 -and (@($r.Output) -join ' ') -match 'pull|not found|no such') {
            return New-InfraResult ('Tool-Image {0} nicht verfuegbar' -f $toolImages.shellcheck) $r.Output
        }
        Format-ToolResult $r ("{0} Shellskript(e) sauber" -f $rel.Count) 'ShellCheck meldet Befunde'
    }

    Add-Gate -Name 'hadolint' -Lanes $allLanes -Kind 'container' -Body {
        $files = Get-CheckFiles @('Dockerfile')
        if ($files.Count -eq 0) { return New-InfraResult 'keine Dockerfiles gefunden (Zero-Match)' }
        if (-not (Test-DockerImage $toolImages.hadolint)) {
            if ($NoNetwork) { return New-InfraResult ('Tool-Image {0} fehlt lokal (NoNetwork: kein Pull)' -f $toolImages.hadolint) }
        }
        $rel = ConvertTo-RepoRelative $files
        $r = Invoke-Tool 'docker' (@('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo', $toolImages.hadolint, 'hadolint') + $rel)
        if ($r.ExitCode -gt 1 -and (@($r.Output) -join ' ') -match 'pull|not found|no such') {
            return New-InfraResult ('Tool-Image {0} nicht verfuegbar' -f $toolImages.hadolint) $r.Output
        }
        Format-ToolResult $r ("{0} Dockerfile(s) sauber" -f $rel.Count) 'Hadolint meldet Befunde'
    }

    Add-Gate -Name 'python-client-tests' -Lanes $allLanes -Kind 'container' -Body {
        if (-not (Test-Path (Join-Path (Join-Path $repoRoot 'Ansible') 'tests'))) {
            return New-InfraResult 'Ansible/tests fehlt unter dem Pruef-Root (Zero-Match)'
        }
        if (-not (Test-DockerImage $toolImages.python)) {
            if ($NoNetwork) { return New-InfraResult ('Tool-Image {0} fehlt lokal (NoNetwork: kein Pull)' -f $toolImages.python) }
        }
        $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo', $toolImages.python, 'python', '-m', 'unittest', 'discover', '-s', 'Ansible/tests')
        Format-ToolResult $r 'Ansible-Python-Tests gruen' 'Ansible-Python-Tests rot'
    }

}
