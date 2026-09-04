@echo off
REM hc-tracking - run PHP 8.5 for this project only.
REM XAMPP's PHP (8.4.12) stays the machine default; nothing here is on PATH.
REM Override the interpreter by setting PHP85 before calling.
setlocal
if "%PHP85%"=="" set "PHP85=C:\php85\php.exe"
if not exist "%PHP85%" (
  echo [hc-tracking] PHP 8.5 not found at "%PHP85%".
  echo Install PHP 8.5 to C:\php85, or set PHP85 to your php.exe.
  exit /b 1
)
"%PHP85%" %*
