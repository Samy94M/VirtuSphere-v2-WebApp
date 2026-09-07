#Requires -Version 5.1

# Durable local outbox for MECM membership writes (ADR-0034 amendment 3).
# The file contains no secrets. Program Files is the default protected root;
# callers may supply a disposable root in tests.
$script:VsMembershipJournalSchema = 1
$script:VsMembershipJournalMaxEntries = 1000
$script:VsMembershipJournalMaxBytes = 1048576

function Get-VsMembershipJournalPath {
    param([string]$Root = $PSScriptRoot)
    return (Join-Path $Root 'membership-journal.json')
}

function Get-VsMembershipOperationId {
    param(
        [Parameter(Mandatory)][int]$VmId,
        [Parameter(Mandatory)][int]$RolloutRevision,
        [Parameter(Mandatory)][string]$ResourceId,
        [Parameter(Mandatory)][string]$CollectionId,
        [Parameter(Mandatory)][string]$Type,
        [Parameter(Mandatory)][string]$Change
    )
    $identity = '{0}|{1}|{2}|{3}|{4}|{5}' -f $VmId, $RolloutRevision, $ResourceId, $CollectionId, $Type, $Change
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($identity))).Replace('-', '').ToLowerInvariant())
    } finally {
        $sha.Dispose()
    }
}

function Test-VsMembershipJournalEntry {
    param($Entry)
    if ($null -eq $Entry) { return $false }
    if ([string]$Entry.operation_id -notmatch '^[0-9a-f]{64}$') { return $false }
    $vmId = 0; $revision = 0
    if (-not [int]::TryParse([string]$Entry.vm_id, [ref]$vmId) -or $vmId -le 0) { return $false }
    if (-not [int]::TryParse([string]$Entry.rollout_revision, [ref]$revision) -or $revision -le 0) { return $false }
    if ([string]::IsNullOrWhiteSpace([string]$Entry.resource_id)) { return $false }
    if ([string]::IsNullOrWhiteSpace([string]$Entry.collection_id)) { return $false }
    if ([string]::IsNullOrWhiteSpace([string]$Entry.collection_name)) { return $false }
    if ([string]$Entry.type -notin @('os', 'package', 'mission')) { return $false }
    if ([string]$Entry.change -notin @('added', 'removed')) { return $false }
    if ([string]$Entry.state -notin @('intent', 'remote_confirmed', 'uncertain')) { return $false }
    return $true
}

function Read-VsMembershipJournal {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        return [pscustomobject]@{ schema = $script:VsMembershipJournalSchema; entries = @() }
    }
    try {
        $item = Get-Item -LiteralPath $Path -ErrorAction Stop
        if ($item.Length -gt $script:VsMembershipJournalMaxBytes) { throw 'Journal ueberschreitet das Bytelimit.' }
        $document = Get-Content -LiteralPath $Path -Raw -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
        if ([int]$document.schema -ne $script:VsMembershipJournalSchema) { throw 'Unbekannte Journalschemaversion.' }
        $entries = @($document.entries)
        if ($entries.Count -gt $script:VsMembershipJournalMaxEntries) { throw 'Journal ueberschreitet das Eintragslimit.' }
        foreach ($entry in $entries) {
            if (-not (Test-VsMembershipJournalEntry -Entry $entry)) { throw 'Journal enthaelt einen ungueltigen Eintrag.' }
        }
        return [pscustomobject]@{ schema = $script:VsMembershipJournalSchema; entries = $entries }
    } catch {
        $quarantine = '{0}.quarantine.{1}.{2}.json' -f $Path, (Get-Date -Format 'yyyyMMddHHmmss'), ([guid]::NewGuid().ToString('N'))
        try { Move-Item -LiteralPath $Path -Destination $quarantine -ErrorAction Stop } catch { Write-Debug $_ }
        throw ('Membership-Journal ist unlesbar und wurde quarantiniert; mutierender Lauf blockiert: {0}' -f $_.Exception.Message)
    }
}

