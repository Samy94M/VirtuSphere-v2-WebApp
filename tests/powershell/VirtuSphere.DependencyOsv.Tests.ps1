# dependency-osv gate (DG1): inventory from the locks, severity, GitHub ranges,
# the exception contract and the audit flow against a recorded transport. No
# network: every answer below is a fixture shaped like the measured OSV and
# GitHub answers of 04.11.2026 (ids, aliases and ranges are synthetic).

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    . (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'dependency-osv.ps1')

    function Write-Utf8File {
        param([string]$Path, [string]$Text)
        New-Item -ItemType Directory -Force -Path (Split-Path $Path -Parent) | Out-Null
        [System.IO.File]::WriteAllText($Path, $Text, (New-Object System.Text.UTF8Encoding($false)))
    }

    function New-AuditRoot {
        param([string]$Name, [switch]$NoIgnoreFile, [switch]$NoLocks)
        $root = Join-Path $TestDrive $Name
        New-Item -ItemType Directory -Force -Path $root | Out-Null
        if (-not $NoIgnoreFile) { Write-Utf8File (Join-Path $root '.dependency-osv-ignore.yaml') "# leer`nvulnerabilities: []`n" }
        if (-not $NoLocks) {
            Write-Utf8File (Join-Path $root 'app/composer.json') '{"require":{"acme/ssh":"^3.0"}}'
            Write-Utf8File (Join-Path $root 'app/composer.lock') @'
{"packages":[{"name":"acme/ssh","version":"v3.0.57","source":{"type":"git","url":"https://github.com/acme/ssh.git"}},
             {"name":"acme/polyfill","version":"9.99.100","source":{"type":"git","url":"https://example.invalid/acme/polyfill.git"}}],
 "packages-dev":[{"name":"acme/testkit","version":"12.5.31","source":{"type":"git","url":"https://github.com/acme/testkit.git"}}]}
'@
            Write-Utf8File (Join-Path $root 'qa/requirements.txt') @'
#    pip-compile --generate-hashes
urllib3==2.7.0 \
    --hash=sha256:aaaa
    # via requests
Jinja2[i18n]==3.1.6 ; python_version >= "3.9" \
    --hash=sha256:bbbb
'@
            Write-Utf8File (Join-Path $root 'e2e/package.json') '{"name":"e2e"}'
            Write-Utf8File (Join-Path $root 'e2e/package-lock.json') @'
{"lockfileVersion":3,"packages":{"":{"name":"e2e"},"node_modules/@acme/runner":{"version":"1.61.1","dev":true},"node_modules/local":{"link":true}}}
'@
        }
        return $root
    }

    # Recorded transport: routes match on method and URL prefix; every call is
    # logged so a test can prove what was (not) asked.
    $script:Calls = New-Object System.Collections.ArrayList
    $script:Routes = @()
    function Set-Routes { param([object[]]$Routes) $script:Routes = $Routes; $script:Calls.Clear() }
    $script:FakeHttp = {
        param([string]$Method, [string]$Url, [string]$Body, [hashtable]$Headers)
        [void]$script:Calls.Add(@{ Method = $Method; Url = $Url; Body = $Body; Headers = $Headers })
        foreach ($route in $script:Routes) {
            if ($route.Method -eq $Method -and $Url.StartsWith($route.Prefix)) {
                if ($route.ContainsKey('Throw')) { throw $route.Throw }
                $answer = $route.Answer
                if ($answer -is [scriptblock]) { $answer = & $answer $Body }
                $headers = @{}
                if ($route.ContainsKey('Headers')) { $headers = $route.Headers }
                $status = 200
                if ($route.ContainsKey('Status')) { $status = $route.Status }
                return @{ Status = $status; Body = $answer; Headers = $headers }
            }
        }
        throw ('unexpected request ' + $Method + ' ' + $Url)
    }
    # querybatch answer: urllib3 has a GHSA and its PYSEC alias, the rest nothing.
    $script:QueryBatch = {
        param([string]$Body)
        $request = ConvertFrom-DependencyJson $Body
        $results = @()
        foreach ($q in $request['queries']) {
            if ($q['package']['name'] -eq 'urllib3') { $results += '{"vulns":[{"id":"GHSA-test-0001"},{"id":"PYSEC-2099-1"}]}' }
            else { $results += '{}' }
        }
        return ('{"results":[' + ($results -join ',') + ']}')
    }
    $script:OsvRoutes = @(
        @{ Method = 'POST'; Prefix = 'https://api.osv.dev/v1/querybatch'; Answer = $script:QueryBatch },
        @{ Method = 'GET'; Prefix = 'https://api.osv.dev/v1/vulns/GHSA-test-0001'; Answer = '{"id":"GHSA-test-0001","aliases":["CVE-2099-1","PYSEC-2099-1"],"database_specific":{"severity":"HIGH"},"affected":[{"package":{"ecosystem":"PyPI","name":"urllib3"},"ranges":[{"type":"ECOSYSTEM","events":[{"introduced":"0"},{"fixed":"2.8.0"}]}]}]}' },
        @{ Method = 'GET'; Prefix = 'https://api.osv.dev/v1/vulns/PYSEC-2099-1'; Answer = '{"id":"PYSEC-2099-1","aliases":["CVE-2099-1","GHSA-test-0001"],"severity":[{"type":"CVSS_V4","score":"CVSS:4.0/AV:N"}]}' }
    )
    $script:GitHubQuiet = @(
        @{ Method = 'GET'; Prefix = 'https://api.github.com/advisories?'; Answer = '[]' },
        @{ Method = 'GET'; Prefix = 'https://api.github.com/repos/acme/ssh/security-advisories'; Answer = '[]' }
    )
    $script:RepoAdvisory = @{ Method = 'GET'; Prefix = 'https://api.github.com/repos/acme/ssh/security-advisories'; Answer = '[{"ghsa_id":"GHSA-test-0002","cve_id":null,"state":"published","severity":"high","withdrawn_at":null,"vulnerabilities":[{"package":{"ecosystem":"composer","name":"acme/ssh"},"vulnerable_version_range":">= 3.0.0, <= 3.0.57","patched_versions":"3.0.58"}]}]' }

    function Invoke-Audit {
        param([string]$Root, [datetime]$Today = [datetime]'2026-11-04', [string]$Wheelhouse = '', [switch]$RequireWheelhouse)
        $all = @(Invoke-DependencyOsvAudit -Root $Root -Http $script:FakeHttp -Today $Today -Wheelhouse $Wheelhouse -RequireWheelhouse:$RequireWheelhouse 6>&1)
        $result = @($all | Where-Object { $_ -isnot [System.Management.Automation.InformationRecord] })[-1]
        $lines = @($all | Where-Object { $_ -is [System.Management.Automation.InformationRecord] } | ForEach-Object { "$_" })
        return @{ Result = $result; Lines = $lines }
    }
}

