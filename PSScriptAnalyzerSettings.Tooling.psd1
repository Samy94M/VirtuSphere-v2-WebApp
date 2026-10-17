# PSScriptAnalyzer-Regelsatz fuer Tooling und Tests (scripts/, tests/powershell).
#
# Warum ein zweiter Satz: PSScriptAnalyzerSettings.psd1 bindet das Befehls- und
# Typprofil des MECM-Servers (Powershell-MECM). Scripts und Pester-Tests laufen
# dagegen unter Windows PowerShell 5.1 und pwsh 7. Fuer sie zaehlen die
# Syntaxkompatibilitaet beider Hosts, die BOM-Pflicht fuer Unicode-Dateien und
# die Sicherheitsregeln (Klartext-Passwort, Invoke-Expression, leere catch-
# Bloecke, Backtick- und Null-Vergleichsfallen). Der Server-Satz wuerde dort
# rund 3700 Befunde liefern, fast alle PSUseCompatibleCommands auf Pester-
# Befehle; dieser Satz meldet nur, was zur Laufzeit bricht oder ein Risiko ist.
@{
    IncludeRules = @(
        'PSUseCompatibleSyntax',
        'PSUseBOMForUnicodeEncodedFile',
        'PSAvoidUsingEmptyCatchBlock',
        'PSAvoidUsingInvokeExpression',
        'PSAvoidUsingPlainTextForPassword',
        'PSUsePSCredentialType',
        'PSAvoidUsingConvertToSecureStringWithPlainText',
        'PSMisleadingBacktick',
        'PSPossibleIncorrectComparisonWithNull',
        'PSPossibleIncorrectUsageOfAssignmentOperator',
        'PSPossibleIncorrectUsageOfRedirectionOperator'
    )

    Rules = @{
        PSUseCompatibleSyntax = @{
            Enable         = $true
            TargetVersions = @('5.1', '7.0')
        }
    }
}
