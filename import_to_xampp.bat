@echo off
setlocal EnableExtensions EnableDelayedExpansion

title Import SQL Dump To Local XAMPP

REM =========================================================
REM CONFIG
REM =========================================================

set DST_HOST=127.0.0.1
set DST_PORT=3306
set DST_DB=scrubproduction
set DST_USER=root
set DST_PASS=

REM SEM SI DAS CESTU K SQL SUBORU Z PRACE
set IMPORT_FILE=C:\mysql_tools\scrubproduction.sql

REM KDE SA ULOZI BACKUP LOKALNEJ DB PRED PREPISOM
set BACKUP_DIR=C:\mysql_tools\backup

REM Ak mas mysql/mysqldump inde, uprav si cesty:
set MYSQL_EXE=C:\xampp\mysql\bin\mysql.exe
set MYSQLDUMP_EXE=C:\xampp\mysql\bin\mysqldump.exe

REM =========================================================
REM CHECKS
REM =========================================================

if not exist "%MYSQL_EXE%" (
    echo [ERROR] mysql.exe not found:
    echo %MYSQL_EXE%
    pause
    exit /b 1
)

if not exist "%MYSQLDUMP_EXE%" (
    echo [ERROR] mysqldump.exe not found:
    echo %MYSQLDUMP_EXE%
    pause
    exit /b 1
)

if not exist "%IMPORT_FILE%" (
    echo [ERROR] Import file not found:
    echo %IMPORT_FILE%
    pause
    exit /b 1
)

if not exist "%BACKUP_DIR%" (
    mkdir "%BACKUP_DIR%"
)

for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd_HH-mm-ss"') do set TS=%%i
set LOCAL_BACKUP_FILE=%BACKUP_DIR%\%DST_DB%_local_backup_%TS%.sql
set COMPAT_FILE=%TEMP%\%DST_DB%_xampp_compat_%TS%.sql

echo.
echo =========================================================
echo   IMPORT SQL TO LOCAL XAMPP
echo =========================================================
echo.
echo Target DB : %DST_DB%
echo SQL file  : %IMPORT_FILE%
echo Backup    : %LOCAL_BACKUP_FILE%
echo.

REM =========================================================
REM STEP 1 - BACKUP LOCAL DB
REM =========================================================

echo [1/3] Backing up current local database...

if defined DST_PASS (
    "%MYSQLDUMP_EXE%" ^
      --host=%DST_HOST% ^
      --port=%DST_PORT% ^
      --user=%DST_USER% ^
      --password=%DST_PASS% ^
      --default-character-set=utf8mb4 ^
      --single-transaction ^
      --skip-lock-tables ^
      --routines ^
      --triggers ^
      --events ^
      %DST_DB% > "%LOCAL_BACKUP_FILE%"
) else (
    "%MYSQLDUMP_EXE%" ^
      --host=%DST_HOST% ^
      --port=%DST_PORT% ^
      --user=%DST_USER% ^
      --default-character-set=utf8mb4 ^
      --single-transaction ^
      --skip-lock-tables ^
      --routines ^
      --triggers ^
      --events ^
      %DST_DB% > "%LOCAL_BACKUP_FILE%"
)

if errorlevel 1 (
    echo [ERROR] Local backup failed.
    pause
    exit /b 1
)

echo [OK] Backup created.
echo.

REM =========================================================
REM STEP 2 - CREATE A XAMPP-COMPATIBLE COPY OF THE DUMP
REM =========================================================

echo [2/3] Converting unsupported collations for local XAMPP...

REM MariaDB 11.x dumps can contain UCA 14.0 and utf8mb3 names which
REM MariaDB 10.4 in XAMPP does not know. The original dump is not changed.
powershell -NoProfile -Command "$reader = [System.IO.StreamReader]::new($env:IMPORT_FILE, [System.Text.Encoding]::UTF8, $true); $writer = [System.IO.StreamWriter]::new($env:COMPAT_FILE, $false, [System.Text.UTF8Encoding]::new($false)); $changes = 0; try { while (($line = $reader.ReadLine()) -ne $null) { $newLine = [regex]::Replace($line, '(?i)(COLLATE(?:=|\s+))utf8mb4_uca1400_ai_ci', '${1}utf8mb4_unicode_ci'); $newLine = [regex]::Replace($newLine, '(?i)(COLLATE(?:=|\s+))utf8mb3_uca1400_ai_ci', '${1}utf8_unicode_ci'); $newLine = [regex]::Replace($newLine, '(?i)(COLLATE(?:=|\s+))utf8mb3_', '${1}utf8_'); $newLine = [regex]::Replace($newLine, '(?i)(CHARACTER SET\s+)utf8mb3\b', '${1}utf8'); $newLine = [regex]::Replace($newLine, '(?i)(CHARSET=)utf8mb3\b', '${1}utf8'); $newLine = [regex]::Replace($newLine, '(?i)(SET NAMES\s+)utf8mb3\b', '${1}utf8'); if ($newLine -cne $line) { $changes++ }; $writer.WriteLine($newLine) } } finally { $reader.Dispose(); $writer.Dispose() }; Write-Host ('[OK] Compatible dump created. Changed lines: ' + $changes)"

if errorlevel 1 (
    echo [ERROR] Could not create a compatible dump.
    if exist "%COMPAT_FILE%" del /q "%COMPAT_FILE%" >nul 2>&1
    pause
    exit /b 1
)

echo.

REM =========================================================
REM STEP 3 - IMPORT
REM =========================================================

echo [3/3] Importing SQL into local XAMPP...

if defined DST_PASS (
    "%MYSQL_EXE%" ^
  --host=%DST_HOST% ^
  --port=%DST_PORT% ^
  --user=%DST_USER% ^
  --password=%DST_PASS% ^
  --default-character-set=utf8mb4 ^
  %DST_DB% < "%COMPAT_FILE%"
) else (
    "%MYSQL_EXE%" ^
  --host=%DST_HOST% ^
  --port=%DST_PORT% ^
  --user=%DST_USER% ^
  --default-character-set=utf8mb4 ^
  %DST_DB% < "%COMPAT_FILE%"
)

if errorlevel 1 (
    if exist "%COMPAT_FILE%" del /q "%COMPAT_FILE%" >nul 2>&1
    echo [ERROR] Import failed.
    echo Backup is here:
    echo %LOCAL_BACKUP_FILE%
    pause
    exit /b 1
)

if exist "%COMPAT_FILE%" del /q "%COMPAT_FILE%" >nul 2>&1

echo.
echo =========================================================
echo   IMPORT COMPLETED
echo =========================================================
echo.
echo Backup before import:
echo %LOCAL_BACKUP_FILE%
echo.
pause
exit /b 0