Describe 'dependency-osv inventory from the locks' {
    It 'splits composer.lock into shipped and dev packages with the GitHub repository' {
        $root = New-AuditRoot 'inventory'
        $prod = Get-ComposerLockPackages (Join-Path $root 'app/composer.lock') 'packages' 'p'
        $dev = Get-ComposerLockPackages (Join-Path $root 'app/composer.lock') 'packages-dev' 'd'
        @($prod | ForEach-Object { $_.Name + '@' + $_.Version }) | Should -Be @('acme/ssh@3.0.57', 'acme/polyfill@9.99.100')
        @($prod | ForEach-Object { $_.Repository }) | Should -Be @('acme/ssh', '')
        @($dev | ForEach-Object { $_.Name }) | Should -Be @('acme/testkit')
    }

    It 'reads pip-compile pins with extras, markers and hashes, and keeps unpinned lines visible' {
        $root = New-AuditRoot 'pip'
        Write-Utf8File (Join-Path $root 'qa/requirements-extra.txt') "requests>=2.0`n-r requirements.txt`n"
        $pins = Get-PipRequirementPins (Join-Path $root 'qa/requirements.txt') 'q'
        @($pins | ForEach-Object { $_.Name + '@' + $_.Version }) | Should -Be @('urllib3@2.7.0', 'jinja2@3.1.6')
        $loose = Get-PipRequirementPins (Join-Path $root 'qa/requirements-extra.txt') 'x'
        @($loose).Count | Should -Be 1
        $loose[0].Version | Should -Be ''
        Test-DependencyVersionQueryable $loose[0].Version | Should -BeFalse
    }

    It 'derives wheelhouse pins from wheel and sdist names in PEP 503 form' {
        $wheels = Join-Path $TestDrive 'wheels'
        foreach ($name in @('ansible_core-2.19.15-py3-none-any.whl', 'MarkupSafe-3.0.4-cp313-cp313-manylinux_2_17_x86_64.whl', 'pyvmomi-9.1.0.0.tar.gz', 'README.txt')) {
            Write-Utf8File (Join-Path $wheels $name) 'x'
        }
        $pins = Get-WheelhousePins $wheels 'w'
        @($pins | ForEach-Object { $_.Name + '@' + $_.Version }) | Should -Be @('ansible-core@2.19.15', 'markupsafe@3.0.4', 'pyvmomi@9.1.0.0')
    }

    It 'reads an npm lockfile with the empty root key on every PowerShell edition and skips links' {
        $root = New-AuditRoot 'npm'
        $packages = Get-NpmLockPackages (Join-Path $root 'e2e/package-lock.json') 'n'
        @($packages | ForEach-Object { $_.Name + '@' + $_.Version }) | Should -Be @('@acme/runner@1.61.1')
    }

    It 'discovers every lock, flags a manifest without lock and an unreadable lock format' {
        $root = New-AuditRoot 'discovery'
        Write-Utf8File (Join-Path $root 'tool/package.json') '{}'
        Write-Utf8File (Join-Path $root 'web/yarn.lock') 'x'
        Write-Utf8File (Join-Path $root 'vendor/x/composer.lock') '{}'
        Write-Utf8File (Join-Path $root 'nested/.git') 'gitdir: elsewhere'
        Write-Utf8File (Join-Path $root 'nested/composer.lock') '{}'
        $found = Find-DependencyLockFiles $root
        @($found.Locks | ForEach-Object { Get-DependencyRelativePath $root $_.Path }) | Should -Be @('app/composer.lock', 'e2e/package-lock.json', 'qa/requirements.txt')
        @($found.Problems | ForEach-Object { $_.Code + ' ' + (Get-DependencyRelativePath $root $_.Path) } | Sort-Object) | Should -Be @('unlocked-manifest tool/package.json', 'unsupported-lock web/yarn.lock')
    }
}

