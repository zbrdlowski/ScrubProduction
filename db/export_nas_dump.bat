@echo off
setlocal EnableExtensions EnableDelayedExpansion

title Export NAS MariaDB Dump

REM =========================================================
REM CONFIG
REM =========================================================

set SRC_HOST=192.168.100.10
set SRC_PORT=3306
set SRC_DB=scrubproduction
set SRC_USER=root
set SRC_PASS=123Admin456*

REM UPRAV SI CESTU
set EXPORT_DIR=C:\mariadb_dump

REM Ak mas mysqldump inde, uprav si cestu:
set MYSQLDUMP_EXE=C:\mysql_tools\bin\mysqldump.exe

REM =========================================================
REM CHECKS
REM =========================================================

if not exist "%MYSQLDUMP_EXE%" (
    echo [ERROR] mysqldump.exe not found:
    echo %MYSQLDUMP_EXE%
    pause
    exit /b 1
)

if not exist "%EXPORT_DIR%" (
    mkdir "%EXPORT_DIR%"
)

for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd_HH-mm-ss"') do set TS=%%i

set RAW_DUMP=%EXPORT_DIR%\%SRC_DB%_%TS%_raw.sql
set FINAL_DUMP=%EXPORT_DIR%\%SRC_DB%_%TS%.sql

echo.
echo =========================================================
echo   EXPORT NAS DB DO SQL SUBORU
echo =========================================================
echo.
echo Host: %SRC_HOST%
echo Port: %SRC_PORT%
echo DB  : %SRC_DB%
echo Out : %FINAL_DUMP%
echo.

REM =========================================================
REM STEP 1 - RAW DUMP
REM =========================================================

echo [1/2] Exportujem Databazu z NAS...

"%MYSQLDUMP_EXE%" ^
  --host=%SRC_HOST% ^
  --port=%SRC_PORT% ^
  --user=%SRC_USER% ^
  --password=%SRC_PASS% ^
  --default-character-set=utf8mb4 ^
  --single-transaction ^
  --skip-lock-tables ^
  --routines ^
  --triggers ^
  --events ^
  --add-drop-table ^
  --create-options ^
  --extended-insert ^
  --set-charset ^
  --databases %SRC_DB% > "%RAW_DUMP%"

if errorlevel 1 (
    echo [ERROR] Export z NAS zlyhal.
    pause
    exit /b 1
)

echo [OK] Raw dump vytvoreny:
echo %RAW_DUMP%
echo.

REM =========================================================
REM STEP 2 - WRAP FOR SAFER IMPORT
REM =========================================================

echo [2/2] Pripravujem finalny import-safe SQL subor...

(
    echo SET FOREIGN_KEY_CHECKS=0;
    echo SET UNIQUE_CHECKS=0;
    echo SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
    echo SET AUTOCOMMIT=0;
    echo START TRANSACTION;
    type "%RAW_DUMP%"
    echo COMMIT;
    echo SET FOREIGN_KEY_CHECKS=1;
    echo SET UNIQUE_CHECKS=1;
) > "%FINAL_DUMP%"

if errorlevel 1 (
    echo [ERROR] Nepodarilo sa pripravit finalny SQL subor.
    pause
    exit /b 1
)

del "%RAW_DUMP%" >nul 2>&1

echo.
echo =========================================================
echo   EXPORT KOMPLETNY
echo =========================================================
echo.
echo Finalny SQL:
echo %FINAL_DUMP%
echo.
pause
exit /b 0