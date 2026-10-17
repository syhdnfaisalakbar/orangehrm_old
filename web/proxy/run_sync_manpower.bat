@echo off
rem ============================================
rem run_sync_manpower.bat
rem Wrapper untuk Windows Task Scheduler:
rem menjalankan auto-sync Manpower Request (FPTK)
rem dari Acumatica ke DB lokal, tiap 1 jam.
rem ============================================
"C:\xampp\php\php.exe" "C:\xampp\htdocs\orangehrm\web\proxy\sync_manpower_scheduled.php"