@echo off
REM hc-tracking - run PHP 8.5 for this project only.
REM XAMPP's PHP (8.4.12) stays the machine default; nothing here is on the
REM system PATH. Override the interpreter by setting PHP85 before calling.
setlocal
if "%PHP85%"=="" set "PHP85=C:\php85\php.exe"
if not exist "%PHP85%" (
  echo [hc-tracking] PHP 8.5 not found at "%PHP85%".
  echo Install PHP 8.5 to C:\php85, or set PHP85 to your php.exe.
  exit /b 1
)
REM Prepend PHP 8.5 to PATH for child processes only. Composer scripts and the
REM vendor\bin\*.bat shims invoke a bare "php"; without this they would pick up
REM XAMPP's 8.4.12 and be rejected by the platform guard.
for %%I in ("%PHP85%") do set "PHP85_DIR=%%~dpI"
set "PATH=%PHP85_DIR%;%PATH%"
"%PHP85%" %*