function Write-VsMembershipJournal {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][AllowEmptyCollection()][array]$Entries
    )
    if ($Entries.Count -gt $script:VsMembershipJournalMaxEntries) { throw 'Membership-Journal ist voll.' }
    $document = [ordered]@{ schema = $script:VsMembershipJournalSchema; entries = @($Entries) }
    $json = $document | ConvertTo-Json -Depth 8 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    if ($bytes.Length -gt $script:VsMembershipJournalMaxBytes) { throw 'Membership-Journal ueberschreitet das Bytelimit.' }
    $directory = Split-Path $Path -Parent
    if (-not (Test-Path -LiteralPath $directory)) { New-Item -ItemType Directory -Path $directory -Force -ErrorAction Stop | Out-Null }
    $temp = Join-Path $directory ('.membership-journal-' + [guid]::NewGuid().ToString('N') + '.tmp')
    try {
        [IO.File]::WriteAllText($temp, $json, (New-Object Text.UTF8Encoding($false)))
        if (Test-Path -LiteralPath $Path) {
            $backup = Join-Path $directory ('.membership-journal-' + [guid]::NewGuid().ToString('N') + '.bak')
            try { [IO.File]::Replace($temp, $Path, $backup, $true) } finally {
                if (Test-Path -LiteralPath $backup) { Remove-Item -LiteralPath $backup -Force -ErrorAction SilentlyContinue }
            }
        } else {
            [IO.File]::Move($temp, $Path)
        }
    } finally {
        if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Force -ErrorAction SilentlyContinue }
    }
}

function Enter-VsMembershipJournalInstance {
    param([string]$Path)
    $lockPath = $Path + '.lock'
    $directory = Split-Path $lockPath -Parent
    if (-not (Test-Path -LiteralPath $directory)) { New-Item -ItemType Directory -Path $directory -Force -ErrorAction Stop | Out-Null }
    try {
        return [IO.File]::Open($lockPath, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    } catch {
        throw 'Membership-Journal ist durch eine andere Device-Sync-Instanz gesperrt.'
    }
}

function New-VsMembershipJournalEntry {
    param(
        [Parameter(Mandatory)][int]$VmId,
        [Parameter(Mandatory)][int]$RolloutRevision,
        [Parameter(Mandatory)][string]$ResourceId,
        [Parameter(Mandatory)][string]$CollectionId,
        [Parameter(Mandatory)][string]$CollectionName,
        [Parameter(Mandatory)][ValidateSet('os','package','mission')][string]$Type,
        [Parameter(Mandatory)][ValidateSet('added','removed')][string]$Change,
        [ValidateSet('intent','remote_confirmed','uncertain')][string]$State = 'intent'
    )
    return [pscustomobject][ordered]@{
        operation_id = Get-VsMembershipOperationId -VmId $VmId -RolloutRevision $RolloutRevision -ResourceId $ResourceId -CollectionId $CollectionId -Type $Type -Change $Change
        vm_id = $VmId
        rollout_revision = $RolloutRevision
        resource_id = $ResourceId
        collection_id = $CollectionId
        collection_name = $CollectionName
        type = $Type
        change = $Change
        state = $State
        updated_at = (Get-Date).ToUniversalTime().ToString('o')
        reason = ''
    }
}

function Set-VsMembershipJournalEntry {
    param([string]$Path, [Parameter(Mandatory)]$Entry)
    $journal = Read-VsMembershipJournal -Path $Path
    $entries = @()
    $replaced = $false
    foreach ($current in @($journal.entries)) {
        if ([string]$current.operation_id -eq [string]$Entry.operation_id) {
            $entries += , $Entry; $replaced = $true
        } else { $entries += , $current }
    }
    if (-not $replaced) { $entries += , $Entry }
    Write-VsMembershipJournal -Path $Path -Entries $entries
}

function Set-VsMembershipJournalState {
    param(
        [string]$Path,
        [Parameter(Mandatory)][string]$OperationId,
        [Parameter(Mandatory)][ValidateSet('remote_confirmed','uncertain')][string]$State,
        [string]$Reason = ''
    )
    $journal = Read-VsMembershipJournal -Path $Path
    $found = $false
    foreach ($entry in @($journal.entries)) {
        if ([string]$entry.operation_id -eq $OperationId) {
            $entry.state = $State
            $entry.updated_at = (Get-Date).ToUniversalTime().ToString('o')
            $entry.reason = $Reason
            $found = $true
        }
    }
    if (-not $found) { throw 'Membership-Journaleintrag fehlt.' }
    Write-VsMembershipJournal -Path $Path -Entries @($journal.entries)
}

function Remove-VsMembershipJournalEntry {
    param([string]$Path, [Parameter(Mandatory)][string]$OperationId)
    $journal = Read-VsMembershipJournal -Path $Path
    $remaining = @($journal.entries | Where-Object { [string]$_.operation_id -ne $OperationId })
    Write-VsMembershipJournal -Path $Path -Entries $remaining
}

function Get-VsMembershipJournalEntriesForVm {
    param([string]$Path, [Parameter(Mandatory)][int]$VmId)
    $journal = Read-VsMembershipJournal -Path $Path
    return @($journal.entries | Where-Object { [int]$_.vm_id -eq $VmId })
}
