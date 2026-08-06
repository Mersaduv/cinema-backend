' Hidden launcher for cinema_queue_worker.bat
' Avoids an interactive console that can receive Ctrl+C and kill the worker.
' Skips start if a cinema worker is already running (no duplicate workers).

Option Explicit
Dim sh, bat, fso, dir, detect, rc
Set fso = CreateObject("Scripting.FileSystemObject")
Set sh = CreateObject("WScript.Shell")
dir = fso.GetParentFolderName(WScript.ScriptFullName)
bat = dir & "\cinema_queue_worker.bat"
detect = dir & "\cinema_detect_worker.ps1"

If Not fso.FileExists(bat) Then
  WScript.Quit 2
End If

If fso.FileExists(detect) Then
  rc = sh.Run("powershell.exe -NoProfile -ExecutionPolicy Bypass -File """ & detect & """", 0, True)
  If rc = 0 Then
    ' Already running
    WScript.Quit 0
  End If
End If

' 0 = hidden window, False = do not wait
sh.Run """" & bat & """", 0, False
WScript.Quit 0
