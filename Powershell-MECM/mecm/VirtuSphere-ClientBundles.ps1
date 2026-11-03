# MC03 client bundle mechanics: BundleId, private four-folder stage, closed
# validation, predecessor proof, immutable archive and a read-only retention
# plan. No import-time side effects and no Configuration Manager cmdlet: CM
# evidence arrives as neutral objects from the caller. Source activation,
# DT/graph reconciliation, restore and retention deletes are separate owners.
# The installer does not call this module while MC-R4 blocks Apply.
Set-StrictMode -Version 1.0

function Get-VsClientBundlePolicy {
    [pscustomobject]@{
        SchemaVersion            = 1
        BootstrapSchemaVersion   = 2
        FolderCount              = 4
        BundleIdDomain           = 'virtusphere-client-bundle-v1'
        MinimumSuccessfulBundles = 5
        MinimumAgeDays           = 180
    }
}

# Runs a file operation from a FileSystem location (the CM site drive cannot
# resolve UNC or local paths) and restores the caller location. The caller's
# scriptblock sees this function's variables through dynamic scoping, so every
# local name here is prefixed to never shadow a caller variable such as $Root
# or $Path.
function Invoke-VsClientFileSystemScope {
    param(
        [Parameter(Mandatory)][string]$VsScopePath,
        [Parameter(Mandatory)][scriptblock]$VsScopeBody
    )
    $vsScopeRoot = [IO.Path]::GetPathRoot([IO.Path]::GetFullPath($VsScopePath))
    if ([string]::IsNullOrWhiteSpace($vsScopeRoot)) { throw "Cannot determine FileSystem root for '$VsScopePath'." }
    Push-Location -LiteralPath $vsScopeRoot -ErrorAction Stop
    try { & $VsScopeBody }
    finally { Pop-Location }
}

function Get-VsClientSha256HexFromBytes {
    param([Parameter(Mandatory)][byte[]]$Bytes)
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

function Get-VsClientSha256HexFromFile {
    param([Parameter(Mandatory)][string]$Path)
    $stream = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($stream))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose(); $stream.Dispose() }
}

function ConvertTo-VsClientCanonicalBytes {
    param([Parameter(Mandatory)][AllowEmptyCollection()][AllowNull()][object[]]$Parts)
    $utf8 = New-Object Text.UTF8Encoding($false)
    $buffer = New-Object IO.MemoryStream
    try {
        foreach ($part in $Parts) {
            $text = if ($null -eq $part) { '' } else { [Convert]::ToString($part, [Globalization.CultureInfo]::InvariantCulture) }
            $bytes = $utf8.GetBytes($text)
            $prefix = $utf8.GetBytes(([string]$bytes.Length) + ':')
            $buffer.Write($prefix, 0, $prefix.Length)
            $buffer.Write($bytes, 0, $bytes.Length)
            $buffer.WriteByte(10)
        }
        return $buffer.ToArray()
    } finally { $buffer.Dispose() }
}

function Get-VsClientCanonicalHash {
    param([Parameter(Mandatory)][AllowEmptyCollection()][AllowNull()][object[]]$Parts)
    Get-VsClientSha256HexFromBytes -Bytes (ConvertTo-VsClientCanonicalBytes -Parts $Parts)
}

function Assert-VsClientBundleHash {
    param([Parameter(Mandatory)][string]$Name, [Parameter(Mandatory)][string]$Value)
    if ($Value -cnotmatch '^[0-9a-f]{64}$') { throw "$Name must be lowercase SHA-256 hex." }
}

function Assert-VsClientBundleSpecs {
    param([Parameter(Mandatory)][object[]]$Specs)
    $policy = Get-VsClientBundlePolicy
    if ($Specs.Count -ne $policy.FolderCount) { throw "The client bundle must contain exactly four specs." }
    if ($null -eq (Get-Command Assert-VsClientAppSpecGraph -ErrorAction SilentlyContinue)) {
        throw 'Assert-VsClientAppSpecGraph is unavailable; load the existing client packaging owner first.'
    }
    Assert-VsClientAppSpecGraph -Specs $Specs
    $names = @($Specs | ForEach-Object { [string]$_.AppName })
    $folders = @($Specs | ForEach-Object { [string]$_.Folder })
    if (@($names | Sort-Object -Unique).Count -ne 4 -or @($folders | Sort-Object -Unique).Count -ne 4) {
        throw 'Client app names and folders must each be unique.'
    }
}

