param(
    [Parameter(Mandatory = $true)]
    [string]$InputPath,

    [string]$OutputPath = "",

    [string]$Marketplace = "shoptet",

    [switch]$IncludeUnknownPrefixes
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Get-EntryText {
    param(
        [Parameter(Mandatory = $true)]
        [System.IO.Compression.ZipArchive]$Zip,

        [Parameter(Mandatory = $true)]
        [string]$EntryName
    )

    $entry = $Zip.Entries | Where-Object { $_.FullName -eq $EntryName } | Select-Object -First 1
    if (-not $entry) {
        return $null
    }

    $reader = New-Object System.IO.StreamReader($entry.Open())
    try {
        return $reader.ReadToEnd()
    }
    finally {
        $reader.Close()
    }
}

function Convert-ColumnReferenceToIndex {
    param(
        [Parameter(Mandatory = $true)]
        [string]$CellReference
    )

    $letters = ($CellReference -replace '[^A-Z]', '')
    $sum = 0
    foreach ($ch in $letters.ToCharArray()) {
        $sum = ($sum * 26) + ([int][char]$ch - [int][char]'A' + 1)
    }

    return $sum
}

function Get-ProductTypeFromCode {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Code
    )

    if ($Code -like 'GFP_*') { return 'design' }
    if ($Code -like 'G_*') { return 'design' }
    if ($Code -like 'S_*') { return 'seatcover' }

    return $null
}

if (-not (Test-Path -LiteralPath $InputPath)) {
    throw "Input file not found: $InputPath"
}

if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $inputItem = Get-Item -LiteralPath $InputPath
    $OutputPath = Join-Path $inputItem.DirectoryName ($inputItem.BaseName + '_product_listing_catalog.csv')
}

Add-Type -AssemblyName System.IO.Compression.FileSystem

