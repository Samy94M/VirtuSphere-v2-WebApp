# PSScriptAnalyzer-Konfiguration fuer Powershell-MECM.
#
# Laeuft ueber scripts\run-pester.ps1 und in CI. Die PowerShell-Skripte laufen als
# SYSTEM in Endlosschleifen auf dem SCCM-Server und auf frisch ausgerollten
# Clients; bis 2026-07 hat sie kein Werkzeug geprueft.
#
# Ausgeschlossen wird nur, was hier nachweislich nicht passt - und jede Ausnahme
# nennt ihren Grund. Insbesondere bleibt PSAvoidUsingEmptyCatchBlock AKTIV: ein
# stiller Fehler ist genau die Fehlerklasse, um die es bei diesen Skripten geht.
# Die vormals 20 leeren catch-Bloecke schreiben jetzt eine Write-Debug-Zeile, so
# dass ein -Debug-Lauf zeigt, was verschluckt wurde.
@{
    IncludeDefaultRules = $true

    # Kompatibilitaets-Gates (Plan v2, AP5): die Skripte laufen beim Kunden
    # unter Windows PowerShell 5.1 auf dem SCCM-Server (Server 2019) und in CI
    # zusaetzlich unter pwsh 7. Syntax wird gegen beide Ziele geprueft, Commands
    # und Types gegen das 5.1/Server-2019-Profil: ein Cmdlet oder .NET-Typ, den
    # 5.1 nicht kennt, faellt damit im Build auf statt nachts als SYSTEM.
    Rules = @{
        PSUseCompatibleSyntax = @{
            Enable = $true
            TargetVersions = @('5.1', '7.0')
        }
        PSUseCompatibleCommands = @{
            Enable = $true
            TargetProfiles = @('win-8_x64_10.0.17763.0_5.1.17763.316_x64_4.0.30319.42000_framework')
        }
        PSUseCompatibleTypes = @{
            Enable = $true
            TargetProfiles = @('win-8_x64_10.0.17763.0_5.1.17763.316_x64_4.0.30319.42000_framework')
        }
    }

    ExcludeRules = @(
        # Diese Skripte SIND Konsolenprogramme: sie laufen als geplante Aufgabe
        # ohne Konsole und schreiben ihren Verlauf zusaetzlich in eine Tagesdatei
        # (Write-VsLog/Write-VsClientLog). Write-Host ist hier die Anzeige, nicht
        # der Rueckgabewert; ein Umbau auf Write-Output wuerde die Pipeline der
        # Funktionen verschmutzen, die tatsaechlich Werte zurueckgeben.
        'PSAvoidUsingWriteHost',

        # ShouldProcess (-WhatIf/-Confirm) ist der Vertrag eines exportierten
        # Cmdlets. Set-StaticIpStatus, Set-DiskStatus, Set-SccmDetection,
        # Set-VsResolvedApi und New-VsDeviceCollection sind skript-interne Helfer,
        # die niemand von aussen aufruft; ein -WhatIf darauf haette keinen Leser.
        'PSUseShouldProcessForStateChangingFunctions',

        # Falsch-Positive der Regel: sie liest das Nomen-Suffix rein
        # orthografisch. "Initialize-VsTls" endet nur zufaellig auf s, und
        # Get-VsApiCandidates/Get-VsApiHeaders liefern bewusst eine Liste.
        'PSUseSingularNouns'
    )
}
