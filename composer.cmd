@echo off
REM hc-tracking - run Composer under PHP 8.5 for this project only.
REM Bypasses C:\composer\composer.bat, which resolves bare "php" from PATH.
setlocal
if "%PHP85%"=="" set "PHP85=C:\php85\php.exe"
if "%COMPOSER_PHAR%"=="" set "COMPOSER_PHAR=C:\composer\composer.phar"
if not exist "%PHP85%" (
  echo [hc-tracking] PHP 8.5 not found at "%PHP85%".
  exit /b 1
)
if not exist "%COMPOSER_PHAR%" (
  echo [hc-tracking] composer.phar not found at "%COMPOSER_PHAR%".
  exit /b 1
)
REM Same reason as php.cmd: composer scripts shell out to a bare "php".
for %%I in ("%PHP85%") do set "PHP85_DIR=%%~dpI"
set "PATH=%PHP85_DIR%;%PATH%"
"%PHP85%" "%COMPOSER_PHAR%" %*
