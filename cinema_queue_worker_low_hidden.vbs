' Hidden launcher for cinema_queue_worker_low.bat
' Skips start if a low worker php OR wrapper bat is already running.
Option Explicit
Dim sh, bat, fso, dir, wmi, procs, proc, cmd, already
Set fso = CreateObject("Scripting.FileSystemObject")
Set sh = CreateObject("WScript.Shell")
dir = fso.GetParentFolderName(WScript.ScriptFullName)
bat = dir & "\cinema_queue_worker_low.bat"

If Not fso.FileExists(bat) Then
  WScript.Quit 2
End If

already = False
On Error Resume Next
Set wmi = GetObject("winmgmts:\\.\root\cimv2")
Set procs = wmi.ExecQuery("Select Name,CommandLine from Win32_Process")
For Each proc In procs
  cmd = LCase(proc.CommandLine & "")
  If InStr(cmd, "cinema_queue_worker_low.bat") > 0 Then
    already = True
  End If
  If InStr(cmd, "new-cinema-api") > 0 And InStr(cmd, "queue:work") > 0 And InStr(cmd, "--queue=low") > 0 Then
    already = True
  End If
Next
On Error GoTo 0

If already Then
  WScript.Quit 0
End If

sh.Run """" & bat & """", 0, False
WScript.Quit 0
