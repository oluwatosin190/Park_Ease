@echo off
echo Setting up ParkEase Cron Jobs...
echo.

:: Create scheduled tasks for both cron jobs
schtasks /create /tn "ParkEaseExpiryCheck" /tr "php C:\xampp\htdocs\Park_Ease\parkease\cron\check-expired.php" /sc minute /mo 1 /f
schtasks /create /tn "ParkEaseReminders" /tr "php C:\xampp\htdocs\Park_Ease\parkease\cron\send-reminders.php" /sc minute /mo 1 /f

echo.
echo Cron jobs created successfully!
echo They will run every minute.
pause