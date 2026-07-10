Set WshShell = CreateObject("WScript.Shell")
WshShell.CurrentDirectory = "C:\AlarmSystem"
WshShell.Run "python scada_takip.py", 0, false
