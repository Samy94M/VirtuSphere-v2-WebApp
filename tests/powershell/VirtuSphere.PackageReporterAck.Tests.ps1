BeforeAll {
    $repoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $adapter = Join-Path $repoRoot 'Powershell-MECM/clients/VirtuSphere-Package-Reporter.ps1'
    . $adapter
    $fixture = Get-Content -LiteralPath (Join-Path $repoRoot 'Docker/WebAPI/tests/fixtures/package-report-v1.json') -Raw | ConvertFrom-Json
    $script:ReportRunId = [string]$fixture.base.run_id
    function Resolve-TestAck {
        param([string]$Json, [int]$Status = 200, [string]$Type = 'application/json; charset=utf-8', [string]$Run = $script:ReportRunId)
        Resolve-VsPackageReportAcknowledgement -StatusCode $Status -ContentType $Type -ResponseJson $Json `
            -RunId $Run -ReportEvent 'step_result' -EventSeq 301
    }
}

Describe 'T4 package reporter accepts only exact post-commit acknowledgements' {
    BeforeEach {
        $script:AcceptedJson = '{"schema_version":1,"run_id":"018f2f49-5e41-4d55-8f05-8f55a5334101","event":"step_result","event_seq":301,"accepted":true,"deduplicated":false}'
    }

    It 'recognizes one exact acceptance and one exact deduplication' {
        $accepted = Resolve-TestAck -Json $script:AcceptedJson
        $accepted.Confirmed | Should -BeTrue
        $accepted.Deduplicated | Should -BeFalse
        $accepted.Reason | Should -Be 'accepted'

        $duplicate = Resolve-TestAck -Json ($script:AcceptedJson.Replace('"accepted":true,"deduplicated":false', '"accepted":false,"deduplicated":true'))
        $duplicate.Confirmed | Should -BeTrue
        $duplicate.Deduplicated | Should -BeTrue
        $duplicate.Reason | Should -Be 'deduplicated'
    }

    It 'does not treat 202, HTML or a foreign run as confirmed' {
        (Resolve-TestAck -Json $script:AcceptedJson -Status 202).Confirmed | Should -BeFalse
        (Resolve-TestAck -Json '<html>login</html>' -Type 'text/html').Confirmed | Should -BeFalse
        (Resolve-TestAck -Json $script:AcceptedJson -Run '018f2f49-5e41-4d55-8f05-8f55a5334199').Confirmed | Should -BeFalse
    }

    It 'rejects wrong event, sequence, schema and nonboolean confirmation flags' {
        (Resolve-TestAck -Json ($script:AcceptedJson.Replace('"step_result"', '"completed"'))).Confirmed | Should -BeFalse
        (Resolve-TestAck -Json ($script:AcceptedJson.Replace('"event_seq":301', '"event_seq":302'))).Confirmed | Should -BeFalse
        (Resolve-TestAck -Json ($script:AcceptedJson.Replace('"schema_version":1', '"schema_version":2'))).Confirmed | Should -BeFalse
        (Resolve-TestAck -Json ($script:AcceptedJson.Replace('"accepted":true', '"accepted":"true"'))).Confirmed | Should -BeFalse
        (Resolve-TestAck -Json ($script:AcceptedJson.Replace('"deduplicated":false', '"deduplicated":true'))).Confirmed | Should -BeFalse
    }

    It 'rejects duplicate or foreign keys and escaped-key ambiguity' {
        (Resolve-TestAck -Json ($script:AcceptedJson.TrimEnd('}') + ',"accepted":false}')).Confirmed | Should -BeFalse
        (Resolve-TestAck -Json ($script:AcceptedJson.TrimEnd('}') + ',"ACCEPTED":false}')).Confirmed | Should -BeFalse
        (Resolve-TestAck -Json ($script:AcceptedJson.TrimEnd('}') + ',"success":true}')).Confirmed | Should -BeFalse
        (Resolve-TestAck -Json ($script:AcceptedJson.Replace('"accepted"', '"acce\u0070ted"'))).Confirmed | Should -BeFalse
    }

    It 'bounds the response before parsing and refuses malformed JSON' {
        (Resolve-TestAck -Json ($script:AcceptedJson + (' ' * 4096))).Reason | Should -Be 'body_shape'
        (Resolve-TestAck -Json '{"schema_version":1').Confirmed | Should -BeFalse
        (Resolve-TestAck -Json $script:AcceptedJson -Type 'application/json; charset=latin1').Confirmed | Should -BeFalse
    }
}