$tempPath = Join-Path $env:TEMP ('shoptet_' + [Guid]::NewGuid().ToString() + '.xlsx')
$sourceStream = [System.IO.File]::Open($InputPath, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
try {
    $tempStream = [System.IO.File]::Open($tempPath, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::Write, [System.IO.FileShare]::None)
    try {
        $sourceStream.CopyTo($tempStream)
    }
    finally {
        $tempStream.Dispose()
    }
}
finally {
    $sourceStream.Dispose()
}

$zip = [System.IO.Compression.ZipFile]::OpenRead($tempPath)
try {
    $sharedStrings = @()
    $sharedXmlText = Get-EntryText -Zip $zip -EntryName 'xl/sharedStrings.xml'
    if ($sharedXmlText) {
        [xml]$sharedXml = $sharedXmlText
        foreach ($si in $sharedXml.sst.si) {
            $hasSimpleText = $si.PSObject.Properties.Name -contains 't'
            $hasRichText = $si.PSObject.Properties.Name -contains 'r'

            if ($hasSimpleText) {
                $sharedStrings += [string]$si.t
            }
            elseif ($hasRichText) {
                $parts = @()
                foreach ($run in $si.r) {
                    if ($run.PSObject.Properties.Name -contains 't') {
                        $parts += [string]$run.t
                    }
                }
                $sharedStrings += ($parts -join '')
            }
            else {
                $sharedStrings += ''
            }
        }
    }

    [xml]$workbookXml = Get-EntryText -Zip $zip -EntryName 'xl/workbook.xml'
    [xml]$relsXml = Get-EntryText -Zip $zip -EntryName 'xl/_rels/workbook.xml.rels'

    $relationshipMap = @{}
    foreach ($relationship in $relsXml.Relationships.Relationship) {
        $relationshipMap[[string]$relationship.Id] = [string]$relationship.Target
    }

    $sheetNode = @($workbookXml.workbook.sheets.sheet)[0]
    if (-not $sheetNode) {
        throw "Workbook does not contain any worksheet."
    }

    $sheetRid = $sheetNode.GetAttribute('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')
    $sheetTarget = $relationshipMap[$sheetRid]
    if (-not $sheetTarget) {
        throw "Cannot resolve first worksheet relationship."
    }

    if ($sheetTarget -notmatch '^xl/') {
        $sheetTarget = 'xl/' + $sheetTarget
    }

    [xml]$sheetXml = Get-EntryText -Zip $zip -EntryName $sheetTarget
    $sheetRows = @($sheetXml.worksheet.sheetData.row)
    if ($sheetRows.Count -lt 2) {
        throw "Workbook does not contain any data rows."
    }

    $headerMap = @{}
    foreach ($cell in @($sheetRows[0].c)) {
        $columnIndex = Convert-ColumnReferenceToIndex -CellReference ([string]$cell.GetAttribute('r'))
        $headerValue = ''
        if ([string]$cell.GetAttribute('t') -eq 's') {
            $headerValue = $sharedStrings[[int]$cell.v]
        }
        else {
            $headerValue = [string]$cell.v
        }
        $headerMap[$columnIndex] = $headerValue
    }

    $maxHeaderIndex = ($headerMap.Keys | Measure-Object -Maximum).Maximum
    $selectedColumns = @{}
    for ($index = 1; $index -le $maxHeaderIndex; $index++) {
        $headerName = [string]$headerMap[$index]
        if (
            $headerName -eq 'code' -or
            $headerName -eq 'name' -or
            $headerName -eq 'itemType' -or
            $headerName -eq 'externalCode' -or
            $headerName -like 'categoryText*'
        ) {
            $selectedColumns[$index] = $headerName
        }
    }

    $outputRows = New-Object System.Collections.Generic.List[object]
    $skippedRows = New-Object System.Collections.Generic.List[object]
    $uniqueRows = New-Object 'System.Collections.Generic.HashSet[string]' ([System.StringComparer]::OrdinalIgnoreCase)
    $prefixStats = @{}

    for ($rowIndex = 1; $rowIndex -lt $sheetRows.Count; $rowIndex++) {
        $row = $sheetRows[$rowIndex]
        $rowData = @{}

        foreach ($selectedColumn in $selectedColumns.GetEnumerator()) {
            $rowData[$selectedColumn.Value] = ''
        }

        foreach ($cell in @($row.c)) {
            $columnIndex = Convert-ColumnReferenceToIndex -CellReference ([string]$cell.GetAttribute('r'))
            if (-not $selectedColumns.ContainsKey($columnIndex)) {
                continue
            }

            $value = ''
            $type = [string]$cell.GetAttribute('t')
            if ($type -eq 's') {
                $value = $sharedStrings[[int]$cell.v]
            }
            elseif ($type -eq 'inlineStr') {
                $value = [string]$cell.is.t
            }
            else {
                $value = [string]$cell.v
            }

            $rowData[$selectedColumns[$columnIndex]] = $value
        }

        $code = [string]$rowData['code']
        $name = [string]$rowData['name']
        $itemType = [string]$rowData['itemType']
        $externalCode = [string]$rowData['externalCode']

        if ([string]::IsNullOrWhiteSpace($code) -and [string]::IsNullOrWhiteSpace($name)) {
            continue
        }

        if ($itemType -ne '' -and $itemType -ne 'product') {
            $skippedRows.Add([pscustomobject]@{
                code = $code
                reason = "itemType:$itemType"
            }) | Out-Null
            continue
        }

        $prefix = ''
        if ($code -match '^([A-Z0-9]+_)') {
            $prefix = $matches[1].ToUpperInvariant()
        }

        if ($prefix -ne '') {
            if (-not $prefixStats.ContainsKey($prefix)) {
                $prefixStats[$prefix] = 0
            }
            $prefixStats[$prefix]++
        }

        $productType = Get-ProductTypeFromCode -Code $code
        if (-not $productType) {
            if (-not $IncludeUnknownPrefixes) {
                $skippedRows.Add([pscustomobject]@{
                    code = $code
                    reason = 'unknown_prefix'
                }) | Out-Null
                continue
            }

            $productType = 'design'
        }

        $modelCodes = New-Object 'System.Collections.Generic.HashSet[string]' ([System.StringComparer]::OrdinalIgnoreCase)
        foreach ($columnName in $rowData.Keys) {
            if ($columnName -notlike 'categoryText*') {
                continue
            }

            $categoryValue = [string]$rowData[$columnName]
            if ([string]::IsNullOrWhiteSpace($categoryValue)) {
                continue
            }

            if ($categoryValue -notmatch 'Shop by bike') {
                continue
            }

            $matches = [System.Text.RegularExpressions.Regex]::Matches($categoryValue, '\(([A-Z0-9]+)\)')
            foreach ($match in $matches) {
                $modelCode = [string]$match.Groups[1].Value
                if (-not [string]::IsNullOrWhiteSpace($modelCode)) {
                    [void]$modelCodes.Add($modelCode.Trim().ToUpperInvariant())
                }
            }
        }

        if ($modelCodes.Count -eq 0) {
            $skippedRows.Add([pscustomobject]@{
                code = $code
                reason = 'no_model_code_in_categoryText'
            }) | Out-Null
            continue
        }

        foreach ($modelCode in $modelCodes) {
            $uniqueKey = ($code + '|' + $modelCode + '|' + $Marketplace).ToUpperInvariant()
            if (-not $uniqueRows.Add($uniqueKey)) {
                continue
            }

            $outputRows.Add([pscustomobject]@{
                product_type  = $productType
                product_code  = $code
                product_name  = $name
                model_code    = $modelCode
                marketplace   = $Marketplace
                external_code = $externalCode
                external_url  = ''
                listing_title = $name
                is_active     = 1
            }) | Out-Null
        }
    }

    $outputRows |
        Sort-Object product_type, product_code, model_code |
        Export-Csv -LiteralPath $OutputPath -NoTypeInformation -Encoding UTF8

    $skippedPath = [System.IO.Path]::ChangeExtension($OutputPath, '.skipped.csv')
    $skippedRows | Export-Csv -LiteralPath $skippedPath -NoTypeInformation -Encoding UTF8

    Write-Host ''
    Write-Host 'Shoptet XLSX conversion finished.' -ForegroundColor Green
    Write-Host ('Input rows:      ' + ($sheetRows.Count - 1))
    Write-Host ('Output rows:     ' + $outputRows.Count)
    Write-Host ('Skipped rows:    ' + $skippedRows.Count)
    Write-Host ('Output CSV:      ' + $OutputPath)
    Write-Host ('Skipped CSV:     ' + $skippedPath)

    if ($prefixStats.Count -gt 0) {
        Write-Host ''
        Write-Host 'Detected code prefixes:' -ForegroundColor Cyan
        foreach ($stat in $prefixStats.GetEnumerator() | Sort-Object Name) {
            Write-Host ('- ' + $stat.Key + ': ' + $stat.Value)
        }
    }

    Write-Host ''
    Write-Host 'Recognized product type mapping:' -ForegroundColor Cyan
    Write-Host '- G_   => design'
    Write-Host '- GFP_ => design'
    Write-Host '- S_   => seatcover'
    Write-Host '- everything else is skipped by default'
}
finally {
    $zip.Dispose()
    Remove-Item -LiteralPath $tempPath -Force -ErrorAction SilentlyContinue
}