function Get-VsClientReceiptGraphIssues {
    param([Parameter(Mandatory)][object[]]$Graph)
    $issues = @()
    if ($Graph.Count -ne 4) { return @('graph:count') }
    $byName = @{}
    $folders = @{}
    $entryCount = 0
    foreach ($node in $Graph) {
        $name = if ($node.PSObject.Properties['AppName']) { [string]$node.AppName } else { '' }
        $folder = if ($node.PSObject.Properties['Folder']) { [string]$node.Folder } else { '' }
        $role = if ($node.PSObject.Properties['Role']) { [string]$node.Role } else { '' }
        foreach ($property in @('AppName', 'Folder', 'Role', 'DependsOn')) {
            if (-not $node.PSObject.Properties[$property]) { $issues += ('graph:{0}:property:{1}' -f $name, $property) }
        }
        if ([string]::IsNullOrWhiteSpace($name) -or $byName.ContainsKey($name)) { $issues += 'graph:app-name' } else { $byName[$name] = $node }
        if ([string]::IsNullOrWhiteSpace($folder) -or $folders.ContainsKey($folder)) { $issues += 'graph:folder' } else { $folders[$folder] = $true }
        if ($role -eq 'deployable_entry') { $entryCount++ }
        elseif ($role -ne 'internal_dependency') { $issues += ('graph:role:{0}' -f $name) }
    }
    if ($entryCount -ne 1) { $issues += 'graph:entry-count' }
    foreach ($node in $Graph) {
        $nodeName = if ($node.PSObject.Properties['AppName']) { [string]$node.AppName } else { '' }
        $dependency = if ($node.PSObject.Properties['DependsOn']) { [string]$node.DependsOn } else { '' }
        if (-not [string]::IsNullOrWhiteSpace($dependency) -and -not $byName.ContainsKey($dependency)) {
            $issues += ('graph:dependency:{0}' -f $nodeName)
            continue
        }
        $seen = @{}
        $cursor = $node
        while ($cursor) {
            $cursorDependency = if ($cursor.PSObject.Properties['DependsOn']) { [string]$cursor.DependsOn } else { '' }
            if ([string]::IsNullOrWhiteSpace($cursorDependency)) { break }
            $cursorName = if ($cursor.PSObject.Properties['AppName']) { [string]$cursor.AppName } else { '' }
            if ($seen.ContainsKey($cursorName)) { $issues += ('graph:cycle:{0}' -f $nodeName); break }
            $seen[$cursorName] = $true
            $cursor = $byName[$cursorDependency]
        }
    }
    return @($issues | Sort-Object -Unique)
}

function Get-VsClientSpecHash {
    param([Parameter(Mandatory)][object[]]$Specs)
    Assert-VsClientBundleSpecs -Specs $Specs
    $parts = @('virtusphere-client-specs-v2')
    foreach ($spec in $Specs) {
        $parts += @(
            $spec.AppName, $spec.DisplayName, $spec.Folder, $spec.Script, $spec.Role,
            [string][bool]$spec.RunAs32Bit, [string][bool]$spec.DetectionIs32Bit, $spec.MaximumRuntimeMins,
            $spec.DetectionKey, $spec.DetectionName, $spec.DetectionType,
            $spec.DependsOn, $spec.ManagedMarker
        )
        $parts += @($spec.CmIdentityFields)
        $parts += @($spec.RequiredFiles)
        $parts += @($spec.DetectionValues)
        foreach ($returnCode in @($spec.ReturnCodes)) { $parts += @($returnCode.Value, $returnCode.Type) }
    }
    Get-VsClientCanonicalHash -Parts $parts
}

function Get-VsClientSourceManifest {
    param([Parameter(Mandatory)][string]$SourceDir, [Parameter(Mandatory)][object[]]$Specs)
    Assert-VsClientBundleSpecs -Specs $Specs
    $names = @($Specs | ForEach-Object { @($_.RequiredFiles) } | Where-Object { $_ -ne 'bootstrap.json' } | Sort-Object -Unique)
    $entries = @(Invoke-VsClientFileSystemScope -VsScopePath $SourceDir -VsScopeBody {
        foreach ($name in $names) {
            if ([string]$name -notmatch '^[A-Za-z0-9_-]+\.ps1$') { throw "Invalid source file name '$name'." }
            $path = Join-Path $SourceDir $name
            if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Required source file is missing: $name" }
            $item = Get-Item -LiteralPath $path -Force -ErrorAction Stop
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Source file is a reparse point: $name" }
            [pscustomobject][ordered]@{
                Path   = [string]$name
                Length = [long]$item.Length
                Sha256 = Get-VsClientSha256HexFromFile -Path $item.FullName
            }
        }
    })
    return @($entries)
}

function Get-VsClientManifestHash {
    param([Parameter(Mandatory)][object[]]$Manifest, [string]$Domain = 'virtusphere-client-content-manifest-v1')
    $parts = @($Domain)
    foreach ($entry in @($Manifest | Sort-Object Path)) { $parts += @($entry.Path, $entry.Length, $entry.Sha256) }
    Get-VsClientCanonicalHash -Parts $parts
}

function New-VsClientBundleId {
    param(
        [Parameter(Mandatory)][string]$PlanId,
        [Parameter(Mandatory)][string]$ConfigHash,
        [Parameter(Mandatory)][string]$SpecHash,
        [Parameter(Mandatory)][string]$SourceManifestHash
    )
    if ([string]::IsNullOrWhiteSpace($PlanId)) { throw 'PlanId is required.' }
    Assert-VsClientBundleHash -Name ConfigHash -Value $ConfigHash
    Assert-VsClientBundleHash -Name SpecHash -Value $SpecHash
    Assert-VsClientBundleHash -Name SourceManifestHash -Value $SourceManifestHash
    $policy = Get-VsClientBundlePolicy
    Get-VsClientCanonicalHash -Parts @($policy.BundleIdDomain, $PlanId, $ConfigHash, $SpecHash, $SourceManifestHash)
}

function Test-VsClientBootstrapFile {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$BundleId,
        [Parameter(Mandatory)][hashtable]$Configuration
    )
    $issues = @()
    try {
        $document = Get-Content -LiteralPath $Path -Raw -Encoding UTF8 -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
    } catch { return @('bootstrap:unreadable') }
    $actualKeys = @($document.PSObject.Properties.Name | Sort-Object)
    $expectedKeys = @('BundleId', 'CertThumbprint', 'Schema', 'Scheme', 'WebAPI')
    if (@(Compare-Object -ReferenceObject $expectedKeys -DifferenceObject $actualKeys).Count -gt 0) { $issues += 'bootstrap:keys' }
    if (-not $document.PSObject.Properties['Schema'] -or $document.Schema -ne 2) { $issues += 'bootstrap:schema' }
    if (-not $document.PSObject.Properties['BundleId'] -or [string]$document.BundleId -cne $BundleId) { $issues += 'bootstrap:bundle-id' }
    foreach ($key in @('WebAPI', 'Scheme', 'CertThumbprint')) {
        if (-not $Configuration.ContainsKey($key) -or -not $document.PSObject.Properties[$key] -or [string]$document.$key -cne [string]$Configuration[$key]) {
            $issues += ('bootstrap:{0}' -f $key.ToLowerInvariant())
        }
    }
    return @($issues)
}

