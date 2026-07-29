@echo off
setlocal EnableExtensions EnableDelayedExpansion

title Export Selected Tables From NAS MariaDB

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

REM Ak je mysqldump inde, treba upravit cestu:
set MYSQLDUMP_EXE=C:\mysql_tools\bin\mysqldump.exe

REM =========================================================
REM EDITOVATELNY ZOZNAM TABULIEK
REM Jednoducho pridaj alebo zmaz cely riadok: set TBL[nazov]=1
REM =========================================================

set TBL[archive_inventory_movements]=1
set TBL[attdn_2026]=1
set TBL[categories]=1
set TBL[chat_attachments]=1
set TBL[chat_messages]=1
set TBL[chat_threads]=1
set TBL[chat_thread_members]=1
set TBL[customers]=1
set TBL[disassembled_kits]=1
set TBL[employees]=1
set TBL[intake_label_queue]=1
set TBL[inventory_movements]=1
set TBL[invoices]=1
set TBL[items]=1
set TBL[listings]=1
set TBL[orders]=1
set TBL[order_activity]=1
set TBL[order_addresses]=1
set TBL[order_assignments]=1
set TBL[order_categories]=1
set TBL[order_invoices]=1
set TBL[order_items]=1
set TBL[order_item_assignments]=1
set TBL[order_item_categories]=1
set TBL[order_item_statuses]=1
set TBL[order_sources]=1
set TBL[order_tracking_numbers]=1
set TBL[plastics_orders]=1
set TBL[plastics_stock]=1
set TBL[position]=1
set TBL[schedules]=1
set TBL[scrubcompat]=1
set TBL[scrubdata]=1
set TBL[scrub_listings]=1
set TBL[scrub_listing_items]=1
set TBL[shelves]=1
set TBL[shipments]=1
set TBL[stock_levels]=1

REM =========================================================
REM KONTROLY
REM =========================================================

if not exist "%MYSQLDUMP_EXE%" (
    echo [ERROR] mysqldump.exe nenajdeny:
    echo %MYSQLDUMP_EXE%
    pause
    exit /b 1
)

if not exist "%EXPORT_DIR%" (
    mkdir "%EXPORT_DIR%"
)

for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd_HH-mm-ss"') do set TS=%%i

set RAW_DUMP=%EXPORT_DIR%\%SRC_DB%_selected_%TS%_raw.sql
set FINAL_DUMP=%EXPORT_DIR%\%SRC_DB%_selected_%TS%.sql

REM =========================================================
REM VYTVORENIE ZOZNAMU TABULIEK
REM =========================================================

set TABLE_LIST=

for %%V in (
    archive_inventory_movements
    attdn_2026
    categories
    chat_attachments
    chat_messages
    chat_threads
    chat_thread_members
    customers
    disassembled_kits
    employees
    intake_label_queue
    inventory_movements
    invoices
    items
    listings
    orders
    order_activity
    order_addresses
    order_assignments
    order_categories
    order_invoices
    order_items
    order_item_assignments
    order_item_categories
    order_item_statuses
    order_sources
    order_tracking_numbers
    plastics_orders
    plastics_stock
    position
    schedules
    scrubcompat
    scrubdata
    scrub_listings
    scrub_listing_items
    shelves
    shipments
    stock_levels
) do (
    if defined TBL[%%V] (
        set TABLE_LIST=!TABLE_LIST! %%V
    )
)

if "%TABLE_LIST%"=="" (
    echo [ERROR] Neboli vybrane ziadne tabulky.
    pause
    exit /b 1
)

echo.
echo =========================================================
echo  EXPORT VYBRANYCH TABULIEK Z NAS DO SQL SUBORU
echo =========================================================
echo.
echo Host   : %SRC_HOST%
echo Port   : %SRC_PORT%
echo DB     : %SRC_DB%
echo Output : %FINAL_DUMP%
echo.
echo Tables :
for %%T in (%TABLE_LIST%) do echo   - %%T
echo.

REM =========================================================
REM KROK 1 - RAW DUMP
REM =========================================================

echo [1/2] Exportujem vybrane tabuky z NAS...

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
  %SRC_DB% %TABLE_LIST% > "%RAW_DUMP%"

if errorlevel 1 (
    echo [ERROR] Export failed.
    pause
    exit /b 1
)

echo [OK] Raw dump vytvoreny:
echo %RAW_DUMP%
echo.

REM =========================================================
REM KROK 2 - WRAP PRE BEZPECNEJSI IMPORT
REM =========================================================

echo [2/2] Pripravujem import-safe SQL subor...

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
echo Final SQL:
echo %FINAL_DUMP%
echo.
pause
exit /b 0