Describe 'dependency-osv severity and ranges' {
    It 'scores CVSS v3 base vectors like the FIRST calculator' {
        Get-CvssV3BaseScore 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H' | Should -Be 9.8
        Get-CvssV3BaseScore 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:H' | Should -Be 7.5
        Get-CvssV3BaseScore 'CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:C/C:L/I:L/A:N' | Should -Be 6.1
        Get-CvssV3BaseScore 'CVSS:3.1/AV:L/AC:H/PR:L/UI:N/S:U/C:H/I:H/A:N' | Should -Be 6.3
        Get-CvssV3BaseScore 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:N' | Should -Be 0
        Get-CvssV3BaseScore 'CVSS:4.0/AV:N/AC:L' | Should -BeNullOrEmpty
    }

    It 'blocks critical, high and unrated, and lets moderate and low through as notes' {
        @(4, 3, 0 | ForEach-Object { Test-SeverityBlocking $_ }) | Should -Be @($true, $true, $true)
        @(2, 1 | ForEach-Object { Test-SeverityBlocking $_ }) | Should -Be @($false, $false)
        ConvertTo-SeverityRank 'MODERATE' | Should -Be 2
        ConvertTo-SeverityRank 'medium' | Should -Be 2
        ConvertTo-SeverityRank '' | Should -Be 0
    }

    It 'evaluates GitHub ranges including the maintainer bare-version form' {
        Test-GitHubRangeAffects '3.0.57' '>= 3.0.0, <= 3.0.57' | Should -Be 'affected'
        Test-GitHubRangeAffects '3.0.58' '>= 3.0.0, <= 3.0.57' | Should -Be 'unaffected'
        Test-GitHubRangeAffects '2.0.9' '>= 3.0.0, <= 3.0.57' | Should -Be 'unaffected'
        Test-GitHubRangeAffects '1.1.9' '< 1.2' | Should -Be 'affected'
        Test-GitHubRangeAffects '3.0.56' '3.0.0' '3.0.57' | Should -Be 'affected'
        Test-GitHubRangeAffects '3.0.58' '3.0.0' '3.0.57' | Should -Be 'unaffected'
        Test-GitHubRangeAffects '3.0.0' '3.0.0' '' | Should -Be 'affected'
        Test-GitHubRangeAffects '1.2.0' '~1.2' | Should -Be 'unparsable'
        Test-GitHubRangeAffects '1.2.0-beta1' '< 1.2.0' | Should -Be 'affected'
    }

    It 'merges a GHSA and its PYSEC alias into one group with the higher rating' {
        $records = @{
            'GHSA-a' = (ConvertFrom-DependencyJson '{"id":"GHSA-a","aliases":["PYSEC-a"],"database_specific":{"severity":"HIGH"}}')
            'PYSEC-a' = (ConvertFrom-DependencyJson '{"id":"PYSEC-a","aliases":["GHSA-a"]}')
            'GHSA-gone' = (ConvertFrom-DependencyJson '{"id":"GHSA-gone","withdrawn":"2026-01-01T00:00:00Z","database_specific":{"severity":"CRITICAL"}}')
        }
        $groups = Get-OsvAdvisoryGroups @('PYSEC-a', 'GHSA-a', 'GHSA-gone') $records
        @($groups).Count | Should -Be 1
        $groups[0].Rank | Should -Be 3
        Get-AdvisoryPrimaryId $groups[0].Ids | Should -Be 'GHSA-a'
    }
}

