# Read-only MC02 inventory. This module has no import-time side effects.
#
# Get-VsClientPreflightReport reads the site once and answers four questions
# with site evidence: does the RBAC view of the caller cover the target
# objects (rbac_scope), are the local and the UNC source path the same
# directory (source_mapping), what references the target applications from
# outside the client chain (references), and do the deployments follow the
# Core policy (deployment_policy). Every answer is a row in Checks with the
# status pass, fail, warn or unknown and a reason; every row that is not pass
# is also a finding. Field names are those two site surveys returned on the
# real provider (15.10.2026).
#
# The file also owns the read helpers that the site survey and the legacy
# retirement share (WQL quoting, UNC mapping, own SIDs, application groups).
# Every entry script that needs them loads this file already, so one copy
# serves all of them. Requires VirtuSphere-ClientPackaging.ps1.
Set-StrictMode -Version 1.0

$script:VsPreflightAllScopeId = 'SMS00ALL'
$script:VsPreflightMaxLimitingChain = 64
$script:VsPreflightCimBatchSize = 20
$script:VsPreflightIdPattern = '\A[A-Za-z0-9]{1,16}\z'
$script:VsPreflightKeyPattern = '\A[A-Za-z0-9_./-]{1,256}\z'
$script:VsPreflightCiIdPattern = '\A[0-9]{1,10}\z'

