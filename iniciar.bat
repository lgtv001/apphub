@echo off
echo ─────────────────────────────────────
echo  AppHub — Iniciando servidor local
echo ─────────────────────────────────────

set BACKEND=C:\Users\luisg\Documents\GitHub\apphub\.worktrees\quiebre-contrato\backend
set NGROK=C:\Users\luisg\Downloads\ngrok\ngrok.exe
set PHP=C:\xampp\php\php.exe

echo [1/3] Iniciando Laravel en puerto 8000...
start "AppHub Backend" cmd /k "cd /d %BACKEND% && %PHP% artisan serve --port=8000"

echo [2/3] Esperando que Laravel arranque...
timeout /t 6 >nul

echo [3/3] Iniciando ngrok...
start "AppHub Ngrok" cmd /k "%NGROK% http 8000"

timeout /t 2 >nul

echo Abriendo app...
start "" "http://localhost:8000/index.html"

echo.
echo ─────────────────────────────────────
echo  Local:   http://localhost:8000/index.html
echo  Ngrok:   revisa la ventana de ngrok para la URL publica
echo ─────────────────────────────────────
pause
