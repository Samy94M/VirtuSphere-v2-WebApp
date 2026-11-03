# Dot-sourced check module. Importing defines functions only.
#
# Die Identitaet des QA-Wegwerf-Stacks ist SSoT und wird geteilt: der Runner
# fuehrt seine Integration-/Release-Gates dagegen aus, und der getrennte
# Baseline-Updatebefehl (scripts/update-visual-baselines.ps1) muss GENAU
# denselben Stack treffen. Zwei Kopien dieser Namen waeren zwei Meinungen
# darueber, welcher Stack der Wegwerfstack ist, und die falsche Antwort hiesse:
# reviewte Sollbilder vom Dev-Stack.
#
# Es ist eine Funktion und keine Zuweisungsliste, weil dieses Verzeichnis
# ausschliesslich Funktionen definieren darf: der Aufrufer entscheidet, wann er
# initialisiert (VirtuSphere.CheckRunner.Tests.ps1 prueft das am AST).

function Get-QaStackIdentity {
    <#
    .SYNOPSIS
        Eigenes Compose-Projekt aus docker-compose.yml + Docker/qa/docker-compose.qa.yml
        mit Docker/qa/qa.env: eigene Wegwerf-DB, eigene ssl/conf-Volumes, Ports 8031ff.
        Kein Integration-Gate laeuft gegen den Dev-Stack oder die Dev-Datenbank.
    #>
    param([Parameter(Mandatory = $true)][string]$RepoRoot)

    $qaDir = Join-Path (Join-Path $RepoRoot 'Docker') 'qa'
    return @{
        Project        = 'virtusphere-qa'
        PhpContainer   = 'virtusphere-qa-php-1'
        WebContainer   = 'virtusphere-qa-webserver-1'
        MysqlContainer = 'virtusphere-qa-mysql-1'
        Network        = 'virtusphere-qa_default'
        PortalBase     = 'http://127.0.0.1:8031'
        Dir            = $qaDir
        EnvFile        = Join-Path $qaDir 'qa.env'
        ComposeOverride = Join-Path $qaDir 'docker-compose.qa.yml'
        # ldap-* Dienste: hermetische LDAP-TLS-Fixture (Plan-Abschnitt 18.3,
        # Etappe 7, Docker/qa/docker-compose.qa.yml). Immer Teil der
        # Integrationslane, damit DirectoryLdapFixtureTest.php nie skippt
        # (ADR-0015-Ergaenzung: kein Skip in dieser Lane).
        Services       = @('webserver', 'php', 'mysql', 'deploy-worker', 'maintenance-worker',
            'ldap-dc1', 'ldap-dc2', 'ldap-badcert-unknown-ca', 'ldap-badcert-expired',
            'ldap-badcert-wrongname', 'ldap-dc-rotated', 'ldap-blackhole')
    }
}
