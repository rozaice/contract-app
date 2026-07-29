$ftpHost = "vh436.timeweb.ru"
$ftpUser = "ci70153"
$remoteDir = "/ci70153/contract-app/"

# Запрашиваем пароль
$secPassword = Read-Host "Введите пароль от FTP" -AsSecureString
$credential = New-Object System.Net.NetworkCredential($ftpUser, $secPassword)

# Файлы для загрузки (относительно папки скрипта)
$files = @(
    "config.php",
    "bitrix24.php",
    "docx.php",
    "index.php",
    "handler.php",
    "install.php",
    ".htaccess",
    "storage/.htaccess"
)

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path

# Создаём удалённые директории
function Create-FtpDir($path) {
    try {
        $req = [System.Net.WebRequest]::Create("ftp://$ftpHost$path")
        $req.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
        $req.Credentials = $credential
        $req.UsePassive = $true
        $null = $req.GetResponse()
        Write-Host "  ✓ Создана папка $path" -ForegroundColor Green
    } catch {
        # папка уже существует — норм
    }
}

Write-Host "Создаю удалённые директории..." -ForegroundColor Yellow
Create-FtpDir $remoteDir
Create-FtpDir "${remoteDir}storage"
Create-FtpDir "${remoteDir}storage/tmp"

Write-Host "Загружаю файлы..." -ForegroundColor Yellow
foreach ($localFile in $files) {
    $localPath = Join-Path $scriptDir $localFile
    $remoteFile = $localFile -replace '\\', '/'
    $remotePath = $remoteDir + $remoteFile

    if (-not (Test-Path $localPath)) {
        Write-Host "  ⚠ Пропущен: $localFile (не найден)" -ForegroundColor DarkYellow
        continue
    }

    try {
        $request = [System.Net.WebRequest]::Create("ftp://$ftpHost$remotePath")
        $request.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
        $request.Credentials = $credential
        $request.UsePassive = $true
        $request.UseBinary = $true

        $fileBytes = [System.IO.File]::ReadAllBytes($localPath)
        $request.ContentLength = $fileBytes.Length

        $stream = $request.GetRequestStream()
        $stream.Write($fileBytes, 0, $fileBytes.Length)
        $stream.Close()

        $response = $request.GetResponse()
        $response.Close()

        Write-Host "  ✓ $localFile" -ForegroundColor Green
    }
    catch {
        Write-Host "  ✗ $localFile : $($_.Exception.Message)" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "Готово! Приложение доступно по адресу:" -ForegroundColor Cyan
Write-Host "http://ci70153.vh436.timeweb.ru/contract-app/" -ForegroundColor White