function Get-VsClientPreflightPlanId {
    param([Parameter(Mandatory)][object[]]$Rows)
    $json = ConvertTo-Json -InputObject $Rows -Depth 12 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

# --- Read helpers shared with the site survey and the legacy retirement ------

function ConvertTo-VsWqlString {
    param([AllowNull()][string]$Value)
    return ("'{0}'" -f ([string]$Value).Replace('\', '\\').Replace("'", "\'"))
}

# Splits a UNC path such as \\SERVER\share\a\b into host, share and the rest.
# A trailing backslash (content paths in SDMPackageXML carry one) is dropped.
# Returns $null for any other form, also for relative segments that would
# leave the share.
function ConvertFrom-VsUncPath {
    param([AllowNull()][string]$Path)
    if ([string]::IsNullOrWhiteSpace($Path)) { return $null }
    $text = $Path.Trim()
    if ($text.Length -gt 2) { $text = $text.TrimEnd('\') }
    if ($text -cnotmatch '\A\\\\(?<host>[A-Za-z0-9][A-Za-z0-9.\-]*)\\(?<share>[^\\/:*?"<>|\x00-\x1F]+)(?<rest>(\\[^\\/:*?"<>|\x00-\x1F]+)*)\z') { return $null }
    foreach ($segment in @(([string]$Matches['share'] + [string]$Matches['rest']) -split '\\')) {
        if ($segment -ceq '.' -or $segment -ceq '..') { return $null }
    }
    return [pscustomobject]@{
        Host = [string]$Matches['host']
        Share = [string]$Matches['share']
        Rest = [string]$Matches['rest']
    }
}

# Maps a UNC path to the local path behind a share of this computer. Only a
# host equal to the NetBIOS or FQDN name of this computer counts (any case).
function Get-VsLocalPathForUnc {
    param(
        [AllowNull()][string]$Path,
        [AllowEmptyCollection()][object[]]$LocalShares,
        [Parameter(Mandatory)][AllowEmptyCollection()][string[]]$LocalNames
    )
    $parsed = ConvertFrom-VsUncPath -Path $Path
    if ($null -eq $parsed) { return [pscustomobject]@{ Parsed = $false; Host = ''; Share = ''; IsLocal = $false; ShareMatch = ''; LocalPath = '' } }
    $isLocal = @($LocalNames | Where-Object { $_ -and [string]$_ -ieq $parsed.Host }).Count -gt 0
    $result = [pscustomobject]@{ Parsed = $true; Host = $parsed.Host; Share = $parsed.Share; IsLocal = $isLocal; ShareMatch = ''; LocalPath = '' }
    if (-not $isLocal) { return $result }
    $share = @($LocalShares | Where-Object { $_ -and [string]$_.Name -ieq $parsed.Share } | Select-Object -First 1)
    if ($share.Count -eq 0 -or -not $share[0].Path) { return $result }
    $result.ShareMatch = [string]$share[0].Name
    $result.LocalPath = ([string]$share[0].Path).TrimEnd('\') + $parsed.Rest
    return $result
}

# NetBIOS and FQDN names of this computer.
function Get-VsLocalComputerNames {
    $names = New-Object System.Collections.Generic.List[string]
    $names.Add([string]$env:COMPUTERNAME)
    try {
        $fqdn = [string]([Net.Dns]::GetHostEntry([string]$env:COMPUTERNAME).HostName)
        if ($fqdn) { $names.Add($fqdn) }
    } catch {
        Write-Verbose ([string]$_)
    }
    if ($env:USERDNSDOMAIN -and $env:COMPUTERNAME) { $names.Add(('{0}.{1}' -f $env:COMPUTERNAME, $env:USERDNSDOMAIN)) }
    @($names | Where-Object { $_ } | Sort-Object -Unique)
}

# SID of the caller and of its groups, to find its own SMS_Admin rows.
function Get-VsCurrentSids {
    try {
        $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
        $sids = New-Object System.Collections.Generic.List[string]
        $sids.Add([string]$identity.User.Value)
        foreach ($group in @($identity.Groups)) { $sids.Add([string]$group.Value) }
        @($sids | Where-Object { $_ } | Sort-Object -Unique)
    } catch {
        Write-Verbose ([string]$_)
    }
}

# The system drive on Windows. pwsh on Linux has no system directory, and a
# bare "/" would resolve inside the current non-FileSystem provider, so the
# fallback names the FileSystem provider explicitly (Linux Pester lane).
function Get-VsFileSystemRoot {
    $root = [IO.Path]::GetPathRoot([Environment]::SystemDirectory)
    if ([string]::IsNullOrEmpty($root)) {
        $root = 'Microsoft.PowerShell.Core\FileSystem::' + [IO.Path]::GetPathRoot([IO.Path]::GetTempPath())
    }
    return $root
}

# ModelName is ScopeId_<guid>/Application_<guid>. Only the second part is
# revision independent and appears in application-group definitions.
function Get-VsApplicationLogicalName {
    param([string]$ModelName)
    $parts = @(([string]$ModelName) -split '/')
    if ($parts.Count -ne 2 -or $parts[1] -cnotmatch '\AApplication_[0-9A-Fa-f]{8}(-[0-9A-Fa-f]{4}){3}-[0-9A-Fa-f]{12}\z') { return '' }
    return $parts[1]
}

# Which application groups contain the given applications, read from each
# group definition. Groups lists every visible group for the inventory.
function Read-VsApplicationGroupMembership {
    param([AllowEmptyCollection()][string[]]$LogicalNames)
    $members = @{}
    foreach ($logicalName in @($LogicalNames | Where-Object { $_ })) { $members[$logicalName] = @() }
    $identities = New-Object System.Collections.Generic.List[object]
    try {
        $groups = @(Get-CMApplicationGroup -ShowHidden -ErrorAction Stop)
        foreach ($group in $groups) {
            $groupName = Get-VsObjectPropertyText -Object $group -Names @('LocalizedDisplayName', 'Name')
            $identities.Add([pscustomobject]@{
                Name = $groupName
                ModelName = (Get-VsObjectPropertyText -Object $group -Names @('ModelName'))
                CiId = (Get-VsObjectPropertyText -Object $group -Names @('CI_ID'))
            })
            $raw = Get-VsObjectPropertyText -Object $group -Names @('SDMPackageXML')
            if (-not $raw -and $group.PSObject.Methods['Get']) {
                # Lazy property of the provider object; load it once explicitly.
                $group.Get()
                $raw = Get-VsObjectPropertyText -Object $group -Names @('SDMPackageXML')
            }
            if (-not $raw) {
                return [pscustomobject]@{ State = 'unknown'; GroupCount = $groups.Count; Members = @{}; Groups = $identities.ToArray(); Detail = ("definition of application group '{0}' unreadable" -f $groupName) }
            }
            foreach ($logicalName in @($members.Keys)) {
                if ($raw.IndexOf($logicalName, [StringComparison]::OrdinalIgnoreCase) -ge 0) { $members[$logicalName] += $groupName }
            }
        }
        return [pscustomobject]@{ State = 'complete'; GroupCount = $groups.Count; Members = $members; Groups = $identities.ToArray(); Detail = '' }
    } catch {
        return [pscustomobject]@{ State = 'unknown'; GroupCount = 0; Members = @{}; Groups = @(); Detail = [string]$_.Exception.Message }
    }
}

# --- Provider reads ------------------------------------------------------------

# The one seam for provider queries. The installer runs on the MECM server,
# so the site namespace is local; a failed query is an unknown result, never
# zero rows. Tests replace this function: the real Get-CimInstance binds
# -InputObject only as a CimInstance.
function Get-VsPreflightCim {
    param(
        [Parameter(Mandatory)][string]$SiteCode,
        [Parameter(Mandatory)][string]$ClassName,
        [string]$Filter,
        [string[]]$Property
    )
    $arguments = @{ Namespace = ('root/SMS/site_{0}' -f $SiteCode); ClassName = $ClassName; ErrorAction = 'Stop' }
    if ($Filter) { $arguments.Filter = $Filter }
    if ($Property) { $arguments.Property = $Property }
    Get-CimInstance @arguments
}

# Loads the lazy properties of exactly one selected instance.
function Get-VsPreflightCimFull {
    param([Parameter(Mandatory)]$InputObject)
    Get-CimInstance -InputObject $InputObject -ErrorAction Stop
}

# Rows whose integer key equals one of the IDs, in batches of OR conditions
# instead of reading the whole class: SMS_CIRelation also holds every
# software-update relation of the site.
function Get-VsPreflightCimByIds {
    param(
        [Parameter(Mandatory)][string]$SiteCode,
        [Parameter(Mandatory)][string]$ClassName,
        [Parameter(Mandatory)][string]$KeyName,
        [AllowEmptyCollection()][string[]]$Ids,
        [string[]]$Property
    )
    $list = @($Ids | Where-Object { $_ } | Sort-Object -Unique)
    foreach ($id in $list) {
        if ([string]$id -cnotmatch $script:VsPreflightCiIdPattern) { throw ("unexpected CI_ID '{0}'" -f $id) }
    }
    for ($offset = 0; $offset -lt $list.Count; $offset += $script:VsPreflightCimBatchSize) {
        $last = [Math]::Min($offset + $script:VsPreflightCimBatchSize, $list.Count) - 1
        $filter = (@($list[$offset..$last] | ForEach-Object { '{0} = {1}' -f $KeyName, $_ }) -join ' OR ')
        Get-VsPreflightCim -SiteCode $SiteCode -ClassName $ClassName -Filter $filter -Property $Property
    }
}

# All values of one property as text; a missing property or $null is none.
function Get-VsPreflightValues {
    param([AllowNull()]$Object, [Parameter(Mandatory)][string]$Name)
    if ($null -eq $Object -or -not $Object.PSObject.Properties[$Name] -or $null -eq $Object.$Name) { return }
    foreach ($value in @($Object.$Name)) {
        if ($null -ne $value -and [string]$value) { [string]$value }
    }
}

# The marker text behind one path; throws when it is missing. A seam of its
# own so tests can stand in for a UNC path.
function Read-VsSourceMarkerText {
    param([Parameter(Mandatory)][string]$LiteralPath)
    if (-not (Test-Path -LiteralPath $LiteralPath -PathType Leaf)) { throw 'marker missing' }
    return ([IO.File]::ReadAllText($LiteralPath)).TrimEnd("`r", "`n")
}

# --- Check rows ------------------------------------------------------------------

# One check result. fail and unknown block, unless site data cannot decide the
# point at all (-NotBlocking); warn and pass never block. A provider error
# without message text still leaves a reason.
function New-VsPreflightCheck {
    param(
        [Parameter(Mandatory)][ValidateSet('rbac_scope', 'source_mapping', 'references', 'deployment_policy')][string]$Check,
        [Parameter(Mandatory)][AllowEmptyString()][string]$Target,
        [Parameter(Mandatory)][ValidateSet('pass', 'fail', 'warn', 'unknown')][string]$Status,
        [Parameter(Mandatory)][string]$Code,
        [Parameter(Mandatory)][AllowEmptyString()][string]$Reason,
        [switch]$NotBlocking
    )
    if (-not $Reason) { $Reason = ('{0}: the provider returned no further detail' -f $Code) }
    $blocking = ($Status -ceq 'fail' -or $Status -ceq 'unknown') -and -not $NotBlocking
    [pscustomobject]@{ Check = $Check; Target = $Target; Status = $Status; Code = $Code; Reason = $Reason; Blocking = $blocking }
}

# --- source_mapping ----------------------------------------------------------------

# Equal marker text alone is only an identity hint: a copied directory carries
# the same file. The proof is the share: ContentShare names this computer, the
# share behind it points to PackagesBase, and the marker reads back equal on
# both paths.
function Get-VsSourceMappingCheck {
    param(
        [Parameter(Mandatory)][string]$PackagesBase,
        [Parameter(Mandatory)][string]$ContentShare,
        [AllowNull()][AllowEmptyCollection()][object[]]$Shares,
        [AllowEmptyString()][string]$ShareError = '',
        [AllowNull()][AllowEmptyCollection()][string[]]$ComputerNames,
        [bool]$MarkersConfirmed
    )
    $target = 'PackagesBase/ContentShare'
    if ($null -eq (ConvertFrom-VsUncPath -Path $ContentShare)) {
        return (New-VsPreflightCheck -Check 'source_mapping' -Target $target -Status 'unknown' -Code 'source_mapping_unknown' -Reason ("ContentShare '{0}' is no UNC path that maps to a share" -f $ContentShare))
    }
    $names = @($ComputerNames | Where-Object { $_ })
    if ($names.Count -eq 0) {
        return (New-VsPreflightCheck -Check 'source_mapping' -Target $target -Status 'unknown' -Code 'source_mapping_unknown' -Reason 'The names of this computer are not readable')
    }
    $mapping = Get-VsLocalPathForUnc -Path $ContentShare -LocalShares @($Shares) -LocalNames $names
    if (-not $mapping.IsLocal) {
        return (New-VsPreflightCheck -Check 'source_mapping' -Target $target -Status 'unknown' -Code 'source_mapping_unknown' -Reason ("Host '{0}' is not this computer ({1}); the share cannot be mapped to PackagesBase from here" -f $mapping.Host, ($names -join ', ')))
    }
    if ($ShareError) {
        return (New-VsPreflightCheck -Check 'source_mapping' -Target $target -Status 'unknown' -Code 'source_mapping_unknown' -Reason ("The local shares are not readable: {0}" -f $ShareError))
    }
    if (-not $mapping.ShareMatch) {
        return (New-VsPreflightCheck -Check 'source_mapping' -Target $target -Status 'fail' -Code 'source_mapping_mismatch' -Reason ("Share '{0}' does not exist on this computer" -f $mapping.Share))
    }
    $localPath = ([string]$mapping.LocalPath).TrimEnd('\')
    if ($localPath -ine $PackagesBase.TrimEnd('\')) {
        return (New-VsPreflightCheck -Check 'source_mapping' -Target $target -Status 'fail' -Code 'source_mapping_mismatch' -Reason ("ContentShare leads to '{0}' through share '{1}', but PackagesBase is '{2}'" -f $localPath, $mapping.ShareMatch, $PackagesBase))
    }
    if (-not $MarkersConfirmed) {
        return (New-VsPreflightCheck -Check 'source_mapping' -Target $target -Status 'unknown' -Code 'source_mapping_unknown' -Reason ("Share '{0}' maps to PackagesBase, but the source identity marker is not confirmed on both paths" -f $mapping.ShareMatch))
    }
    return (New-VsPreflightCheck -Check 'source_mapping' -Target $target -Status 'pass' -Code 'source_mapping_verified' -Reason ("Host '{0}' is this computer, share '{1}' leads to '{2}' = PackagesBase, and the marker reads back equal on both paths" -f $mapping.Host, $mapping.ShareMatch, $localPath))
}

# --- rbac_scope ------------------------------------------------------------------

# The SMS_Admin rows of the caller (own SID and group SIDs). AdminSid carries
# the lazy qualifier; the site returned it on enumeration, a provider that
# does not is read per row. Categories, Roles and Permissions are lazy and
# loaded for the matched rows only.
function Read-VsCallerAdminScope {
    param([Parameter(Mandatory)][string]$SiteCode, [AllowNull()][AllowEmptyCollection()][string[]]$CurrentSids)
    $result = [pscustomobject]@{ State = 'unknown'; Detail = ''; Logons = @(); ScopeIds = @(); CollectionIds = @(); CollectionNames = @(); RoleNames = @() }
    $sids = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($sid in @($CurrentSids)) { if ($sid) { [void]$sids.Add([string]$sid) } }
    if ($sids.Count -eq 0) { $result.Detail = 'The SIDs of the caller are not readable'; return $result }
    try {
        $matched = New-Object System.Collections.Generic.List[object]
        foreach ($row in @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_Admin')) {
            $current = $row
            $isFull = $false
            $sid = Get-VsObjectPropertyText -Object $row -Names @('AdminSid')
            if (-not $sid) {
                $current = Get-VsPreflightCimFull -InputObject $row
                $isFull = $true
                $sid = Get-VsObjectPropertyText -Object $current -Names @('AdminSid')
            }
            if (-not $sid -or -not $sids.Contains($sid)) { continue }
            if (-not $isFull) { $current = Get-VsPreflightCimFull -InputObject $row }
            $matched.Add($current)
        }
        if ($matched.Count -eq 0) { $result.Detail = 'No SMS_Admin row matches the caller or one of its groups'; return $result }
        $scopes = New-Object System.Collections.Generic.List[string]
        $collections = New-Object System.Collections.Generic.List[string]
        foreach ($admin in $matched) {
            foreach ($id in @(Get-VsPreflightValues -Object $admin -Name 'Categories')) { $scopes.Add($id) }
            foreach ($permission in @($(if ($admin.PSObject.Properties['Permissions']) { $admin.Permissions }))) {
                if ($null -eq $permission) { continue }
                $categoryId = Get-VsObjectPropertyText -Object $permission -Names @('CategoryID')
                $typeId = Get-VsObjectPropertyText -Object $permission -Names @('CategoryTypeID')
                if (-not $categoryId) { continue }
                if ($typeId -ceq '29') { $scopes.Add($categoryId) }
                elseif ($typeId -ceq '1') { $collections.Add($categoryId) }
            }
        }
        $result.Logons = @($matched | ForEach-Object { Get-VsObjectPropertyText -Object $_ -Names @('LogonName') } | Where-Object { $_ } | Sort-Object -Unique)
        $result.ScopeIds = @($scopes | Sort-Object -Unique)
        $result.CollectionIds = @($collections | Sort-Object -Unique)
        $result.CollectionNames = @($matched | ForEach-Object { Get-VsPreflightValues -Object $_ -Name 'CollectionNames' } | Sort-Object -Unique)
        $result.RoleNames = @($matched | ForEach-Object { Get-VsPreflightValues -Object $_ -Name 'RoleNames' } | Sort-Object -Unique)
        if ($result.ScopeIds.Count -eq 0) { $result.Detail = 'The security scopes of the caller are not readable'; return $result }
        $result.State = 'complete'
    } catch {
        $result.Detail = [string]$_.Exception.Message
    }
    return $result
}

# Security scope IDs of one object from SMS_SecuredCategoryMembership.
function Read-VsObjectScopeIds {
    param([Parameter(Mandatory)][string]$SiteCode, [Parameter(Mandatory)][string]$Name, [AllowEmptyString()][string]$ObjectKey)
    $result = [pscustomobject]@{ Name = $Name; State = 'unknown'; Detail = ''; ScopeIds = @() }
    if ($ObjectKey -cnotmatch $script:VsPreflightKeyPattern) { $result.Detail = ("Unexpected object key '{0}'" -f $ObjectKey); return $result }
    try {
        $ids = @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_SecuredCategoryMembership' -Filter ('ObjectKey = {0}' -f (ConvertTo-VsWqlString -Value $ObjectKey)) |
            ForEach-Object { Get-VsObjectPropertyText -Object $_ -Names @('CategoryID') } | Where-Object { $_ } | Sort-Object -Unique)
        if ($ids.Count -eq 0) { $result.Detail = 'No security scope membership is readable for this application'; return $result }
        $result.ScopeIds = $ids
        $result.State = 'complete'
    } catch {
        $result.Detail = [string]$_.Exception.Message
    }
    return $result
}

# A collection and its limiting chain up to the root. A cycle or an overlong
# chain ends as unknown instead of looping.
function Read-VsCollectionChain {
    param([Parameter(Mandatory)][string]$SiteCode, [Parameter(Mandatory)][string]$CollectionId)
    $result = [pscustomobject]@{ CollectionId = $CollectionId; State = 'unknown'; Detail = ''; Chain = @() }
    $chain = New-Object System.Collections.Generic.List[object]
    $visited = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    $id = $CollectionId
    try {
        for ($step = 0; $step -lt $script:VsPreflightMaxLimitingChain; $step++) {
            if (-not $id) {
                $result.Chain = $chain.ToArray()
                $result.State = 'complete'
                return $result
            }
            if ($id -cnotmatch $script:VsPreflightIdPattern) { throw ("unexpected collection ID '{0}'" -f $id) }
            if (-not $visited.Add($id)) { throw ('the limiting chain returns to {0}' -f $id) }
            $rows = @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_Collection' -Filter ('CollectionID = {0}' -f (ConvertTo-VsWqlString -Value $id)) -Property @('CollectionID', 'Name', 'LimitToCollectionID'))
            if ($rows.Count -ne 1) { throw ('collection {0} is not visible' -f $id) }
            $chain.Add([pscustomobject]@{ Id = $id; Name = (Get-VsObjectPropertyText -Object $rows[0] -Names @('Name')) })
            $id = Get-VsObjectPropertyText -Object $rows[0] -Names @('LimitToCollectionID')
        }
        throw ('the limiting chain is longer than {0} collections' -f $script:VsPreflightMaxLimitingChain)
    } catch {
        $result.Chain = $chain.ToArray()
        $result.Detail = [string]$_.Exception.Message
    }
    return $result
}

# Pure decision over the read RBAC evidence. An application counts as covered
# when the caller holds scope All or one of its scopes; a collection when the
# caller holds it or a collection of its limiting chain. Zero visible objects
# prove absence only when the caller sees every scope of the site.
function Get-VsRbacChecks {
    param(
        [Parameter(Mandatory)]$Admin,
        [AllowEmptyCollection()][object[]]$Applications = @(),
        [AllowEmptyCollection()][object[]]$Collections = @(),
        [AllowNull()]$SiteScopes,
        [AllowEmptyCollection()][string[]]$AbsentApplications = @()
    )
    $absent = @($AbsentApplications | Where-Object { $_ })
    if ([string]$Admin.State -cne 'complete') {
        New-VsPreflightCheck -Check 'rbac_scope' -Target 'caller' -Status 'unknown' -Code 'rbac_admin_unknown' -Reason ([string]$Admin.Detail)
        foreach ($name in $absent) {
            New-VsPreflightCheck -Check 'rbac_scope' -Target $name -Status 'unknown' -Code 'application_absence_unverified' -Reason 'Not visible, and the RBAC view of the caller is not readable; zero objects do not prove absence'
        }
        return
    }
    $held = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($id in @($Admin.ScopeIds)) { [void]$held.Add([string]$id) }
    $holdsAll = $held.Contains($script:VsPreflightAllScopeId)
    $logons = @($Admin.Logons) -join ', '
    $heldText = @($Admin.ScopeIds) -join ', '
    $viewComplete = $false
    if ($holdsAll) {
        $viewComplete = $true
        New-VsPreflightCheck -Check 'rbac_scope' -Target 'caller' -Status 'pass' -Code 'rbac_view_complete' -Reason ("Scope All ({0}) through {1}" -f $script:VsPreflightAllScopeId, $logons)
    } elseif ($null -eq $SiteScopes -or [string]$SiteScopes.State -cne 'complete') {
        $detail = if ($null -eq $SiteScopes) { 'not read' } else { [string]$SiteScopes.Detail }
        New-VsPreflightCheck -Check 'rbac_scope' -Target 'caller' -Status 'unknown' -Code 'rbac_view_unknown' -NotBlocking -Reason ("The caller does not hold scope All and the scopes of the site are not readable: {0}" -f $detail)
    } else {
        $missing = @($SiteScopes.Scopes | Where-Object { -not $held.Contains([string]$_.Id) } | ForEach-Object { '{0} ({1})' -f $_.Id, $_.Name })
        if ($missing.Count -eq 0) {
            $viewComplete = $true
            New-VsPreflightCheck -Check 'rbac_scope' -Target 'caller' -Status 'pass' -Code 'rbac_view_complete' -Reason ("Every security scope of the site ({0}) through {1}" -f $heldText, $logons)
        } else {
            New-VsPreflightCheck -Check 'rbac_scope' -Target 'caller' -Status 'warn' -Code 'rbac_view_partial' -Reason ("Scope(s) {0} lie outside the view of {1}; objects there stay invisible, reverse references included" -f ($missing -join ', '), $logons)
        }
    }
    foreach ($application in $Applications) {
        $name = [string]$application.Name
        if ([string]$application.State -cne 'complete') {
            New-VsPreflightCheck -Check 'rbac_scope' -Target $name -Status 'unknown' -Code 'rbac_scope_unknown' -Reason ([string]$application.Detail)
            continue
        }
        $scopes = @($application.ScopeIds)
        $shared = @($scopes | Where-Object { $held.Contains([string]$_) })
        if ($holdsAll -or $shared.Count -gt 0) {
            New-VsPreflightCheck -Check 'rbac_scope' -Target $name -Status 'pass' -Code 'rbac_scope_covered' -Reason ("Application scope(s) {0}; the caller holds {1}" -f ($scopes -join ', '), $heldText)
        } else {
            New-VsPreflightCheck -Check 'rbac_scope' -Target $name -Status 'fail' -Code 'rbac_scope_uncovered' -Reason ("Application scope(s) {0}; the caller holds only {1}" -f ($scopes -join ', '), $heldText)
        }
    }
    $collectionIds = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($id in @($Admin.CollectionIds)) { [void]$collectionIds.Add([string]$id) }
    $collectionNames = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($collectionName in @($Admin.CollectionNames)) { [void]$collectionNames.Add([string]$collectionName) }
    foreach ($collection in $Collections) {
        $id = [string]$collection.CollectionId
        if ([string]$collection.State -cne 'complete') {
            New-VsPreflightCheck -Check 'rbac_scope' -Target $id -Status 'unknown' -Code 'rbac_collection_unknown' -Reason ([string]$collection.Detail)
            continue
        }
        $path = @($collection.Chain | ForEach-Object { [string]$_.Id }) -join ' -> '
        $hit = @($collection.Chain | Where-Object { $collectionIds.Contains([string]$_.Id) -or $collectionNames.Contains([string]$_.Name) } | Select-Object -First 1)
        if ($hit.Count -gt 0) {
            New-VsPreflightCheck -Check 'rbac_scope' -Target $id -Status 'pass' -Code 'rbac_collection_covered' -Reason ("Limiting chain {0}; the caller holds {1} ({2})" -f $path, $hit[0].Id, $hit[0].Name)
        } else {
            New-VsPreflightCheck -Check 'rbac_scope' -Target $id -Status 'fail' -Code 'rbac_collection_uncovered' -Reason ("No collection of the limiting chain {0} is assigned to the caller" -f $path)
        }
    }
    foreach ($name in $absent) {
        if ($viewComplete) {
            New-VsPreflightCheck -Check 'rbac_scope' -Target $name -Status 'pass' -Code 'application_absent' -Reason 'Not visible although the caller sees every security scope; the application does not exist'
        } else {
            New-VsPreflightCheck -Check 'rbac_scope' -Target $name -Status 'unknown' -Code 'application_absence_unverified' -Reason 'Not visible, and the caller does not see every security scope; zero objects do not prove absence'
        }
    }
}

# --- references --------------------------------------------------------------------

# Every revision of the target applications and of their deployment types.
# SMS_Application holds all revisions; expired ones still carry relations.
function Read-VsTargetRevisions {
    param([Parameter(Mandatory)][string]$SiteCode, [AllowEmptyCollection()][object[]]$Targets = @())
    $applications = New-Object System.Collections.Generic.List[object]
    $deploymentTypes = New-Object System.Collections.Generic.List[object]
    $problems = New-Object System.Collections.Generic.List[string]
    foreach ($target in $Targets) {
        $model = [string]$target.Model
        if ($model -cnotmatch $script:VsPreflightKeyPattern) { $problems.Add(("{0}: unexpected ModelName" -f $target.Name)); continue }
        try {
            foreach ($row in @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_Application' -Filter ('ModelName = {0}' -f (ConvertTo-VsWqlString -Value $model)) -Property @('CI_ID', 'CIVersion', 'IsLatest', 'IsExpired', 'LocalizedDisplayName', 'ModelName'))) {
                $applications.Add((ConvertTo-VsPreflightRevision -Row $row -ModelProperty 'ModelName' -Target $target))
            }
            foreach ($row in @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_DeploymentType' -Filter ('AppModelName = {0}' -f (ConvertTo-VsWqlString -Value $model)) -Property @('CI_ID', 'CIVersion', 'IsLatest', 'IsExpired', 'LocalizedDisplayName', 'AppModelName'))) {
                $deploymentTypes.Add((ConvertTo-VsPreflightRevision -Row $row -ModelProperty 'AppModelName' -Target $target))
            }
        } catch {
            $problems.Add(('{0}: {1}' -f $target.Name, [string]$_.Exception.Message))
        }
        # The latest CI_ID from the CM cmdlet always belongs to the set.
        if ($target.CiId -and @($applications | Where-Object { $_.CiId -ceq [string]$target.CiId }).Count -eq 0) {
            $applications.Add([pscustomobject]@{ CiId = [string]$target.CiId; ModelName = $model; Name = [string]$target.Name; Version = ''; IsLatest = $true; Target = [string]$target.Name })
        }
    }
    return [pscustomobject]@{
        State = $(if ($problems.Count -eq 0) { 'complete' } else { 'unknown' })
        Detail = ($problems -join '; ')
        Applications = $applications.ToArray()
        DeploymentTypes = $deploymentTypes.ToArray()
    }
}

function ConvertTo-VsPreflightRevision {
    param([Parameter(Mandatory)]$Row, [Parameter(Mandatory)][string]$ModelProperty, $Target)
    [pscustomobject]@{
        CiId = Get-VsObjectPropertyText -Object $Row -Names @('CI_ID')
        ModelName = Get-VsObjectPropertyText -Object $Row -Names @($ModelProperty)
        Name = Get-VsObjectPropertyText -Object $Row -Names @('LocalizedDisplayName')
        Version = Get-VsObjectPropertyText -Object $Row -Names @('CIVersion')
        IsLatest = ((Get-VsObjectPropertyText -Object $Row -Names @('IsLatest')) -ceq 'True')
        Target = $(if ($Target) { [string]$Target.Name } else { '' })
    }
}

# Resolves the CI on the other side of a relation: an application revision,
# a deployment type (owner by AppModelName) or an application group. Results
# are cached per CI_ID; an unresolvable CI returns Kind ''.
function Resolve-VsPreflightCi {
    param([Parameter(Mandatory)][string]$SiteCode, [Parameter(Mandatory)][string]$CiId, [Parameter(Mandatory)][hashtable]$Cache)
    if ($Cache.ContainsKey($CiId)) { return $Cache[$CiId] }
    if ($CiId -cnotmatch $script:VsPreflightCiIdPattern) { throw ("unexpected CI_ID '{0}'" -f $CiId) }
    $resolved = [pscustomobject]@{ Kind = ''; CiId = $CiId; ModelName = ''; Name = ''; Version = ''; IsLatest = $false }
    $filter = 'CI_ID = {0}' -f $CiId
    $rows = @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_Application' -Filter $filter -Property @('CI_ID', 'CIVersion', 'IsLatest', 'IsExpired', 'LocalizedDisplayName', 'ModelName'))
    if ($rows.Count -gt 0) {
        $revision = ConvertTo-VsPreflightRevision -Row $rows[0] -ModelProperty 'ModelName'
        $resolved = [pscustomobject]@{ Kind = 'application'; CiId = $CiId; ModelName = $revision.ModelName; Name = $revision.Name; Version = $revision.Version; IsLatest = $revision.IsLatest }
    } else {
        $rows = @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_DeploymentType' -Filter $filter -Property @('CI_ID', 'CIVersion', 'IsLatest', 'IsExpired', 'LocalizedDisplayName', 'AppModelName'))
        if ($rows.Count -gt 0) {
            $revision = ConvertTo-VsPreflightRevision -Row $rows[0] -ModelProperty 'AppModelName'
            $resolved = [pscustomobject]@{ Kind = 'deployment-type'; CiId = $CiId; ModelName = $revision.ModelName; Name = $revision.Name; Version = $revision.Version; IsLatest = $revision.IsLatest }
        } else {
            $rows = @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_ApplicationGroup' -Filter $filter -Property @('CI_ID', 'IsLatest', 'LocalizedDisplayName', 'ModelName'))
            if ($rows.Count -gt 0) {
                $revision = ConvertTo-VsPreflightRevision -Row $rows[0] -ModelProperty 'ModelName'
                $resolved = [pscustomobject]@{ Kind = 'application-group'; CiId = $CiId; ModelName = $revision.ModelName; Name = $revision.Name; Version = ''; IsLatest = $revision.IsLatest }
            }
        }
    }
    $Cache[$CiId] = $resolved
    return $resolved
}

# Reverse dependencies from SMS_AppDependenceRelation: every relation whose
# ToApplicationCIID is a revision of a target application. Inside the client
# chain a latest revision passes and an expired one warns; any other latest
# application depending on a target blocks.
function Get-VsDependenceRelationChecks {
    param(
        [Parameter(Mandatory)][string]$SiteCode,
        [Parameter(Mandatory)]$Revisions,
        [Parameter(Mandatory)][hashtable]$Cache
    )
    $checks = New-Object System.Collections.Generic.List[object]
    $inventory = New-Object System.Collections.Generic.List[object]
    $reported = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    $byCi = @{}
    foreach ($revision in @($Revisions.Applications)) { if ($revision.CiId) { $byCi[[string]$revision.CiId] = $revision } }
    $targetModels = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($revision in @($Revisions.Applications)) { if ($revision.ModelName) { [void]$targetModels.Add([string]$revision.ModelName) } }
    $relations = @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_AppDependenceRelation')
    foreach ($relation in $relations) {
        $toId = Get-VsObjectPropertyText -Object $relation -Names @('ToApplicationCIID')
        if (-not $toId -or -not $byCi.ContainsKey($toId)) { continue }
        $to = $byCi[$toId]
        $fromId = Get-VsObjectPropertyText -Object $relation -Names @('FromApplicationCIID')
        $from = $null
        if ($fromId -and $byCi.ContainsKey($fromId)) { $from = $byCi[$fromId] }
        elseif ($fromId) {
            $resolved = Resolve-VsPreflightCi -SiteCode $SiteCode -CiId $fromId -Cache $Cache
            if ($resolved.Kind -ceq 'application') { $from = $resolved }
        }
        $target = [string]$to.Target
        $typeFlag = Get-VsObjectPropertyText -Object $relation -Names @('TypeFlag')
        if ($null -eq $from) {
            $checks.Add((New-VsPreflightCheck -Check 'references' -Target $target -Status 'unknown' -Code 'references_unknown' -Reason ("Application CI {0} depends on {1}, but cannot be resolved" -f $fromId, $target)))
            continue
        }
        $inventory.Add([pscustomobject]@{
            Kind = 'reverse-dependency'; Name = $target; Identity = $fromId
            FromName = [string]$from.Name; FromVersion = [string]$from.Version; FromLatest = [bool]$from.IsLatest
            FromDeploymentTypeId = (Get-VsObjectPropertyText -Object $relation -Names @('FromDeploymentTypeCIID'))
            ToDeploymentTypeId = (Get-VsObjectPropertyText -Object $relation -Names @('ToDeploymentTypeCIID'))
            TypeFlag = $typeFlag
        })
        $label = "{0} (revision {1})" -f $from.Name, $from.Version
        if ($targetModels.Contains([string]$from.ModelName)) {
            if ($from.IsLatest) {
                $checks.Add((New-VsPreflightCheck -Check 'references' -Target $target -Status 'pass' -Code 'reverse_dependency_internal' -Reason ("{0} depends on {1} inside the client chain" -f $label, $target)))
            } else {
                $checks.Add((New-VsPreflightCheck -Check 'references' -Target $target -Status 'warn' -Code 'reverse_dependency_old_revision' -Reason ("Old revision {0} still depends on {1}; it installs nothing, but MECM keeps the reference" -f $label, $target)))
            }
            continue
        }
        [void]$reported.Add(('{0}|{1}' -f $from.ModelName, $to.ModelName))
        if ($from.IsLatest) {
            $checks.Add((New-VsPreflightCheck -Check 'references' -Target $target -Status 'fail' -Code 'reverse_dependency_foreign' -Reason ("{0} outside the client chain depends on {1}" -f $label, $target)))
        } else {
            $checks.Add((New-VsPreflightCheck -Check 'references' -Target $target -Status 'warn' -Code 'reverse_dependency_old_revision' -Reason ("Old revision {0} outside the client chain still depends on {1}" -f $label, $target)))
        }
    }
    $inventory.Add([pscustomobject]@{ Kind = 'reverse-dependency-scan'; Name = 'all-visible'; Count = $relations.Count })
    return [pscustomobject]@{ Checks = $checks.ToArray(); Inventory = $inventory.ToArray(); Reported = $reported }
}

# Generic CI relations pointing at a revision of a target application or of
# its deployment types: supersedence, group membership and anything else the
# dependence class does not show. The relation type is reported as a number;
# the preflight does not interpret it.
function Get-VsCiRelationChecks {
    param(
        [Parameter(Mandatory)][string]$SiteCode,
        [Parameter(Mandatory)]$Revisions,
        [Parameter(Mandatory)][hashtable]$Cache,
        [AllowNull()]$Reported
    )
    $checks = New-Object System.Collections.Generic.List[object]
    $inventory = New-Object System.Collections.Generic.List[object]
    $own = @{}
    foreach ($revision in @(@($Revisions.Applications) + @($Revisions.DeploymentTypes))) { if ($revision.CiId) { $own[[string]$revision.CiId] = $revision } }
    $targetModels = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($revision in @($own.Values)) { if ($revision.ModelName) { [void]$targetModels.Add([string]$revision.ModelName) } }
    $rows = @(Get-VsPreflightCimByIds -SiteCode $SiteCode -ClassName 'SMS_CIRelation' -KeyName 'ToCIID' -Ids @($own.Keys))
    foreach ($row in $rows) {
        $toId = Get-VsObjectPropertyText -Object $row -Names @('ToCIID')
        if (-not $own.ContainsKey($toId)) { continue }
        $to = $own[$toId]
        $target = [string]$to.Target
        $fromId = Get-VsObjectPropertyText -Object $row -Names @('FromCIID')
        $type = Get-VsObjectPropertyText -Object $row -Names @('RelationType')
        if ((Get-VsObjectPropertyText -Object $row -Names @('IsBroken')) -ceq 'True') {
            $checks.Add((New-VsPreflightCheck -Check 'references' -Target $target -Status 'warn' -Code 'ci_relation_broken' -Reason ("Relation type {0} from CI {1} to CI {2} of {3} is marked broken" -f $type, $fromId, $toId, $target)))
        }
        if (-not $fromId -or $own.ContainsKey($fromId)) { continue }
        $from = Resolve-VsPreflightCi -SiteCode $SiteCode -CiId $fromId -Cache $Cache
        if ($from.Kind -and $targetModels.Contains([string]$from.ModelName)) { continue }
        $inventory.Add([pscustomobject]@{ Kind = 'ci-relation'; Name = $target; Identity = $fromId; ToCiId = $toId; RelationType = $type; FromKind = [string]$from.Kind; FromName = [string]$from.Name })
        if (-not $from.Kind) {
            $checks.Add((New-VsPreflightCheck -Check 'references' -Target $target -Status 'unknown' -Code 'references_unknown' -Reason ("CI {0} has a type {1} relation to {2}, but is neither an application, a deployment type nor an application group" -f $fromId, $type, $target)))
            continue
        }
        if ($null -ne $Reported -and $Reported.Contains(('{0}|{1}' -f $from.ModelName, $to.ModelName))) { continue }
        if ($from.Kind -ceq 'application-group') {
            $checks.Add((New-VsPreflightCheck -Check 'references' -Target $target -Status 'fail' -Code 'application_group_member' -Reason ("Application group '{0}' has a type {1} relation to {2}; a group must not orchestrate the chain in parallel" -f $from.Name, $type, $target)))
            continue
        }
        $label = "{0} '{1}' (revision {2})" -f $from.Kind, $from.Name, $from.Version
        if ($from.IsLatest) {
            $checks.Add((New-VsPreflightCheck -Check 'references' -Target $target -Status 'fail' -Code 'reverse_relation_foreign' -Reason ("{0} outside the client chain has a type {1} relation to {2}" -f $label, $type, $target)))
        } else {
            $checks.Add((New-VsPreflightCheck -Check 'references' -Target $target -Status 'warn' -Code 'reverse_relation_old_revision' -Reason ("Old {0} outside the client chain still has a type {1} relation to {2}" -f $label, $type, $target)))
        }
    }
    $inventory.Add([pscustomobject]@{ Kind = 'ci-relation-scan'; Name = 'all-visible'; Count = $rows.Count })
    return [pscustomobject]@{ Checks = $checks.ToArray(); Inventory = $inventory.ToArray() }
}

# --- deployment_policy ---------------------------------------------------------

# The client phase an application name belongs to: the spec name itself, or
# the spec name (for the first phase also a legacy name) followed by a
# separator and a suffix, such as a versioned copy. Case-insensitive, as MECM
# compares application names.
function Get-VsClientPhaseFamily {
    param(
        [AllowEmptyString()][string]$ApplicationName,
        [Parameter(Mandatory)][object[]]$Specs,
        [AllowEmptyCollection()][string[]]$LegacyNames = @()
    )
    if (-not $ApplicationName) { return '' }
    foreach ($spec in $Specs) {
        $stems = @([string]$spec.AppName)
        if ($null -eq $spec.DependsOn) { $stems += @($LegacyNames) }
        foreach ($stem in @($stems | Where-Object { $_ })) {
            if ($ApplicationName -ieq $stem) { return [string]$spec.AppName }
            if ($ApplicationName.Length -gt ($stem.Length + 1) -and
                $ApplicationName.StartsWith($stem, [StringComparison]::OrdinalIgnoreCase) -and
                '_-. '.IndexOf($ApplicationName[$stem.Length]) -ge 0) {
                return [string]$spec.AppName
            }
        }
    }
    return ''
}

# One deployment row with the SMS_ApplicationAssignment fields the policy
# check reads, from a Get-CMApplicationDeployment result or a CIM row.
function ConvertTo-VsDeploymentRow {
    param(
        [Parameter(Mandatory)]$Deployment,
        [Parameter(Mandatory)][AllowEmptyString()][string]$QueriedName,
        [Parameter(Mandatory)][string]$Source
    )
    $applicationName = Get-VsObjectPropertyText -Object $Deployment -Names @('ApplicationName')
    if (-not $applicationName) { $applicationName = $QueriedName }
    [pscustomobject]@{
        Name = $applicationName
        QueriedName = $QueriedName
        Source = $Source
        Family = ''
        AssignmentId = Get-VsObjectPropertyText -Object $Deployment -Names @('AssignmentID', 'AssignmentId')
        CollectionId = Get-VsObjectPropertyText -Object $Deployment -Names @('TargetCollectionID', 'CollectionID', 'CollectionId')
        CollectionName = Get-VsObjectPropertyText -Object $Deployment -Names @('CollectionName')
        OfferTypeId = Get-VsObjectPropertyText -Object $Deployment -Names @('OfferTypeID')
        DesiredConfigType = Get-VsObjectPropertyText -Object $Deployment -Names @('DesiredConfigType')
        OverrideServiceWindows = Get-VsObjectPropertyText -Object $Deployment -Names @('OverrideServiceWindows')
        RebootOutsideOfServiceWindows = Get-VsObjectPropertyText -Object $Deployment -Names @('RebootOutsideOfServiceWindows')
        Enabled = Get-VsObjectPropertyText -Object $Deployment -Names @('Enabled')
        Deadline = Get-VsObjectPropertyText -Object $Deployment -Names @('EnforcementDeadline')
        SoftDeadlineEnabled = Get-VsObjectPropertyText -Object $Deployment -Names @('SoftDeadlineEnabled')
        OfferFlags = Get-VsObjectPropertyText -Object $Deployment -Names @('OfferFlags')
        RequireApproval = Get-VsObjectPropertyText -Object $Deployment -Names @('RequireApproval')
    }
}

# Maximum runtime of one managed phase from its deployment-type definition;
# the drift decision is the one of Get-VsClientDeploymentTypeContractIssues.
function Get-VsClientRuntimeState {
    param([Parameter(Mandatory)]$Spec, $DeploymentType, [AllowEmptyCollection()][string[]]$Issues = @(), [string]$Detail = '')
    $row = [pscustomobject]@{ Name = [string]$Spec.AppName; Expected = [string]$Spec.MaximumRuntimeMins; State = 'unknown'; Minutes = ''; Detail = $Detail }
    if ($null -eq $DeploymentType) { return $row }
    if ($Issues -contains 'definition-unreadable' -or $Issues -contains 'definition-invalid-xml') {
        $row.Detail = 'The deployment-type definition is not readable'
        return $row
    }
    [xml]$xml = Get-VsObjectPropertyText -Object $DeploymentType -Names @('SDMPackageXML')
    $values = @(Get-VsDeploymentTypeMaximumRuntimeArgs -Xml $xml)
    if ($values.Count -eq 1) { $row.Minutes = $values[0] }
    if ($Issues -notcontains 'maximum-runtime') { $row.State = 'ok'; return $row }
    if ($values.Count -eq 1) { $row.State = 'drift'; return $row }
    $row.Detail = ('The install action carries {0} MaxExecuteTime values instead of one' -f $values.Count)
    return $row
}

# Maintenance windows of one collection: SMS_CollectionSettings.ServiceWindows
# is lazy and loaded per settings row. A collection without a settings row has
# no window. Duration and IsEnabled are SMS_ServiceWindow fields; the surveyed
# site had no window, so their values are read tolerantly.
function Read-VsCollectionServiceWindows {
    param([Parameter(Mandatory)][string]$SiteCode, [Parameter(Mandatory)][string]$CollectionId)
    $result = [pscustomobject]@{ CollectionId = $CollectionId; State = 'unknown'; Detail = ''; Count = 0; LongestMinutes = '' }
    try {
        if ($CollectionId -cnotmatch $script:VsPreflightIdPattern) { throw ("unexpected collection ID '{0}'" -f $CollectionId) }
        $windows = New-Object System.Collections.Generic.List[object]
        foreach ($settings in @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_CollectionSettings' -Filter ('CollectionID = {0}' -f (ConvertTo-VsWqlString -Value $CollectionId)))) {
            $full = Get-VsPreflightCimFull -InputObject $settings
            foreach ($window in @($(if ($full.PSObject.Properties['ServiceWindows']) { $full.ServiceWindows }))) {
                if ($null -ne $window -and (Get-VsObjectPropertyText -Object $window -Names @('IsEnabled')) -cne 'False') { $windows.Add($window) }
            }
        }
        $durations = @($windows | ForEach-Object { Get-VsObjectPropertyText -Object $_ -Names @('Duration') } | Where-Object { $_ -cmatch '\A[0-9]{1,6}\z' } | ForEach-Object { [int]$_ })
        $result.Count = $windows.Count
        if ($durations.Count -eq $windows.Count -and $durations.Count -gt 0) { $result.LongestMinutes = [string](($durations | Measure-Object -Maximum).Maximum) }
        $result.State = 'complete'
    } catch {
        $result.Detail = [string]$_.Exception.Message
    }
    return $result
}

function Get-VsWindowFlagText {
    param([AllowEmptyString()][string]$Value)
    if ($Value -ceq 'True') { return 'allowed' }
    if ($Value -ceq 'False') { return 'not allowed' }
    return 'unknown'
}

# Pure decision over the deployment rows. Q1 variant A (15.10.2026): the
# installer-managed Core deployment must install and restart outside
# maintenance windows, otherwise it blocks; an existing manual deployment is
# only reported. Two Required versions of one phase on one collection block.
# Implicit uninstall and simulation have no own assignment field and stay a
# non-blocking unknown.
function Get-VsDeploymentPolicyChecks {
    param(
        [AllowEmptyCollection()][object[]]$Deployments = @(),
        [AllowEmptyString()][string]$EntryName = '',
        [ValidateSet('managed', 'manual', 'unknown')][string]$EntryOwnership = 'unknown',
        [AllowEmptyCollection()][object[]]$Windows = @(),
        [AllowEmptyCollection()][object[]]$Runtimes = @()
    )
    $managed = New-Object System.Collections.Generic.List[object]
    foreach ($deployment in $Deployments) {
        $name = [string]$deployment.Name
        $label = 'Assignment {0} of {1} on {2}' -f $deployment.AssignmentId, $name, $deployment.CollectionId
        $isEntry = $EntryName -and $name -ieq $EntryName
        if ($isEntry -and $EntryOwnership -ceq 'managed') {
            $managed.Add($deployment)
            $missing = @(@('AssignmentId', 'CollectionId', 'OfferTypeId', 'DesiredConfigType', 'OverrideServiceWindows', 'RebootOutsideOfServiceWindows', 'Enabled', 'SoftDeadlineEnabled', 'RequireApproval') |
                Where-Object { -not [string]$deployment.$_ })
            if ($missing.Count -gt 0) {
                New-VsPreflightCheck -Check 'deployment_policy' -Target $name -Status 'unknown' -Code 'core_deployment_fields_unknown' -Reason ("{0}: fields {1} are not readable" -f $label, ($missing -join ', '))
                continue
            }
            $drift = New-Object System.Collections.Generic.List[string]
            if ([string]$deployment.OfferTypeId -cne '0') { $drift.Add(('purpose is not Required (OfferTypeID {0})' -f $deployment.OfferTypeId)) }
            if ([string]$deployment.DesiredConfigType -cne '1') { $drift.Add(('not an install deployment (DesiredConfigType {0})' -f $deployment.DesiredConfigType)) }
            if ([string]$deployment.Enabled -cne 'True') { $drift.Add('disabled') }
            if ([string]$deployment.OverrideServiceWindows -cne 'True') { $drift.Add('no installation outside maintenance windows') }
            if ([string]$deployment.RebootOutsideOfServiceWindows -cne 'True') { $drift.Add('no restart outside maintenance windows') }
            if ([string]$deployment.SoftDeadlineEnabled -ceq 'True') { $drift.Add('soft deadline lets users postpone enforcement') }
            if ([string]$deployment.RequireApproval -ceq 'True') { $drift.Add('requires approval') }
            if (-not [string]$deployment.Deadline) { $drift.Add('no enforcement deadline') }
            if ($drift.Count -gt 0) {
                New-VsPreflightCheck -Check 'deployment_policy' -Target $name -Status 'fail' -Code 'core_deployment_policy_drift' -Reason ("{0}: {1}; the managed Core deployment must be a Required install that installs and restarts outside maintenance windows (Q1 variant A)" -f $label, ($drift -join '; '))
            } else {
                New-VsPreflightCheck -Check 'deployment_policy' -Target $name -Status 'pass' -Code 'core_deployment_policy_ok' -Reason ("{0}: Required install, enabled, deadline set, installation and restart outside maintenance windows allowed" -f $label)
            }
            continue
        }
        if ($isEntry -and $EntryOwnership -cne 'manual') {
            New-VsPreflightCheck -Check 'deployment_policy' -Target $name -Status 'unknown' -Code 'deployment_owner_unknown' -Reason ("{0}: the application is not visible, so it is open whether this is the managed Core deployment" -f $label)
            continue
        }
        if ([string]$deployment.OverrideServiceWindows -ceq 'True' -and [string]$deployment.RebootOutsideOfServiceWindows -ceq 'True') {
            New-VsPreflightCheck -Check 'deployment_policy' -Target $name -Status 'pass' -Code 'manual_deployment_window_flags' -Reason ("{0}: manual deployment, installation and restart outside maintenance windows allowed" -f $label)
        } else {
            New-VsPreflightCheck -Check 'deployment_policy' -Target $name -Status 'warn' -Code 'manual_deployment_window_flags' -Reason ("{0}: manual deployment, installation outside maintenance windows {1}, restart outside maintenance windows {2}; Q1 variant A expects both for Core, an existing manual deployment is only reported" -f $label, (Get-VsWindowFlagText $deployment.OverrideServiceWindows), (Get-VsWindowFlagText $deployment.RebootOutsideOfServiceWindows))
        }
    }

    $active = @($Deployments | Where-Object { $_.Family -and [string]$_.OfferTypeId -ceq '0' -and [string]$_.DesiredConfigType -ceq '1' -and [string]$_.Enabled -ceq 'True' })
    foreach ($group in @($active | Group-Object -Property { '{0}|{1}' -f $_.CollectionId, $_.Family })) {
        $names = @($group.Group | ForEach-Object { [string]$_.Name } | Sort-Object -Unique)
        if ($names.Count -lt 2) { continue }
        $first = $group.Group[0]
        New-VsPreflightCheck -Check 'deployment_policy' -Target ([string]$first.CollectionId) -Status 'fail' -Code 'parallel_required_deployment' -Reason ("{0} are Required at the same time on collection {1} ({2}) for phase {3}; only one version of a phase may be deployed" -f ($names -join ' and '), $first.CollectionId, $first.CollectionName, $first.Family)
    }

    $runtimeByName = @{}
    foreach ($runtime in $Runtimes) { $runtimeByName[[string]$runtime.Name] = $runtime }
    foreach ($window in $Windows) {
        $id = [string]$window.CollectionId
        if ([string]$window.State -cne 'complete') {
            New-VsPreflightCheck -Check 'deployment_policy' -Target $id -Status 'unknown' -Code 'maintenance_windows_unknown' -Reason ([string]$window.Detail)
            continue
        }
        if ([int]$window.Count -eq 0) {
            New-VsPreflightCheck -Check 'deployment_policy' -Target $id -Status 'pass' -Code 'maintenance_windows_none' -Reason ("No maintenance window on collection {0}" -f $id)
            continue
        }
        $waiting = @($Deployments | Where-Object { [string]$_.CollectionId -ieq $id -and [string]$_.OverrideServiceWindows -cne 'True' })
        if ($waiting.Count -eq 0) {
            New-VsPreflightCheck -Check 'deployment_policy' -Target $id -Status 'pass' -Code 'maintenance_windows_overridden' -Reason ("{0} maintenance window(s) on collection {1}; every deployment there may install outside them" -f $window.Count, $id)
            continue
        }
        $minutes = @($waiting | ForEach-Object {
            $runtime = $runtimeByName[[string]$_.Family]
            if ($runtime -and [string]$runtime.Minutes -cmatch '\A[0-9]+\z') { [int]$runtime.Minutes }
            elseif ($runtime -and [string]$runtime.Expected -cmatch '\A[0-9]+\z') { [int]$runtime.Expected }
        })
        $needed = if ($minutes.Count -gt 0) { [int](($minutes | Measure-Object -Maximum).Maximum) } else { 0 }
        $fit = 'the window duration is not readable'
        if ([string]$window.LongestMinutes -and $needed -gt 0) {
            if ([int]$window.LongestMinutes -ge $needed) { $fit = ('the longest window ({0} min) fits a maximum runtime of {1} min' -f $window.LongestMinutes, $needed) }
            else { $fit = ('no window fits a maximum runtime of {1} min (longest {0} min), so these deployments do not start' -f $window.LongestMinutes, $needed) }
        }
        $names = @($waiting | ForEach-Object { [string]$_.Name } | Sort-Object -Unique) -join ', '
        New-VsPreflightCheck -Check 'deployment_policy' -Target $id -Status 'warn' -Code 'maintenance_windows_delay' -Reason ("{0} maintenance window(s) on collection {1}; {2} wait for a window; {3}" -f $window.Count, $id, $names, $fit)
    }

    foreach ($runtime in $Runtimes) {
        $name = [string]$runtime.Name
        switch ([string]$runtime.State) {
            'ok' { New-VsPreflightCheck -Check 'deployment_policy' -Target $name -Status 'pass' -Code 'maximum_runtime_ok' -Reason ("MaxExecuteTime of the install action is {0} min as in the contract" -f $runtime.Minutes) }
            'drift' { New-VsPreflightCheck -Check 'deployment_policy' -Target $name -Status 'fail' -Code 'maximum_runtime_drift' -Reason ("MaxExecuteTime of the install action is {0} min, the contract expects {1} min" -f $runtime.Minutes, $runtime.Expected) }
            default { New-VsPreflightCheck -Check 'deployment_policy' -Target $name -Status 'unknown' -Code 'maximum_runtime_unknown' -Reason ([string]$runtime.Detail) }
        }
    }

    $raw = 'no managed Core deployment exists yet'
    if ($managed.Count -gt 0) {
        $raw = @($managed | ForEach-Object { 'assignment {0}: OfferFlags {1}, DesiredConfigType {2}' -f $_.AssignmentId, $_.OfferFlags, $_.DesiredConfigType }) -join '; '
    }
    New-VsPreflightCheck -Check 'deployment_policy' -Target 'core' -Status 'unknown' -Code 'implicit_uninstall_unknown' -NotBlocking -Reason ("SMS_ApplicationAssignment has no own field for implicit uninstall, so the preflight does not decide it; check the Core deployment in the console ({0})" -f $raw)
    New-VsPreflightCheck -Check 'deployment_policy' -Target 'core' -Status 'unknown' -Code 'simulation_unknown' -NotBlocking -Reason ("SMS_ApplicationAssignment has no own field that marks a simulated deployment, so the preflight does not decide it ({0})" -f $raw)
}

# --- Report ------------------------------------------------------------------------

# Only forward DT supersedence is exposed by this CM cmdlet; the reverse
# direction comes from SMS_CIRelation in Get-VsCiRelationChecks.
function Get-VsClientSupersedenceRows {
    param([Parameter(Mandatory)][string]$ApplicationName, [Parameter(Mandatory)]$DeploymentType)
    $sourceId = Get-VsObjectPropertyText -Object $DeploymentType -Names @('CI_ID')
    if (-not $sourceId) { throw 'deployment type CI_ID missing' }
    $oldTypes = @(Get-CMDeploymentTypeSupersedence -InputObject $DeploymentType -ErrorAction Stop)
    foreach ($oldType in $oldTypes) {
        $targetId = Get-VsObjectPropertyText -Object $oldType -Names @('CI_ID')
        if (-not $targetId) { throw 'superseded deployment type CI_ID missing' }
        [pscustomobject]@{
            Kind = 'supersedence-forward'
            Name = $ApplicationName
            SourceId = $sourceId
            TargetId = $targetId
        }
    }
}

function Get-VsClientPreflightReport {
    param(
        [Parameter(Mandatory)][hashtable]$Config,
        [Parameter(Mandatory)][object[]]$Specs,
        [Parameter(Mandatory)][string]$ClientSourceDir,
        [Parameter(Mandatory)][string]$SiteCode,
        [AllowNull()][AllowEmptyCollection()][string[]]$CurrentSids,
        [AllowNull()][AllowEmptyCollection()][string[]]$ComputerNames
    )
    if (-not $PSBoundParameters.ContainsKey('CurrentSids')) { $CurrentSids = @(Get-VsCurrentSids) }
    if (-not $PSBoundParameters.ContainsKey('ComputerNames')) { $ComputerNames = @(Get-VsLocalComputerNames) }
    $findings = New-Object System.Collections.Generic.List[object]
    $inventory = New-Object System.Collections.Generic.List[object]
    $checks = New-Object System.Collections.Generic.List[object]
    $recordCheck = {
        param($row)
        $checks.Add($row)
        if ($row.Status -cne 'pass') {
            $findings.Add([pscustomobject]@{ Code = $row.Code; Target = $row.Target; Detail = $row.Reason; Blocking = $row.Blocking; Status = $row.Status; Check = $row.Check })
        }
    }
    $legacyNames = @((Get-VsClientPackagingPolicy).LegacyApplicationNames)
    $entryName = [string](@($Specs | Where-Object { $_.PSObject.Properties['Role'] -and [string]$_.Role -ceq 'deployable_entry' } | ForEach-Object { [string]$_.AppName }) | Select-Object -First 1)
    $total = 8
    Write-Host "[0/$total] RUN client-preflight"

    Write-Host "[1/$total] RUN source-identity"
    # Initialize-VsCmSite has already switched to the CM site drive. From a
    # non-FileSystem location Test-Path and Get-ChildItem cannot resolve UNC
    # paths, so every share check would report a false unknown. Run the file
    # checks from a FileSystem location and restore the site drive afterwards.
    Push-Location -LiteralPath (Get-VsFileSystemRoot)
    try {
        $markerName = '.virtusphere-source-identity'
        $localMarker = Join-Path ([string]$Config.PackagesBase) $markerName
        $shareMarker = Join-Path ([string]$Config.ContentShare) $markerName
        foreach ($pair in @(@('local', $localMarker), @('unc', $shareMarker))) {
            try {
                $value = Read-VsSourceMarkerText -LiteralPath $pair[1]
                if ($value -cne [string]$Config.SourceIdentity) { throw 'marker differs from site configuration' }
                $inventory.Add([pscustomobject]@{ Kind = 'source'; Name = $pair[0]; Identity = $value })
            } catch {
                $findings.Add([pscustomobject]@{ Code = 'source_identity_unknown'; Target = $pair[0]; Detail = [string]$_.Exception.Message; Blocking = $true })
            }
        }
        $specCount = $Specs.Count
        $specIndex = 0
        Write-Host "[0/$specCount] RUN content-manifests"
        foreach ($spec in $Specs) {
            $specIndex++
            Write-Host "[$specIndex/$specCount] RUN content-manifest $($spec.AppName)"
            foreach ($fileName in @($spec.RequiredFiles | Where-Object { $_ -cne 'bootstrap.json' })) {
                $sourceFile = Join-Path $ClientSourceDir ([string]$fileName)
                try {
                    $hash = (Get-FileHash -LiteralPath $sourceFile -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant()
                    $inventory.Add([pscustomobject]@{ Kind = 'client-source-file'; Name = [string]$fileName; Identity = $hash })
                } catch {
                    $findings.Add([pscustomobject]@{ Code = 'client_source_unknown'; Target = [string]$fileName; Detail = [string]$_.Exception.Message; Blocking = $true })
                }
            }
            $localPath = Join-Path ([string]$Config.PackagesBase) ([string]$spec.Folder)
            $sharePath = Join-Path ([string]$Config.ContentShare) ([string]$spec.Folder)
            try {
                if (-not (Test-Path -LiteralPath $localPath -PathType Container) -or
                    -not (Test-Path -LiteralPath $sharePath -PathType Container)) { throw 'local or UNC content folder missing' }
                $drift = @(Compare-VsClientContentManifest -StagedPath $localPath -PublishedPath $sharePath)
                foreach ($issue in $drift) {
                    $findings.Add([pscustomobject]@{ Code = 'content_manifest_drift'; Target = [string]$spec.AppName; Detail = [string]$issue; Blocking = $true })
                }
                $inventory.Add([pscustomobject]@{ Kind = 'content'; Name = [string]$spec.AppName; Local = $localPath; Unc = $sharePath; DriftCount = $drift.Count })
            } catch {
                $findings.Add([pscustomobject]@{ Code = 'content_inventory_unknown'; Target = [string]$spec.AppName; Detail = [string]$_.Exception.Message; Blocking = $true })
            }
            Write-Host "[$specIndex/$specCount] DONE content-manifest $($spec.AppName)"
        }
        $shares = @()
        $shareError = ''
        try {
            $shares = @(Get-SmbShare -ErrorAction Stop | ForEach-Object { [pscustomobject]@{ Name = [string]$_.Name; Path = [string]$_.Path } })
        } catch {
            $shareError = [string]$_.Exception.Message
        }
        $markersConfirmed = @($inventory.ToArray() | Where-Object { $_.Kind -ceq 'source' }).Count -eq 2
        & $recordCheck (Get-VsSourceMappingCheck -PackagesBase ([string]$Config.PackagesBase) -ContentShare ([string]$Config.ContentShare) `
            -Shares $shares -ShareError $shareError -ComputerNames $ComputerNames -MarkersConfirmed $markersConfirmed)
    } finally {
        Pop-Location
    }
    Write-Host "[1/$total] DONE source-identity"

    Write-Host "[2/$total] RUN site-scope"
    if ($SiteCode -cnotmatch '\A[A-Z0-9]{3}\z' -or [string]$Config.CoreLimitingCollectionId -cnotlike "$SiteCode*") {
        $findings.Add([pscustomobject]@{ Code = 'site_mismatch'; Target = 'CoreLimitingCollectionId'; Detail = 'Collection ID and active site differ'; Blocking = $true })
    }
    try {
        $collections = @(Get-CMDeviceCollection -CollectionId ([string]$Config.CoreLimitingCollectionId) -ErrorAction Stop)
        if ($collections.Count -ne 1 -or [string]$collections[0].CollectionID -cne [string]$Config.CoreLimitingCollectionId) {
            throw 'limiting collection is not uniquely visible'
        }
        $inventory.Add([pscustomobject]@{ Kind = 'collection'; Name = 'limiting'; Identity = [string]$collections[0].CollectionID })
    } catch {
        $findings.Add([pscustomobject]@{ Code = 'limiting_collection_unknown'; Target = [string]$Config.CoreLimitingCollectionId; Detail = [string]$_.Exception.Message; Blocking = $true })
    }
    Write-Host "[2/$total] DONE site-scope"

    # MECM compares application names without case (the surveyed site spells
    # client_Hostname, the spec client_hostname); the site spelling is kept.
    Write-Host "[3/$total] RUN legacy-applications"
    $legacyTotal = $legacyNames.Count
    $legacyIndex = 0
    Write-Host "[0/$legacyTotal] RUN legacy-applications"
    foreach ($name in $legacyNames) {
        $legacyIndex++
        Write-Host "[$legacyIndex/$legacyTotal] RUN legacy-application $name"
        try {
            $appMatches = @(Get-CMApplication -Name $name -ShowHidden -DisableWildcardHandling -ErrorAction Stop | Where-Object { (Get-VsObjectPropertyText -Object $_ -Names @('LocalizedDisplayName', 'Name')) -ieq $name })
            foreach ($app in $appMatches) {
                $inventory.Add([pscustomobject]@{ Kind = 'legacy-application'; Name = $name; Identity = (Get-VsObjectPropertyText -Object $app -Names @('ModelName')); CiId = (Get-VsObjectPropertyText -Object $app -Names @('CI_ID')) })
            }
            if ($appMatches.Count -gt 1) { $findings.Add([pscustomobject]@{ Code = 'legacy_ambiguous'; Target = $name; Detail = 'Multiple exact-name applications'; Blocking = $true }) }
            if ($appMatches.Count -eq 1) {
                try {
                    $legacyTypes = @(Get-CMDeploymentType -ApplicationName $name -ErrorAction Stop)
                    foreach ($legacyType in $legacyTypes) {
                        $inventory.Add([pscustomobject]@{ Kind = 'legacy-deployment-type'; Name = $name; Identity = (Get-VsObjectPropertyText -Object $legacyType -Names @('CI_ID')) })
                        foreach ($row in @(Get-VsClientSupersedenceRows -ApplicationName $name -DeploymentType $legacyType)) { $inventory.Add($row) }
                    }
                } catch {
                    $findings.Add([pscustomobject]@{ Code = 'legacy_supersedence_unknown'; Target = $name; Detail = [string]$_.Exception.Message; Blocking = $true })
                }
            }
        } catch {
            $findings.Add([pscustomobject]@{ Code = 'legacy_inventory_unknown'; Target = $name; Detail = [string]$_.Exception.Message; Blocking = $true })
        } finally {
            Write-Host "[$legacyIndex/$legacyTotal] DONE legacy-application $name"
        }
    }
    Write-Host "[3/$total] DONE legacy-applications"

    Write-Host "[4/$total] RUN managed-applications"
    $absentManaged = New-Object System.Collections.Generic.List[string]
    $runtimes = New-Object System.Collections.Generic.List[object]
    $entryOwnership = 'unknown'
    $managedIndex = 0
    Write-Host "[0/$($Specs.Count)] RUN managed-applications"
    foreach ($spec in $Specs) {
        $managedIndex++
        $name = [string]$spec.AppName
        Write-Host "[$managedIndex/$($Specs.Count)] RUN managed-application $name"
        try {
            $appMatches = @(Get-CMApplication -Name $name -ShowHidden -DisableWildcardHandling -ErrorAction Stop | Where-Object { (Get-VsObjectPropertyText -Object $_ -Names @('LocalizedDisplayName', 'Name')) -ieq $name })
            if ($appMatches.Count -gt 1) { throw 'multiple exact-name applications' }
            if ($appMatches.Count -eq 0) {
                $inventory.Add([pscustomobject]@{ Kind = 'managed-application'; Name = $name; State = 'not-visible' })
                $absentManaged.Add($name)
                continue
            }
            $app = $appMatches[0]
            $ciId = Get-VsObjectPropertyText -Object $app -Names @('CI_ID')
            $model = Get-VsObjectPropertyText -Object $app -Names @('ModelName')
            $scopes = if ($app.PSObject.Properties['SecuredScopeNames']) { @($app.SecuredScopeNames) } else { @() }
            $siteName = Get-VsObjectPropertyText -Object $app -Names @('LocalizedDisplayName', 'Name')
            $owned = [bool](Test-VsClientApplicationOwnership -Application $app -Spec $spec -AppFolder 'VirtuSphere_Core')
            if ($name -ieq $entryName) { $entryOwnership = $(if ($owned) { 'managed' } else { 'manual' }) }
            $inventory.Add([pscustomobject]@{ Kind = 'managed-application'; Name = $name; State = 'visible'; SiteName = $siteName; CiId = $ciId; Identity = $model; Owned = $owned; SecurityScopes = @($scopes) })
            if (-not $ciId -or -not $model -or -not $owned) {
                $findings.Add([pscustomobject]@{ Code = 'application_identity_unverified'; Target = $name; Detail = 'CI_ID, ModelName or managed marker missing'; Blocking = $true })
            }
            $types = @(Get-CMDeploymentType -ApplicationName $name -ErrorAction Stop)
            if ($types.Count -ne 1) {
                $findings.Add([pscustomobject]@{ Code = 'deployment_type_count'; Target = $name; Detail = "Visible deployment types: $($types.Count)"; Blocking = $true })
                $runtimes.Add((Get-VsClientRuntimeState -Spec $spec -DeploymentType $null -Detail ('Visible deployment types: {0}; the maximum runtime belongs to exactly one' -f $types.Count)))
                continue
            }
            $codes = @(Get-CMDeploymentTypeReturnCode -InputObject $types[0] -ErrorAction Stop)
            $issues = @(Get-VsClientDeploymentTypeContractIssues -DeploymentType $types[0] -Spec $spec -ContentLocation (Join-Path ([string]$Config.ContentShare) ([string]$spec.Folder)) -InstallCommand (Get-VsClientInstallCommand -Spec $spec) -ReturnCodes $codes)
            foreach ($issue in $issues) { $findings.Add([pscustomobject]@{ Code = 'deployment_type_drift'; Target = $name; Detail = [string]$issue; Blocking = $true }) }
            $runtimes.Add((Get-VsClientRuntimeState -Spec $spec -DeploymentType $types[0] -Issues $issues))
            try {
                foreach ($row in @(Get-VsClientSupersedenceRows -ApplicationName $name -DeploymentType $types[0])) { $inventory.Add($row) }
            } catch {
                $findings.Add([pscustomobject]@{ Code = 'supersedence_inventory_unknown'; Target = $name; Detail = [string]$_.Exception.Message; Blocking = $true })
            }
            $groups = @(Get-CMDeploymentTypeDependencyGroup -InputObject $types[0] -ErrorAction Stop)
            foreach ($group in $groups) {
                $deps = @(Get-CMDeploymentTypeDependency -InputObject $group -ErrorAction Stop)
                $inventory.Add([pscustomobject]@{ Kind = 'dependency-group'; Name = $name; Group = (Get-VsObjectPropertyText -Object $group -Names @('GroupName', 'LocalizedDisplayName')); TargetIds = @($deps | ForEach-Object { Get-VsObjectPropertyText -Object $_ -Names @('CI_ID') }) })
            }
        } catch {
            $findings.Add([pscustomobject]@{ Code = 'application_inventory_unknown'; Target = $name; Detail = [string]$_.Exception.Message; Blocking = $true })
        } finally {
            Write-Host "[$managedIndex/$($Specs.Count)] DONE managed-application $name"
        }
    }
    Write-Host "[4/$total] DONE managed-applications"
    $targets = @($inventory.ToArray() | Where-Object { $_.Kind -in @('managed-application', 'legacy-application') -and $_.PSObject.Properties['Identity'] -and $_.Identity } |
        ForEach-Object { [pscustomobject]@{ Name = [string]$_.Name; CiId = [string]$_.CiId; Model = [string]$_.Identity } })

    Write-Host "[5/$total] RUN deployment-references"
    $deploymentRows = New-Object System.Collections.Generic.List[object]
    $deploymentNames = @(@($Specs | ForEach-Object { [string]$_.AppName }) + $legacyNames)
    $deploymentIndex = 0
    Write-Host "[0/$($deploymentNames.Count)] RUN application-deployments"
    foreach ($name in $deploymentNames) {
        $deploymentIndex++
        Write-Host "[$deploymentIndex/$($deploymentNames.Count)] RUN application-deployment $name"
        try {
            $deployments = @(Get-CMApplicationDeployment -Name $name -ErrorAction Stop)
            foreach ($deployment in $deployments) {
                $row = ConvertTo-VsDeploymentRow -Deployment $deployment -QueriedName $name -Source 'name'
                $row.Family = Get-VsClientPhaseFamily -ApplicationName $row.Name -Specs $Specs -LegacyNames $legacyNames
                $deploymentRows.Add($row)
                $inventory.Add([pscustomobject]@{
                    Kind = 'application-deployment'; Name = $name; Identity = $row.AssignmentId
                    ApplicationName = $row.Name; Collection = $row.CollectionId; OfferTypeId = $row.OfferTypeId
                    DesiredConfigType = $row.DesiredConfigType
                    OverrideServiceWindows = $row.OverrideServiceWindows
                    RebootOutsideOfServiceWindows = $row.RebootOutsideOfServiceWindows
                    Enabled = $row.Enabled; Deadline = $row.Deadline
                    SoftDeadlineEnabled = $row.SoftDeadlineEnabled; OfferFlags = $row.OfferFlags; RequireApproval = $row.RequireApproval
                })
                # Targeting topology. The deployable entry's deployments are
                # judged by the deployment_policy check below.
                if ($entryName -and $name -ieq $entryName) { continue }
                if ($name -cin $legacyNames) {
                    # A deployment the retirement tool already disabled no longer
                    # targets clients; an enabled or unreadable state still blocks.
                    if ($row.Enabled -ceq 'False') {
                        $findings.Add([pscustomobject]@{ Code = 'legacy_deployment_disabled'; Target = $name; Detail = ('Assignment {0} on collection {1} is disabled' -f $row.AssignmentId, $row.CollectionId); Blocking = $false })
                    } else {
                        $findings.Add([pscustomobject]@{ Code = 'legacy_deployment_present'; Target = $name; Detail = ('Assignment {0} targets collection {1}' -f $row.AssignmentId, $row.CollectionId); Blocking = $true })
                    }
                } else {
                    $findings.Add([pscustomobject]@{ Code = 'internal_phase_deployment_present'; Target = $name; Detail = ('Assignment {0} targets collection {1}' -f $row.AssignmentId, $row.CollectionId); Blocking = $true })
                }
            }
        } catch {
            $findings.Add([pscustomobject]@{ Code = 'deployment_inventory_unknown'; Target = $name; Detail = [string]$_.Exception.Message; Blocking = $true })
        } finally {
            Write-Host "[$deploymentIndex/$($deploymentNames.Count)] DONE application-deployment $name"
        }
    }

    # Reverse references site-wide. Every revision counts: expired revisions
    # still carry relations (surveyed site: old revisions pointing at getInfos).
    Write-Host '[0/4] RUN shared-references'
    $referenceRows = New-Object System.Collections.Generic.List[object]
    $revisions = Read-VsTargetRevisions -SiteCode $SiteCode -Targets $targets
    if ($revisions.State -cne 'complete') {
        $referenceRows.Add((New-VsPreflightCheck -Check 'references' -Target 'revisions' -Status 'unknown' -Code 'references_unknown' -Reason ("Revisions of the target applications are not completely readable: {0}" -f $revisions.Detail)))
    }
    $ciCache = @{}
    $modelByCi = @{}
    foreach ($revision in @($revisions.Applications)) { if ($revision.CiId) { $modelByCi[[string]$revision.CiId] = [string]$revision.ModelName } }

    Write-Host '[1/4] RUN application-groups'
    $logicalNames = @($targets | ForEach-Object { Get-VsApplicationLogicalName -ModelName $_.Model } | Where-Object { $_ })
    $membership = Read-VsApplicationGroupMembership -LogicalNames $logicalNames
    foreach ($group in @($membership.Groups)) {
        $inventory.Add([pscustomobject]@{ Kind = 'application-group'; Name = [string]$group.Name; Identity = [string]$group.ModelName; CiId = [string]$group.CiId })
    }
    if ($membership.State -ceq 'complete') {
        $inventory.Add([pscustomobject]@{ Kind = 'application-group-scan'; Name = 'all-visible'; Count = [int]$membership.GroupCount })
        foreach ($target in $targets) {
            $logicalName = Get-VsApplicationLogicalName -ModelName $target.Model
            if (-not $logicalName -or -not $membership.Members.ContainsKey($logicalName)) { continue }
            foreach ($groupName in @($membership.Members[$logicalName])) {
                $referenceRows.Add((New-VsPreflightCheck -Check 'references' -Target $target.Name -Status 'fail' -Code 'application_group_member' -Reason ("Member of application group '{0}'; a group must not orchestrate the chain in parallel" -f $groupName)))
            }
        }
    } else {
        $referenceRows.Add((New-VsPreflightCheck -Check 'references' -Target 'all-visible' -Status 'unknown' -Code 'application_group_inventory_unknown' -Reason ([string]$membership.Detail)))
    }
    Write-Host '[1/4] DONE application-groups'

    Write-Host '[2/4] RUN task-sequence-references'
    try {
        # The provider class exposes PackageID and exact referenced app IDs; a
        # missing local provider is an unknown result, never zero references.
        $taskRefs = @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_TaskSequenceAppReferencesInfo')
        $taskNames = @{}
        foreach ($reference in $taskRefs) {
            $packageId = Get-VsObjectPropertyText -Object $reference -Names @('PackageID')
            $model = Get-VsObjectPropertyText -Object $reference -Names @('RefAppModelName')
            $ciId = Get-VsObjectPropertyText -Object $reference -Names @('RefAppCI_ID')
            if (-not $packageId -or (-not $model -and -not $ciId)) { throw 'task sequence reference has incomplete identity' }
            $referencedModel = if ($model) { $model } elseif ($modelByCi.ContainsKey($ciId)) { $modelByCi[$ciId] } else { '' }
            foreach ($target in @($targets | Where-Object { ($referencedModel -and $_.Model -ieq $referencedModel) -or ($ciId -and $_.CiId -ceq $ciId) })) {
                $inventory.Add([pscustomobject]@{ Kind = 'task-sequence-reference'; Name = [string]$target.Name; Identity = $packageId; RefAppModelName = $model; RefAppCiId = $ciId })
                if (-not $taskNames.ContainsKey($packageId)) {
                    $taskNames[$packageId] = ''
                    if ($packageId -cmatch $script:VsPreflightIdPattern) {
                        try {
                            $package = @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_TaskSequencePackage' -Filter ('PackageID = {0}' -f (ConvertTo-VsWqlString -Value $packageId)) -Property @('PackageID', 'Name'))
                            if ($package.Count -gt 0) { $taskNames[$packageId] = Get-VsObjectPropertyText -Object $package[0] -Names @('Name') }
                        } catch {
                            Write-Verbose ([string]$_)
                        }
                    }
                }
                $referenceRows.Add((New-VsPreflightCheck -Check 'references' -Target $target.Name -Status 'fail' -Code 'task_sequence_reference' -Reason ("Task sequence {0} '{1}' references the application; a task sequence must not orchestrate the chain in parallel" -f $packageId, $taskNames[$packageId])))
            }
        }
        $inventory.Add([pscustomobject]@{ Kind = 'task-sequence-reference-scan'; Name = 'all-visible'; Count = $taskRefs.Count })
    } catch {
        $referenceRows.Add((New-VsPreflightCheck -Check 'references' -Target 'all-visible' -Status 'unknown' -Code 'task_sequence_references_unknown' -Reason ([string]$_.Exception.Message)))
    } finally {
        Write-Host '[2/4] DONE task-sequence-references'
    }

    Write-Host '[3/4] RUN dependence-relations'
    $reported = $null
    try {
        $dependence = Get-VsDependenceRelationChecks -SiteCode $SiteCode -Revisions $revisions -Cache $ciCache
        foreach ($row in @($dependence.Checks)) { $referenceRows.Add($row) }
        foreach ($row in @($dependence.Inventory)) { $inventory.Add($row) }
        $reported = $dependence.Reported
    } catch {
        $referenceRows.Add((New-VsPreflightCheck -Check 'references' -Target 'all-visible' -Status 'unknown' -Code 'references_unknown' -Reason ("SMS_AppDependenceRelation: {0}" -f [string]$_.Exception.Message)))
    } finally {
        Write-Host '[3/4] DONE dependence-relations'
    }

    Write-Host '[4/4] RUN ci-relations'
    try {
        $relations = Get-VsCiRelationChecks -SiteCode $SiteCode -Revisions $revisions -Cache $ciCache -Reported $reported
        foreach ($row in @($relations.Checks)) { $referenceRows.Add($row) }
        foreach ($row in @($relations.Inventory)) { $inventory.Add($row) }
    } catch {
        $referenceRows.Add((New-VsPreflightCheck -Check 'references' -Target 'all-visible' -Status 'unknown' -Code 'references_unknown' -Reason ("SMS_CIRelation: {0}" -f [string]$_.Exception.Message)))
    } finally {
        Write-Host '[4/4] DONE ci-relations'
    }
    if (@($referenceRows | Where-Object { $_.Status -cne 'pass' }).Count -eq 0) {
        $referenceRows.Add((New-VsPreflightCheck -Check 'references' -Target 'all-visible' -Status 'pass' -Code 'references_complete' -Reason ("No application group, task sequence, dependency or CI relation outside the client chain references the {0} target application(s)" -f $targets.Count)))
    }
    foreach ($row in $referenceRows) { & $recordCheck $row }
    Write-Host "[5/$total] DONE deployment-references"

    Write-Host "[6/$total] RUN rbac-scope"
    $admin = Read-VsCallerAdminScope -SiteCode $SiteCode -CurrentSids $CurrentSids
    $inventory.Add([pscustomobject]@{ Kind = 'rbac-caller'; Name = 'caller'; State = $admin.State; Logons = @($admin.Logons); ScopeIds = @($admin.ScopeIds); CollectionIds = @($admin.CollectionIds); CollectionNames = @($admin.CollectionNames); RoleNames = @($admin.RoleNames) })
    $applicationScopes = @(foreach ($target in $targets) { Read-VsObjectScopeIds -SiteCode $SiteCode -Name $target.Name -ObjectKey $target.Model })
    $collectionIds = @(@([string]$Config.CoreLimitingCollectionId) + @($deploymentRows | ForEach-Object { [string]$_.CollectionId }) | Where-Object { $_ } | Sort-Object -Unique)
    $chains = @(foreach ($id in $collectionIds) { Read-VsCollectionChain -SiteCode $SiteCode -CollectionId $id })
    $siteScopes = $null
    if ($admin.State -ceq 'complete' -and @($admin.ScopeIds) -notcontains $script:VsPreflightAllScopeId) {
        try {
            $siteScopes = [pscustomobject]@{ State = 'complete'; Detail = ''; Scopes = @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_SecuredCategory' -Property @('CategoryID', 'CategoryName') |
                ForEach-Object { [pscustomobject]@{ Id = (Get-VsObjectPropertyText -Object $_ -Names @('CategoryID')); Name = (Get-VsObjectPropertyText -Object $_ -Names @('CategoryName')) } }) }
        } catch {
            $siteScopes = [pscustomobject]@{ State = 'unknown'; Detail = [string]$_.Exception.Message; Scopes = @() }
        }
    }
    foreach ($row in @(Get-VsRbacChecks -Admin $admin -Applications $applicationScopes -Collections $chains -SiteScopes $siteScopes -AbsentApplications $absentManaged.ToArray())) { & $recordCheck $row }
    Write-Host "[6/$total] DONE rbac-scope"

    Write-Host "[7/$total] RUN deployment-policy"
    # A versioned copy of a phase (surveyed site: a second getInfos) is no
    # spec name and was not read by name; it is found on the same collections.
    $knownAssignments = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($row in $deploymentRows) { if ($row.AssignmentId) { [void]$knownAssignments.Add([string]$row.AssignmentId) } }
    $policyCollections = @($deploymentRows | ForEach-Object { [string]$_.CollectionId } | Where-Object { $_ } | Sort-Object -Unique)
    $windows = New-Object System.Collections.Generic.List[object]
    $collectionIndex = 0
    Write-Host "[0/$($policyCollections.Count)] RUN deployment-collections"
    foreach ($id in $policyCollections) {
        $collectionIndex++
        Write-Host "[$collectionIndex/$($policyCollections.Count)] RUN deployment-collection $id"
        try {
            if ($id -cnotmatch $script:VsPreflightIdPattern) { throw ("unexpected collection ID '{0}'" -f $id) }
            foreach ($assignment in @(Get-VsPreflightCim -SiteCode $SiteCode -ClassName 'SMS_ApplicationAssignment' -Filter ('TargetCollectionID = {0}' -f (ConvertTo-VsWqlString -Value $id)))) {
                $row = ConvertTo-VsDeploymentRow -Deployment $assignment -QueriedName '' -Source 'collection'
                $row.Family = Get-VsClientPhaseFamily -ApplicationName $row.Name -Specs $Specs -LegacyNames $legacyNames
                if (-not $row.Family -or -not $row.AssignmentId -or -not $knownAssignments.Add([string]$row.AssignmentId)) { continue }
                $deploymentRows.Add($row)
                $inventory.Add([pscustomobject]@{
                    Kind = 'application-deployment'; Name = $row.Name; Identity = $row.AssignmentId
                    ApplicationName = $row.Name; Collection = $row.CollectionId; OfferTypeId = $row.OfferTypeId
                    DesiredConfigType = $row.DesiredConfigType
                    OverrideServiceWindows = $row.OverrideServiceWindows
                    RebootOutsideOfServiceWindows = $row.RebootOutsideOfServiceWindows
                    Enabled = $row.Enabled; Deadline = $row.Deadline
                    SoftDeadlineEnabled = $row.SoftDeadlineEnabled; OfferFlags = $row.OfferFlags; RequireApproval = $row.RequireApproval
                })
            }
        } catch {
            & $recordCheck (New-VsPreflightCheck -Check 'deployment_policy' -Target $id -Status 'unknown' -Code 'deployment_policy_unknown' -Reason ("Deployments on collection {0} are not readable: {1}" -f $id, [string]$_.Exception.Message))
        }
        $windows.Add((Read-VsCollectionServiceWindows -SiteCode $SiteCode -CollectionId $id))
        Write-Host "[$collectionIndex/$($policyCollections.Count)] DONE deployment-collection $id"
    }
    foreach ($row in @(Get-VsDeploymentPolicyChecks -Deployments $deploymentRows.ToArray() -EntryName $entryName -EntryOwnership $entryOwnership -Windows $windows.ToArray() -Runtimes $runtimes.ToArray())) { & $recordCheck $row }
    Write-Host "[7/$total] DONE deployment-policy"

    Write-Host "[8/$total] RUN distribution-and-plan"
    if ([string]::IsNullOrEmpty([string]$Config.DpGroupName)) {
        $findings.Add([pscustomobject]@{ Code = 'dp_target_unset'; Target = 'DpGroupName'; Detail = 'Distribution target requires a site decision'; Blocking = $true })
    } else {
        try {
            $groups = @(Get-CMDistributionPointGroup -Name ([string]$Config.DpGroupName) -ErrorAction Stop)
            if ($groups.Count -ne 1) { throw 'DP group is not uniquely visible' }
            $inventory.Add([pscustomobject]@{ Kind = 'distribution-point-group'; Name = [string]$Config.DpGroupName; Identity = (Get-VsObjectPropertyText -Object $groups[0] -Names @('GroupID', 'GroupId')) })
            $points = @(Get-CMDistributionPoint -DistributionPointGroup $groups[0] -ErrorAction Stop)
            if ($points.Count -eq 0) { throw 'DP group has no visible distribution points' }
            $pointIndex = 0
            Write-Host "[0/$($points.Count)] RUN distribution-points"
            foreach ($point in $points) {
                $pointIndex++
                Write-Host "[$pointIndex/$($points.Count)] RUN distribution-point"
                $server = Get-VsObjectPropertyText -Object $point -Names @('SiteSystemServerName', 'NetworkOSPath', 'ServerName')
                if (-not $server) { throw 'distribution point identity missing' }
                $inventory.Add([pscustomobject]@{ Kind = 'distribution-point'; Name = [string]$Config.DpGroupName; Identity = $server })
                Write-Host "[$pointIndex/$($points.Count)] DONE distribution-point"
            }
        } catch {
            $findings.Add([pscustomobject]@{ Code = 'dp_group_unknown'; Target = [string]$Config.DpGroupName; Detail = [string]$_.Exception.Message; Blocking = $true })
        }
    }
    $planRows = @($inventory.ToArray() | Sort-Object Kind, Name, Identity)
    $planInputs = @($Config.Keys | Sort-Object | ForEach-Object { [pscustomobject]@{ Kind = 'config'; Name = [string]$_; Value = [string]$Config[$_] } })
    $planInputs += $planRows
    $planInputs += @($checks.ToArray() | Sort-Object Check, Target, Code, Reason)
    $planInputs += @($findings.ToArray() | Sort-Object Code, Target, Detail)
    $planId = Get-VsClientPreflightPlanId -Rows @($planInputs)
    Write-Host "[8/$total] DONE distribution-and-plan"
    # Apply stays closed: MC02 only reads. The ordered dependency cutover
    # (MC03) decides when a clean report may enable it.
    return [pscustomobject]@{
        SchemaVersion = 2
        SiteCode = $SiteCode
        PlanId = $planId
        CanApply = $false
        Inventory = @($planRows)
        Checks = @($checks.ToArray())
        Findings = @($findings.ToArray())
    }
}
