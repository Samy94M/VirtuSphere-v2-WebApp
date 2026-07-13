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