Describe 'dependency-osv exception contract' {
    It 'accepts the empty list and a complete entry' {
        $file = Join-Path $TestDrive 'ok.yaml'
        Write-Utf8File $file "# c`nvulnerabilities:`n  - id: GHSA-test-0001`n    statement: `"ungenutzt; Owner: Betrieb`"`n    expired_at: 2026-12-31`n"
        $parsed = Read-DependencyIgnoreFile $file
        $parsed.Errors | Should -BeNullOrEmpty
        $parsed.Entries[0].Id | Should -Be 'GHSA-test-0001'
        Write-Utf8File $file "vulnerabilities: []`n"
        (Read-DependencyIgnoreFile $file).Errors | Should -BeNullOrEmpty
    }

    It 'rejects an entry without owner, without date, with an unknown key or a file without root key' {
        $file = Join-Path $TestDrive 'bad.yaml'
        Write-Utf8File $file "vulnerabilities:`n  - id: A`n    statement: nur Grund`n    expired_at: 2026-12-31`n  - id: B`n    statement: x; Owner: y`n    expired_at: bald`n  - id: C`n    statement: x; Owner: y`n    expired_at: 2026-12-31`n    package: z`n"
        $errors = @((Read-DependencyIgnoreFile $file).Errors)
        @($errors | Where-Object { $_ -match 'Owner' }).Count | Should -Be 1
        @($errors | Where-Object { $_ -match 'kein Datum' }).Count | Should -Be 1
        @($errors | Where-Object { $_ -match 'unbekannter Schluessel' }).Count | Should -Be 1
        Write-Utf8File $file "- id: A`n"
        @((Read-DependencyIgnoreFile $file).Errors).Count | Should -BeGreaterThan 0
    }

    It 'matches by alias and stops covering after the expiry day' {
        $entries = @([pscustomobject]@{ Id = 'CVE-2099-1'; Statement = 's; Owner: o'; ExpiresAt = [datetime]'2026-11-04' })
        @((Find-DependencyException @('GHSA-x', 'CVE-2099-1') $entries ([datetime]'2026-11-04')).Active).Count | Should -Be 1
        $later = Find-DependencyException @('GHSA-x', 'CVE-2099-1') $entries ([datetime]'2026-11-05')
        @($later.Active).Count | Should -Be 0
        @($later.Expired).Count | Should -Be 1
    }
}