function Get-VsClientBundleValidation {
    param(
        [Parameter(Mandatory)][string]$Root,
        [Parameter(Mandatory)][object[]]$Specs,
        [Parameter(Mandatory)][string]$BundleId,
        [Parameter(Mandatory)][hashtable]$BootstrapConfiguration,
        [string[]]$AllowedTopLevelFiles = @()
    )
    try {
        Assert-VsClientBundleHash -Name BundleId -Value $BundleId
        Assert-VsClientBundleSpecs -Specs $Specs
        foreach ($key in @('WebAPI', 'Scheme', 'CertThumbprint')) {
            if (-not $BootstrapConfiguration.ContainsKey($key)) { throw "Bootstrap configuration lacks $key." }
        }
        $expectedTopFiles = @($AllowedTopLevelFiles | Sort-Object)
        $inspection = Invoke-VsClientFileSystemScope -VsScopePath $Root -VsScopeBody {
            $localFindings = @()
            $localManifest = @()
            if (-not (Test-Path -LiteralPath $Root -PathType Container)) {
                return [pscustomobject]@{ Findings = @('root:missing'); Manifest = @() }
            }
            $rootItem = Get-Item -LiteralPath $Root -Force -ErrorAction Stop
            if (($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { $localFindings += 'root:reparse-point' }
            $children = @(Get-ChildItem -LiteralPath $Root -Force -ErrorAction Stop)
            $expectedFolders = @($Specs | ForEach-Object { [string]$_.Folder } | Sort-Object)
            $actualFolders = @($children | Where-Object { $_.PSIsContainer } | ForEach-Object { $_.Name } | Sort-Object)
            $actualFiles = @($children | Where-Object { -not $_.PSIsContainer } | ForEach-Object { $_.Name } | Sort-Object)
            foreach ($diff in @(Compare-Object -ReferenceObject $expectedFolders -DifferenceObject $actualFolders)) {
                $localFindings += ('folder:{0}:{1}' -f $diff.SideIndicator, $diff.InputObject)
            }
            foreach ($name in $expectedTopFiles) {
                if ($actualFiles -cnotcontains $name) { $localFindings += ('top-file:<=:{0}' -f $name) }
            }
            foreach ($name in $actualFiles) {
                if ($expectedTopFiles -cnotcontains $name) { $localFindings += ('top-file:=>:{0}' -f $name) }
            }
            foreach ($spec in $Specs) {
                $folderPath = Join-Path $Root $spec.Folder
                if (-not (Test-Path -LiteralPath $folderPath -PathType Container)) { continue }
                $folderItem = Get-Item -LiteralPath $folderPath -Force -ErrorAction Stop
                if (($folderItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                    $localFindings += ('folder:reparse-point:{0}' -f $spec.Folder)
                    continue
                }
                $items = @(Get-ChildItem -LiteralPath $folderPath -Force -ErrorAction Stop)
                if (@($items | Where-Object { $_.PSIsContainer }).Count -gt 0) { $localFindings += ('folder:nested:{0}' -f $spec.Folder) }
                $expectedFiles = @($spec.RequiredFiles | Sort-Object)
                $actualFolderFiles = @($items | Where-Object { -not $_.PSIsContainer } | ForEach-Object { $_.Name } | Sort-Object)
                foreach ($diff in @(Compare-Object -ReferenceObject $expectedFiles -DifferenceObject $actualFolderFiles)) {
                    $localFindings += ('file:{0}:{1}:{2}' -f $spec.Folder, $diff.SideIndicator, $diff.InputObject)
                }
                foreach ($fileName in $expectedFiles) {
                    $filePath = Join-Path $folderPath $fileName
                    if (-not (Test-Path -LiteralPath $filePath -PathType Leaf)) { continue }
                    $file = Get-Item -LiteralPath $filePath -Force -ErrorAction Stop
                    if (($file.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                        $localFindings += ('file:reparse-point:{0}/{1}' -f $spec.Folder, $fileName)
                        continue
                    }
                    $relative = ('{0}/{1}' -f $spec.Folder, $fileName)
                    $localManifest += [pscustomobject][ordered]@{
                        Path   = $relative
                        Length = [long]$file.Length
                        Sha256 = Get-VsClientSha256HexFromFile -Path $file.FullName
                    }
                }
                $bootstrapPath = Join-Path $folderPath 'bootstrap.json'
                if (Test-Path -LiteralPath $bootstrapPath -PathType Leaf) {
                    foreach ($issue in @(Test-VsClientBootstrapFile -Path $bootstrapPath -BundleId $BundleId -Configuration $BootstrapConfiguration)) {
                        $localFindings += ('{0}:{1}' -f $spec.Folder, $issue)
                    }
                }
            }
            [pscustomobject]@{ Findings = @($localFindings); Manifest = @($localManifest) }
        }
    } catch {
        return [pscustomobject]@{ Status = 'unknown'; Findings = @('inspection:' + $_.Exception.Message); Manifest = @(); ManifestHash = $null }
    }
    $findings = @($inspection.Findings)
    $manifest = @($inspection.Manifest)
    if ($findings.Count -gt 0) {
        return [pscustomobject]@{ Status = 'partial'; Findings = @($findings | Sort-Object -Unique); Manifest = @($manifest); ManifestHash = $null }
    }
    $manifestHash = Get-VsClientManifestHash -Manifest $manifest
    [pscustomobject]@{ Status = 'ready'; Findings = @(); Manifest = @($manifest | Sort-Object Path); ManifestHash = $manifestHash }
}

function New-VsClientBundleStage {
    param(
        [Parameter(Mandatory)][string]$PackagesBase,
        [Parameter(Mandatory)][string]$SourceDir,
        [Parameter(Mandatory)][string]$PlanId,
        [Parameter(Mandatory)][string]$ConfigHash,
        [Parameter(Mandatory)][hashtable]$BootstrapConfiguration
    )
    if ($null -eq (Get-Command Get-VsClientAppSpecs -ErrorAction SilentlyContinue) -or
        $null -eq (Get-Command Copy-VsClientContent -ErrorAction SilentlyContinue)) {
        throw 'Load the existing VirtuSphere-ClientPackaging.ps1 owner before building a bundle.'
    }
    $specs = @(Get-VsClientAppSpecs)
    Assert-VsClientBundleSpecs -Specs $specs
    $specHash = Get-VsClientSpecHash -Specs $specs
    $sourceManifest = @(Get-VsClientSourceManifest -SourceDir $SourceDir -Specs $specs)
    $sourceManifestHash = Get-VsClientManifestHash -Manifest $sourceManifest -Domain 'virtusphere-client-source-manifest-v1'
    $bundleId = New-VsClientBundleId -PlanId $PlanId -ConfigHash $ConfigHash -SpecHash $specHash -SourceManifestHash $sourceManifestHash
    $stageRoot = Join-Path $PackagesBase ('.virtusphere-client-bundle-stage-' + $bundleId + '-' + [guid]::NewGuid().ToString('N'))
    if ([IO.Path]::GetPathRoot([IO.Path]::GetFullPath($stageRoot)) -cne [IO.Path]::GetPathRoot([IO.Path]::GetFullPath($PackagesBase))) {
        throw 'Bundle stage must be on the PackagesBase volume.'
    }
    $bootstrap = @{
        Schema         = 2
        WebAPI         = [string]$BootstrapConfiguration.WebAPI
        Scheme         = [string]$BootstrapConfiguration.Scheme
        CertThumbprint = [string]$BootstrapConfiguration.CertThumbprint
        BundleId       = $bundleId
    }
    Invoke-VsClientFileSystemScope -VsScopePath $PackagesBase -VsScopeBody {
        New-Item -ItemType Directory -Path $stageRoot -ErrorAction Stop | Out-Null
        Write-Host ('[0/{0}] RUN client-bundle-content' -f $specs.Count)
        for ($index = 0; $index -lt $specs.Count; $index++) {
            $ordinal = $index + 1
            $spec = $specs[$index]
            Write-Host ('[{0}/{1}] RUN client-bundle {2}' -f $ordinal, $specs.Count, $spec.AppName)
            try {
                $null = Copy-VsClientContent -Spec $spec -SourceDir $SourceDir -PackagesBase $stageRoot -Bootstrap $bootstrap
                Write-Host ('[{0}/{1}] DONE client-bundle {2}' -f $ordinal, $specs.Count, $spec.AppName)
            } catch {
                Write-Host ('[{0}/{1}] FAIL client-bundle {2}' -f $ordinal, $specs.Count, $spec.AppName)
                throw
            }
        }
    }
    $validation = Get-VsClientBundleValidation -Root $stageRoot -Specs $specs -BundleId $bundleId -BootstrapConfiguration $BootstrapConfiguration
    if ($validation.Status -ne 'ready') { throw ('Bundle stage is not ready: ' + ($validation.Findings -join ', ')) }
    [pscustomobject][ordered]@{
        Status             = 'ready'
        StageRoot          = $stageRoot
        BundleId           = $bundleId
        PlanId             = $PlanId
        ConfigHash         = $ConfigHash
        SpecHash           = $specHash
        SourceManifest     = $sourceManifest
        SourceManifestHash = $sourceManifestHash
        Manifest           = $validation.Manifest
        ManifestHash       = $validation.ManifestHash
        Specs              = $specs
        Bootstrap          = $bootstrap
    }
}

function Get-VsClientCompletionEvidenceStatus {
    param(
        [Parameter(Mandatory)][object[]]$Specs,
        [Parameter(Mandatory)][object[]]$CmDefinitions,
        [Parameter(Mandatory)][string[]]$ExpectedDistributionPoints,
        [Parameter(Mandatory)][object[]]$DistributionEvidence
    )
    Assert-VsClientBundleSpecs -Specs $Specs
    $findings = @()
    $unknown = $false
    if ($CmDefinitions.Count -ne 4) { $findings += 'cm-definition-count' }
    foreach ($spec in $Specs) {
        $definitions = @($CmDefinitions | Where-Object { $_.PSObject.Properties['AppName'] -and [string]$_.AppName -ceq [string]$spec.AppName })
        if ($definitions.Count -ne 1) { $findings += ('cm-definition:{0}' -f $spec.AppName); continue }
        $definition = $definitions[0]
        foreach ($property in @('ApplicationId', 'DeploymentTypeId', 'DefinitionHash', 'ContentId', 'State')) {
            if (-not $definition.PSObject.Properties[$property] -or [string]::IsNullOrWhiteSpace([string]$definition.$property)) {
                $findings += ('cm-definition:{0}:{1}' -f $spec.AppName, $property)
            }
        }
        if ($definition.PSObject.Properties['DefinitionHash'] -and [string]$definition.DefinitionHash -cnotmatch '^[0-9a-f]{64}$') {
            $findings += ('cm-definition:{0}:definition-hash' -f $spec.AppName)
        }
        foreach ($property in @('Role', 'DependsOn', 'RequiredBy')) {
            if (-not $definition.PSObject.Properties[$property]) {
                $findings += ('cm-definition:{0}:{1}' -f $spec.AppName, $property)
            }
        }
        if ($definition.PSObject.Properties['Role'] -and [string]$definition.Role -cne [string]$spec.Role) {
            $findings += ('cm-definition:{0}:role' -f $spec.AppName)
        }
        if ($definition.PSObject.Properties['DependsOn'] -and [string]$definition.DependsOn -cne [string]$spec.DependsOn) {
            $findings += ('cm-definition:{0}:dependency' -f $spec.AppName)
        }
        if ($definition.PSObject.Properties['RequiredBy']) {
            $expectedRequiredBy = @($Specs | Where-Object { [string]$_.DependsOn -ceq [string]$spec.AppName } | ForEach-Object { [string]$_.AppName } | Sort-Object)
            $actualRequiredBy = @($definition.RequiredBy | ForEach-Object { [string]$_ } | Sort-Object)
            if ($actualRequiredBy.Count -ne $expectedRequiredBy.Count -or
                @($actualRequiredBy | Where-Object { $expectedRequiredBy -cnotcontains $_ }).Count -gt 0) {
                $findings += ('cm-definition:{0}:reverse-dependency' -f $spec.AppName)
            }
        }
        if ($definition.PSObject.Properties['State']) {
            if ([string]$definition.State -eq 'unknown') { $unknown = $true }
            elseif ([string]$definition.State -ne 'confirmed') { $findings += ('cm-definition:{0}:state' -f $spec.AppName) }
        }
    }
    $targets = @($ExpectedDistributionPoints | Sort-Object -Unique)
    if ($targets.Count -ne $ExpectedDistributionPoints.Count -or $targets.Count -eq 0) { $findings += 'distribution-targets' }
    foreach ($definition in $CmDefinitions) {
        if (-not $definition.PSObject.Properties['AppName'] -or -not $definition.PSObject.Properties['ContentId']) { continue }
        foreach ($target in $targets) {
            $rows = @($DistributionEvidence | Where-Object {
                $_.PSObject.Properties['AppName'] -and $_.PSObject.Properties['ContentId'] -and $_.PSObject.Properties['DistributionPoint'] -and
                [string]$_.AppName -ceq [string]$definition.AppName -and
                [string]$_.ContentId -ceq [string]$definition.ContentId -and
                [string]$_.DistributionPoint -ceq $target
            })
            if ($rows.Count -ne 1) { $findings += ('distribution:{0}:{1}' -f $definition.AppName, $target); continue }
            if (-not $rows[0].PSObject.Properties['State']) { $findings += ('distribution:{0}:{1}:state' -f $definition.AppName, $target) }
            elseif ([string]$rows[0].State -eq 'unknown') { $unknown = $true }
            elseif ([string]$rows[0].State -ne 'ready') { $findings += ('distribution:{0}:{1}:state' -f $definition.AppName, $target) }
        }
    }
    $expectedRows = $CmDefinitions.Count * $targets.Count
    if ($DistributionEvidence.Count -ne $expectedRows) { $findings += 'distribution-evidence-count' }
    $status = if ($unknown) { 'unknown' } elseif ($findings.Count -gt 0) { 'partial' } else { 'ready' }
    [pscustomobject]@{ Status = $status; Findings = @($findings | Sort-Object -Unique) }
}

function Test-VsClientPredecessorEvidence {
    param([Parameter(Mandatory)]$Evidence)
    try {
        foreach ($property in @('BundleId', 'ArchivePath', 'ReceiptHash', 'ManifestFileHash')) {
            if (-not $Evidence.PSObject.Properties[$property] -or [string]::IsNullOrWhiteSpace([string]$Evidence.$property)) {
                return [pscustomobject]@{ Status = 'partial'; Findings = @('predecessor:' + $property) }
            }
        }
        return Invoke-VsClientFileSystemScope -VsScopePath ([string]$Evidence.ArchivePath) -VsScopeBody {
            Assert-VsClientBundleHash -Name BundleId -Value ([string]$Evidence.BundleId)
            Assert-VsClientBundleHash -Name ReceiptHash -Value ([string]$Evidence.ReceiptHash)
            Assert-VsClientBundleHash -Name ManifestFileHash -Value ([string]$Evidence.ManifestFileHash)
            $receiptPath = Join-Path $Evidence.ArchivePath 'receipt.json'
            $manifestPath = Join-Path $Evidence.ArchivePath 'manifest.json'
            if (-not (Test-Path -LiteralPath $receiptPath -PathType Leaf) -or -not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
                return [pscustomobject]@{ Status = 'partial'; Findings = @('predecessor:files') }
            }
            if ((Get-VsClientSha256HexFromFile -Path $receiptPath) -cne [string]$Evidence.ReceiptHash -or
                (Get-VsClientSha256HexFromFile -Path $manifestPath) -cne [string]$Evidence.ManifestFileHash) {
                return [pscustomobject]@{ Status = 'partial'; Findings = @('predecessor:hash') }
            }
            $receipt = Get-Content -LiteralPath $receiptPath -Raw -Encoding UTF8 -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
            $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
            if (-not $receipt.PSObject.Properties['BundleId'] -or -not $receipt.PSObject.Properties['Status'] -or
                -not $receipt.PSObject.Properties['Graph'] -or -not $receipt.PSObject.Properties['ContentManifestHash'] -or
                -not $receipt.PSObject.Properties['ManifestFileHash'] -or -not $manifest.PSObject.Properties['Files'] -or
                -not $manifest.PSObject.Properties['BundleId'] -or -not $manifest.PSObject.Properties['ManifestHash']) {
                return [pscustomobject]@{ Status = 'partial'; Findings = @('predecessor:evidence-shape') }
            }
            if ([string]$receipt.BundleId -cne [string]$Evidence.BundleId -or [string]$receipt.Status -ne 'success') {
                return [pscustomobject]@{ Status = 'partial'; Findings = @('predecessor:receipt') }
            }
            $manifestEntries = @($manifest.Files)
            if ($manifestEntries.Count -ne 16 -or
                @($receipt.Graph).Count -ne 4 -or
                [string]$manifest.BundleId -cne [string]$Evidence.BundleId -or
                [string]$manifest.ManifestHash -cne (Get-VsClientManifestHash -Manifest $manifestEntries) -or
                [string]$receipt.ContentManifestHash -cne [string]$manifest.ManifestHash -or
                [string]$receipt.ManifestFileHash -cne [string]$Evidence.ManifestFileHash) {
                return [pscustomobject]@{ Status = 'partial'; Findings = @('predecessor:manifest') }
            }
            if (@(Get-VsClientReceiptGraphIssues -Graph @($receipt.Graph)).Count -gt 0) {
                return [pscustomobject]@{ Status = 'partial'; Findings = @('predecessor:graph') }
            }
            $expectedPaths = @($manifestEntries | ForEach-Object { [string]$_.Path } | Sort-Object)
            if (@($expectedPaths | Sort-Object -Unique).Count -ne 16 -or
                @($expectedPaths | ForEach-Object { ($_ -split '/')[0] } | Sort-Object -Unique).Count -ne 4) {
                return [pscustomobject]@{ Status = 'partial'; Findings = @('predecessor:manifest-shape') }
            }
            $manifestFolders = @($expectedPaths | ForEach-Object { ($_ -split '/')[0] } | Sort-Object -Unique)
            $graphFolders = @($receipt.Graph | ForEach-Object { [string]$_.Folder } | Sort-Object -Unique)
            if (@(Compare-Object -ReferenceObject $graphFolders -DifferenceObject $manifestFolders).Count -gt 0) {
                return [pscustomobject]@{ Status = 'partial'; Findings = @('predecessor:graph-folders') }
            }
            $actualPaths = @(Get-ChildItem -LiteralPath $Evidence.ArchivePath -Recurse -File -Force -ErrorAction Stop |
                Where-Object { $_.FullName -notin @($receiptPath, $manifestPath) } |
                ForEach-Object { $_.FullName.Substring(([string]$Evidence.ArchivePath).TrimEnd('\', '/').Length).TrimStart('\', '/').Replace('\', '/') } |
                Sort-Object)
            if (@(Compare-Object -ReferenceObject $expectedPaths -DifferenceObject $actualPaths).Count -gt 0) {
                return [pscustomobject]@{ Status = 'partial'; Findings = @('predecessor:content-set') }
            }
            foreach ($entry in $manifestEntries) {
                $contentPath = Join-Path $Evidence.ArchivePath ([string]$entry.Path).Replace('/', '\')
                $item = Get-Item -LiteralPath $contentPath -Force -ErrorAction Stop
                if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 -or
                    [long]$item.Length -ne [long]$entry.Length -or
                    (Get-VsClientSha256HexFromFile -Path $item.FullName) -cne [string]$entry.Sha256) {
                    return [pscustomobject]@{ Status = 'partial'; Findings = @('predecessor:content-hash') }
                }
            }
            $bootstrapPaths = @($manifestEntries | Where-Object { [string]$_.Path -cmatch '/bootstrap\.json$' })
            if ($bootstrapPaths.Count -ne 4) {
                return [pscustomobject]@{ Status = 'partial'; Findings = @('predecessor:bootstrap-count') }
            }
            foreach ($entry in $bootstrapPaths) {
                $bootstrapPath = Join-Path $Evidence.ArchivePath ([string]$entry.Path).Replace('/', '\')
                $bootstrap = Get-Content -LiteralPath $bootstrapPath -Raw -Encoding UTF8 -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
                if ($bootstrap.Schema -ne 2 -or [string]$bootstrap.BundleId -cne [string]$Evidence.BundleId) {
                    return [pscustomobject]@{ Status = 'partial'; Findings = @('predecessor:bootstrap') }
                }
            }
            return [pscustomobject]@{ Status = 'ready'; Findings = @() }
        }
    } catch { return [pscustomobject]@{ Status = 'unknown'; Findings = @('predecessor:' + $_.Exception.Message) } }
}

function Write-VsClientUtf8NoBom {
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string]$Text)
    [IO.File]::WriteAllText($Path, $Text, (New-Object Text.UTF8Encoding($false)))
}

function Complete-VsClientBundleArchive {
    param(
        [Parameter(Mandatory)]$StageEvidence,
        [Parameter(Mandatory)][string]$ArchiveRoot,
        [Parameter(Mandatory)][object[]]$CmDefinitions,
        [Parameter(Mandatory)][string[]]$ExpectedDistributionPoints,
        [Parameter(Mandatory)][object[]]$DistributionEvidence,
        [Parameter(Mandatory)]$PredecessorEvidence,
        [Parameter(Mandatory)][string]$Actor,
        [Parameter(Mandatory)][string]$HostName,
        [Parameter(Mandatory)][DateTime]$CompletedAtUtc
    )
    if ([string]$StageEvidence.Status -ne 'ready') { throw 'Stage evidence is not ready.' }
    if ([string]::IsNullOrWhiteSpace($Actor) -or [string]::IsNullOrWhiteSpace($HostName)) { throw 'Receipt actor and host are required.' }
    $completedText = $CompletedAtUtc.ToUniversalTime().ToString('o', [Globalization.CultureInfo]::InvariantCulture)
    $configuration = @{
        WebAPI = [string]$StageEvidence.Bootstrap.WebAPI
        Scheme = [string]$StageEvidence.Bootstrap.Scheme
        CertThumbprint = [string]$StageEvidence.Bootstrap.CertThumbprint
    }
    $stageValidation = Get-VsClientBundleValidation -Root $StageEvidence.StageRoot -Specs $StageEvidence.Specs -BundleId $StageEvidence.BundleId -BootstrapConfiguration $configuration
    if ($stageValidation.Status -ne 'ready' -or $stageValidation.ManifestHash -cne [string]$StageEvidence.ManifestHash) {
        throw 'Stage changed after validation or is no longer readable.'
    }
    $completion = Get-VsClientCompletionEvidenceStatus -Specs $StageEvidence.Specs -CmDefinitions $CmDefinitions -ExpectedDistributionPoints $ExpectedDistributionPoints -DistributionEvidence $DistributionEvidence
    if ($completion.Status -ne 'ready') { throw ('Completion evidence is ' + $completion.Status + ': ' + ($completion.Findings -join ', ')) }
    $predecessor = Test-VsClientPredecessorEvidence -Evidence $PredecessorEvidence
    if ($predecessor.Status -ne 'ready') { throw ('Predecessor evidence is ' + $predecessor.Status + ': ' + ($predecessor.Findings -join ', ')) }
    $target = Join-Path $ArchiveRoot $StageEvidence.BundleId
    if (Test-Path -LiteralPath $target) { throw "Immutable bundle archive already exists: $target" }
    $temporary = Join-Path $ArchiveRoot ('.virtusphere-client-archive-' + $StageEvidence.BundleId + '-' + [guid]::NewGuid().ToString('N'))
    Invoke-VsClientFileSystemScope -VsScopePath $ArchiveRoot -VsScopeBody {
        if (-not (Test-Path -LiteralPath $ArchiveRoot -PathType Container)) { New-Item -ItemType Directory -Path $ArchiveRoot -ErrorAction Stop | Out-Null }
        New-Item -ItemType Directory -Path $temporary -ErrorAction Stop | Out-Null
        foreach ($spec in $StageEvidence.Specs) {
            Copy-Item -LiteralPath (Join-Path $StageEvidence.StageRoot $spec.Folder) -Destination $temporary -Recurse -ErrorAction Stop
        }
        $copyValidation = Get-VsClientBundleValidation -Root $temporary -Specs $StageEvidence.Specs -BundleId $StageEvidence.BundleId -BootstrapConfiguration $configuration
        if ($copyValidation.Status -ne 'ready' -or $copyValidation.ManifestHash -cne [string]$StageEvidence.ManifestHash) {
            throw 'Archive copy does not match the validated stage.'
        }
        $manifestDocument = [ordered]@{
            Schema       = 1
            BundleId     = [string]$StageEvidence.BundleId
            ManifestHash = [string]$StageEvidence.ManifestHash
            Files        = @($copyValidation.Manifest)
        }
        $manifestPath = Join-Path $temporary 'manifest.json'
        Write-VsClientUtf8NoBom -Path $manifestPath -Text ($manifestDocument | ConvertTo-Json -Depth 8)
        $manifestFileHash = Get-VsClientSha256HexFromFile -Path $manifestPath
        $receipt = [ordered]@{
            Schema                     = 1
            Status                     = 'success'
            BundleId                   = [string]$StageEvidence.BundleId
            PlanId                     = [string]$StageEvidence.PlanId
            ConfigHash                 = [string]$StageEvidence.ConfigHash
            SpecHash                   = [string]$StageEvidence.SpecHash
            SourceManifestHash         = [string]$StageEvidence.SourceManifestHash
            ContentManifestHash        = [string]$StageEvidence.ManifestHash
            ManifestFileHash           = $manifestFileHash
            CompletedAtUtc             = $completedText
            Actor                      = $Actor
            HostName                   = $HostName
            Bootstrap                  = [ordered]@{ Schema = 2; WebAPI = $configuration.WebAPI; Scheme = $configuration.Scheme; CertThumbprint = $configuration.CertThumbprint }
            Graph                      = @($StageEvidence.Specs | ForEach-Object {
                [ordered]@{ AppName = $_.AppName; Folder = $_.Folder; Role = $_.Role; DependsOn = $_.DependsOn }
            })
            CmDefinitions              = @($CmDefinitions | Sort-Object AppName)
            ExpectedDistributionPoints = @($ExpectedDistributionPoints | Sort-Object)
            DistributionEvidence       = @($DistributionEvidence | Sort-Object AppName, DistributionPoint)
            Predecessor                = [ordered]@{ BundleId = $PredecessorEvidence.BundleId; ReceiptHash = $PredecessorEvidence.ReceiptHash; ManifestFileHash = $PredecessorEvidence.ManifestFileHash }
        }
        $receiptPath = Join-Path $temporary 'receipt.json'
        Write-VsClientUtf8NoBom -Path $receiptPath -Text ($receipt | ConvertTo-Json -Depth 12)
        if (Test-Path -LiteralPath $target) { throw "Immutable bundle archive collision: $target" }
        Move-Item -LiteralPath $temporary -Destination $target -ErrorAction Stop
    }
    $finalReceipt = Join-Path $target 'receipt.json'
    [pscustomobject][ordered]@{
        Status           = 'success'
        BundleId         = [string]$StageEvidence.BundleId
        ArchivePath      = $target
        ReceiptHash      = Get-VsClientSha256HexFromFile -Path $finalReceipt
        ManifestFileHash = Get-VsClientSha256HexFromFile -Path (Join-Path $target 'manifest.json')
    }
}

function Get-VsClientBundleRetentionPlan {
    param(
        [Parameter(Mandatory)][string]$ArchiveRoot,
        [Parameter(Mandatory)][DateTime]$NowUtc,
        [string[]]$ProtectedBundleIds = @()
    )
    $policy = Get-VsClientBundlePolicy
    $inventory = @()
    try {
        $directories = @(Invoke-VsClientFileSystemScope -VsScopePath $ArchiveRoot -VsScopeBody {
            if (Test-Path -LiteralPath $ArchiveRoot -PathType Container) {
                Get-ChildItem -LiteralPath $ArchiveRoot -Directory -Force -ErrorAction Stop | Where-Object { $_.Name -cmatch '^[0-9a-f]{64}$' }
            }
        })
    } catch {
        return [pscustomobject]@{ Status = 'unknown'; Findings = @($_.Exception.Message); Items = @(); PlanHash = $null }
    }
    foreach ($directory in $directories) {
        $inventory += Invoke-VsClientFileSystemScope -VsScopePath $directory.FullName -VsScopeBody {
            try {
                $receiptPath = Join-Path $directory.FullName 'receipt.json'
                $manifestPath = Join-Path $directory.FullName 'manifest.json'
                if (-not (Test-Path -LiteralPath $receiptPath -PathType Leaf) -or -not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
                    return [pscustomobject]@{ BundleId = $directory.Name; Status = 'partial'; CompletedAtUtc = $null; Candidate = $false; Reason = 'missing-evidence'; NewerSuccessfulCount = $null; AgeFullDays = $null }
                }
                $receipt = Get-Content -LiteralPath $receiptPath -Raw -Encoding UTF8 -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
                $completed = [DateTime]::Parse([string]$receipt.CompletedAtUtc, [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::RoundtripKind).ToUniversalTime()
                if ([string]$receipt.BundleId -cne $directory.Name -or [string]$receipt.Status -ne 'success' -or
                    (Get-VsClientSha256HexFromFile -Path $manifestPath) -cne [string]$receipt.ManifestFileHash) {
                    return [pscustomobject]@{ BundleId = $directory.Name; Status = 'partial'; CompletedAtUtc = $completed; Candidate = $false; Reason = 'invalid-evidence'; NewerSuccessfulCount = $null; AgeFullDays = $null }
                }
                return [pscustomobject]@{ BundleId = $directory.Name; Status = 'success'; CompletedAtUtc = $completed; Candidate = $false; Reason = 'protected'; NewerSuccessfulCount = 0; AgeFullDays = [Math]::Floor(($NowUtc.ToUniversalTime() - $completed).TotalDays) }
            } catch {
                return [pscustomobject]@{ BundleId = $directory.Name; Status = 'unknown'; CompletedAtUtc = $null; Candidate = $false; Reason = 'unreadable-evidence'; NewerSuccessfulCount = $null; AgeFullDays = $null }
            }
        }
    }
    $successful = @($inventory | Where-Object { $_.Status -eq 'success' } | Sort-Object @{ Expression = 'CompletedAtUtc'; Descending = $true }, BundleId)
    for ($index = 0; $index -lt $successful.Count; $index++) {
        $item = $successful[$index]
        $item.NewerSuccessfulCount = $index
        $oldEnough = $item.CompletedAtUtc -lt $NowUtc.ToUniversalTime().AddDays(-$policy.MinimumAgeDays)
        $hasFiveNewer = $index -ge $policy.MinimumSuccessfulBundles
        $isProtected = @($ProtectedBundleIds | Where-Object { $_ -ceq $item.BundleId }).Count -gt 0
        if ($hasFiveNewer -and $oldEnough -and -not $isProtected) { $item.Candidate = $true; $item.Reason = 'eligible' }
        elseif ($isProtected) { $item.Reason = 'explicitly-protected' }
        elseif (-not $hasFiveNewer) { $item.Reason = 'fewer-than-five-newer-successes' }
        else { $item.Reason = 'not-older-than-180-full-days' }
    }
    $planParts = @('virtusphere-client-retention-plan-v1', $NowUtc.ToUniversalTime().ToString('o'))
    foreach ($item in @($inventory | Sort-Object BundleId)) { $planParts += @($item.BundleId, $item.Status, $item.Candidate, $item.Reason) }
    $planStatus = if (@($inventory | Where-Object { $_.Status -eq 'unknown' }).Count -gt 0) {
        'unknown'
    } elseif (@($inventory | Where-Object { $_.Status -eq 'partial' }).Count -gt 0) {
        'partial'
    } else { 'ready' }
    $planFindings = @($inventory | Where-Object { $_.Status -ne 'success' } | ForEach-Object { '{0}:{1}' -f $_.BundleId, $_.Status } | Sort-Object)
    [pscustomobject]@{ Status = $planStatus; Findings = $planFindings; Items = @($inventory | Sort-Object BundleId); PlanHash = Get-VsClientCanonicalHash -Parts $planParts }
}
