@echo off
setlocal
cd /d "%~dp0"

rem Prefer the standard XAMPP PHP path; fall back to PHP on PATH.
set "PHP_EXE="
if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
if not defined PHP_EXE (
  for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP_EXE set "PHP_EXE=%%P"
)
if not defined PHP_EXE (
  echo ERROR: PHP was not found.
  echo Install XAMPP or add php.exe to your Windows PATH.
  if not defined CI pause
  exit /b 1
)

"%PHP_EXE%" -r "if (PHP_VERSION_ID < 80100) { fwrite(STDERR, 'ERROR: PHP 8.1 or newer is required.'.PHP_EOL); exit(1); } if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, 'ERROR: Enable pdo_sqlite in php.ini.'.PHP_EOL); exit(1); }"
if errorlevel 1 (
  if not defined CI pause
  exit /b 1
)

rem Generate the setup key through a temporary file. This avoids cmd.exe's
rem nested-quote parsing bug when PHP_EXE contains a quoted path.
set "SETUP_KEY_FILE=%TEMP%\project-dashboard-setup-key-%RANDOM%-%RANDOM%.txt"
"%PHP_EXE%" -r "echo bin2hex(random_bytes(32));" > "%SETUP_KEY_FILE%"
if errorlevel 1 (
  del "%SETUP_KEY_FILE%" >nul 2>&1
  echo ERROR: Could not generate the first-admin setup key.
  if not defined CI pause
  exit /b 1
)
set /p "PROJECT_DASHBOARD_SETUP_KEY="<"%SETUP_KEY_FILE%"
del "%SETUP_KEY_FILE%" >nul 2>&1
if not defined PROJECT_DASHBOARD_SETUP_KEY (
  echo ERROR: Could not generate the first-admin setup key.
  if not defined CI pause
  exit /b 1
)

echo.
echo ============================================================
echo Project Control Dashboard - Local Development Server
echo ============================================================
echo First-admin setup key (copy this into the browser form):
echo %PROJECT_DASHBOARD_SETUP_KEY%
echo.
echo Open: http://127.0.0.1:8000
echo Keep this window open while using the dashboard.
echo Press Ctrl+C to stop the server.
echo ============================================================
echo.

"%PHP_EXE%" -S 127.0.0.1:8000 router.php
endlocal