Describe 'dependency-osv audit flow' {
    It 'finds the OSV advisory once per alias group and the repository advisory OSV does not know' {
        Set-Routes (@($script:OsvRoutes) + @(@{ Method = 'GET'; Prefix = 'https://api.github.com/advisories?'; Answer = '[]' }, $script:RepoAdvisory))
        $run = Invoke-Audit (New-AuditRoot 'flow-red')
        $run.Result.ExitCode | Should -Be 1
        @($run.Result.Blocking).Count | Should -Be 2
        ($run.Result.Blocking -join "`n") | Should -Match 'HIGH GHSA-test-0001 urllib3@2\.7\.0 \[PyPI\] via osv; Fix: 2\.8\.0'
        ($run.Result.Blocking -join "`n") | Should -Match 'HIGH GHSA-test-0002 acme/ssh@3\.0\.57 \[Packagist\] via github-repository-advisories; Fix: 3\.0\.58'
        # Repository advisories only for the shipped package with a GitHub source.
        @($script:Calls | Where-Object { $_.Url -like '*security-advisories*' }).Count | Should -Be 1
        $global = @($script:Calls | Where-Object { $_.Url -like 'https://api.github.com/advisories?*' })
        $global.Count | Should -Be 1
        [uri]::UnescapeDataString($global[0].Url) | Should -Match 'affects=acme/ssh@3\.0\.57,acme/polyfill@9\.99\.100,acme/testkit@12\.5\.31'
    }

    It 'reports every unit before and after it with the total known up front' {
        Set-Routes (@($script:OsvRoutes) + @($script:GitHubQuiet))
        $run = Invoke-Audit (New-AuditRoot 'flow-progress')
        $run.Result.ExitCode | Should -Be 1
        $total = $run.Result.UnitCount
        $total | Should -Be 6
        for ($n = 1; $n -le $total; $n++) {
            @($run.Lines | Where-Object { $_ -match ('^\[' + $n + '/' + $total + '\] RUN ') }).Count | Should -Be 1
            @($run.Lines | Where-Object { $_ -match ('^\[' + $n + '/' + $total + '\] (PASS|FAIL|INFRA) ') }).Count | Should -Be 1
        }
    }

    It 'passes when an active exception covers the finding by alias, and blocks again after expiry' {
        $root = New-AuditRoot 'flow-exception'
        Write-Utf8File (Join-Path $root '.dependency-osv-ignore.yaml') "vulnerabilities:`n  - id: CVE-2099-1`n    statement: `"nur QA-Image; Owner: QA`"`n    expired_at: 2026-11-30`n"
        Set-Routes (@($script:OsvRoutes) + @($script:GitHubQuiet))
        $run = Invoke-Audit $root
        $run.Result.ExitCode | Should -Be 0
        ($run.Result.Notes -join "`n") | Should -Match '\[osv\.excepted\].*GHSA-test-0001'
        Set-Routes (@($script:OsvRoutes) + @($script:GitHubQuiet))
        $expired = Invoke-Audit $root ([datetime]'2026-12-01')
        $expired.Result.ExitCode | Should -Be 1
        ($expired.Result.Notes -join "`n") | Should -Match '\[osv\.exception-expired\] CVE-2099-1'
    }

    It 'turns a missing network into infrastructure_error, never pass' {
        Set-Routes @(@{ Method = 'POST'; Prefix = 'https://api.osv.dev/'; Throw = '[osv.network] keine Antwort von api.osv.dev: Name nicht aufloesbar' },
            @{ Method = 'GET'; Prefix = 'https://api.github.com/'; Throw = '[osv.network] keine Antwort von api.github.com: Name nicht aufloesbar' })
        $run = Invoke-Audit (New-AuditRoot 'flow-offline')
        $run.Result.ExitCode | Should -Be 2
        @($run.Result.Infra | Where-Object { $_ -match '\[osv\.network\]' }).Count | Should -Be $run.Result.UnitCount
        @($run.Lines | Where-Object { $_ -match '\] PASS ' }).Count | Should -Be 0
    }

    It 'names the GitHub rate limit and its reset instead of a generic HTTP error' {
        Set-Routes (@($script:OsvRoutes) + @(@{ Method = 'GET'; Prefix = 'https://api.github.com/'; Status = 403; Answer = '{"message":"API rate limit exceeded"}'; Headers = @{ 'x-ratelimit-remaining' = '0'; 'x-ratelimit-reset' = '1793823796' } }))
        $run = Invoke-Audit (New-AuditRoot 'flow-ratelimit')
        ($run.Result.Infra -join "`n") | Should -Match '\[osv\.github-rate-limit\] GitHub-Ratenlimit erschoepft bis 2026-11-04'
        $run.Result.ExitCode | Should -Be 1
    }

    It 'refuses a paginated OSV answer instead of reading half of it' {
        Set-Routes (@(@{ Method = 'POST'; Prefix = 'https://api.osv.dev/v1/querybatch'; Answer = { param($b) $q = @((ConvertFrom-DependencyJson $b)['queries']); '{"results":[' + ((@(1..$q.Count) | ForEach-Object { '{"vulns":[],"next_page_token":"t"}' }) -join ',') + ']}' } }) + @($script:GitHubQuiet))
        $run = Invoke-Audit (New-AuditRoot 'flow-pages')
        ($run.Result.Infra -join "`n") | Should -Match '\[osv\.pagination\]'
        $run.Result.ExitCode | Should -Be 2
    }

    It 'is infrastructure_error without locks or without the exception file, before any request' {
        Set-Routes @()
        $empty = Invoke-Audit (New-AuditRoot 'flow-zero' -NoLocks)
        $empty.Result.ExitCode | Should -Be 2
        ($empty.Result.Infra -join "`n") | Should -Match '\[osv\.zero-match\]'
        $script:Calls.Count | Should -Be 0
        Set-Routes (@($script:OsvRoutes) + @($script:GitHubQuiet))
        $noIgnore = Invoke-Audit (New-AuditRoot 'flow-noignore' -NoIgnoreFile)
        ($noIgnore.Result.Infra -join "`n") | Should -Match '\[osv\.ignore-missing\]'
    }

    It 'requires the wheelhouse when the run built the bundle and audits it when present' {
        Set-Routes (@($script:OsvRoutes) + @($script:GitHubQuiet))
        $missing = Invoke-Audit (New-AuditRoot 'flow-wheel-missing') -Wheelhouse (Join-Path $TestDrive 'no-such-wheels') -RequireWheelhouse
        ($missing.Result.Infra -join "`n") | Should -Match '\[osv\.wheelhouse-missing\]'
        $wheels = Join-Path $TestDrive 'flow-wheels'
        Write-Utf8File (Join-Path $wheels 'urllib3-2.7.0-py3-none-any.whl') 'x'
        Set-Routes (@($script:OsvRoutes) + @($script:GitHubQuiet))
        $present = Invoke-Audit (New-AuditRoot 'flow-wheel-present') -Wheelhouse $wheels -RequireWheelhouse
        @($present.Result.Sources | Where-Object { $_.Name -eq 'offline-bundle deps/wheels' -and $_.Shipped }).Count | Should -Be 1
        ($present.Result.Blocking -join "`n") | Should -Match 'urllib3@2\.7\.0 .*Lock: .*offline-bundle deps/wheels'
    }

    It 'keeps moderate advisories as notes and treats an unrated one as blocking' {
        $root = New-AuditRoot 'flow-severity'
        Set-Routes @(
            @{ Method = 'POST'; Prefix = 'https://api.osv.dev/v1/querybatch'; Answer = $script:QueryBatch },
            @{ Method = 'GET'; Prefix = 'https://api.osv.dev/v1/vulns/GHSA-test-0001'; Answer = '{"id":"GHSA-test-0001","database_specific":{"severity":"MODERATE"}}' },
            @{ Method = 'GET'; Prefix = 'https://api.osv.dev/v1/vulns/PYSEC-2099-1'; Answer = '{"id":"PYSEC-2099-1"}' },
            $script:GitHubQuiet[0], $script:GitHubQuiet[1])
        $run = Invoke-Audit $root
        ($run.Result.Notes -join "`n") | Should -Match '\[osv\.note\] MODERATE GHSA-test-0001'
        ($run.Result.Blocking -join "`n") | Should -Match 'UNKNOWN PYSEC-2099-1 urllib3'
        $run.Result.ExitCode | Should -Be 1
    }
}
