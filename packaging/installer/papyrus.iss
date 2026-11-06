; The Windows installer for Papyrus.
;
; ## What this is for
;
; Phase 2 item 2.1: an installer that produces one executable, installs without asking for
; administrator rights, and leaves a reader's library alone when it is removed.
;
; ## Per user, not per machine
;
; `PrivilegesRequired=lowest` is the important line in this file.
;
; The install goes to `%LOCALAPPDATA%\Programs\Papyrus`, which the reader can write to without an
; elevation prompt. A first run that asks "do you want to allow this app to make changes to your
; computer" is a bad first impression for a reader app, and it buys nothing here: the install is
; self contained, it writes nothing at runtime (measured across a full install, run and uninstall,
; 12,641 files and 0 of them changed), and a program installed under Program Files gains only the
; ability to be upgraded by somebody else.
;
; ## The library is never in the install directory
;
; This is the whole reason the data location rule in DataLocation.php has an "inside AppData"
; branch. Data lives at `%LOCALAPPDATA%\Papyrus`, which is a different folder from
; `%LOCALAPPDATA%\Programs\Papyrus`, so uninstalling removes the program and leaves the library.
;
; That is also why there is no `[UninstallDelete]` here, and why there must never be one that
; reaches outside `{app}`. A reader who uninstalls because the app is broken must not lose their
; library in the same breath.
;
; ## One executable
;
; `SolidCompression=no` on 623MB of already-compressed payloads: the jar, the JRE and the PHP
; runtime are all zip-compressed already, so a solid LZMA2 block spends minutes to save very little.
; LZMA2 with `LZMAUseSeparateProcess=yes` keeps the compiler in a separate process so it does not
; exhaust the memory the installer itself needs.
;
; ## Usage:
;
;   iscc /DMyAppVersion=1.2.0 papyrus.iss
;
; Version numbers come from the command line rather than from a constant in this file, because
; `VERSION` in the repository root is the single source and a second copy here is a second thing to
; forget to bump. The build script reads `VERSION`, splits it into a SemVer string and a four part
; file version, and passes both in.

#ifndef MyAppVersion
  /*
   * Loudly wrong rather than silently plausible.
   *
   * A compile that succeeds with a placeholder version produces an installer named
   * `Papyrus-0.0.0.0-unset-setup.exe`, which is obvious on a build machine and is exactly the sort
   * of artifact that gets attached to a bug report by somebody who did not notice.
   */
  #error MyAppVersion is not defined. Build it with: iscc /DMyAppVersion=<version> papyrus.iss
#endif

#ifndef MyAppVersionInfo
  #error MyAppVersionInfo is not defined. It is the four part form, for the file version resource.
#endif

#ifndef SourceRoot
  #error SourceRoot is not defined. It is the built install directory that gets packaged.
#endif

#define MyAppName "Papyrus"
#define MyAppPublisher "Papyrus"
#define MyAppExeName "Papyrus"

; The first release whose launcher exits non-zero when a process survives. An older install's
; stop returns zero even after it prints "did not stop", so the installer checks the output as
; well as the exit code for those versions.
#define StopFixVersion "1.5.3"

[Setup]
AppId={{4F2A9C31-6B8E-4D57-9A1E-3C7B0D5E8F42}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppVerName={#MyAppName} {#MyAppVersion}
VersionInfoVersion={#MyAppVersionInfo}
VersionInfoCompany={#MyAppPublisher}
VersionInfoDescription={#MyAppName} setup
VersionInfoProductName={#MyAppName}
VersionInfoProductVersion={#MyAppVersionInfo}

; Per user. See the header.
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
DefaultDirName={localappdata}\Programs\{#MyAppName}

; x64compatible rather than x64, because it also accepts ARM64 Windows, where this runs under
; emulation. Papyrus is a 64 bit x64 payload either way.
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible

DefaultGroupName={#MyAppName}
DisableProgramGroupPage=yes
DisableWelcomePage=no
WizardStyle=modern
AllowNoIcons=yes

; A normal install shows the language picker and silent mode does not. `yes` is the documented way
; to offer it whenever more than one [Languages] entry exists; Inno suppresses wizard pages,
; including this one, when Setup runs with /SILENT or /VERYSILENT, and /LANG suppresses it too.
ShowLanguageDialog=yes
LanguageDetectionMethod=uilanguage

OutputDir=..\..\build\dist
OutputBaseFilename={#MyAppName}-{#MyAppVersion}-setup

; The setup executable carries the same mark as the shortcuts and the uninstaller. SourceRoot is
; the built install, which already holds the generated favicon, so all three read one file rather
; than a second copy kept in this directory.
SetupIconFile={#SourceRoot}\public\favicon.ico

; See the header for why this payload is not solid compressed.
Compression=lzma2/max
SolidCompression=no
LZMAUseSeparateProcess=yes

; A reader who is running Papyrus when they upgrade should be asked, not have files pulled out from
; under a live JVM. The explicit stop in CurUninstallStepChanged is the real fix; this is the
; backstop for the case where the launcher itself will not run.
CloseApplications=yes
RestartApplications=no

; Two installs of Papyrus on one machine would otherwise race for the same AppId.
SetupMutex=PapyrusSetupMutex

UninstallDisplayIcon={app}\public\favicon.ico
UninstallDisplayName={#MyAppName}

[Languages]
; English first, so it remains the default source language when Windows matches no other entry.
Name: "english"; MessagesFile: "compiler:Default.isl"
Name: "spanish"; MessagesFile: "compiler:Languages\Spanish.isl"
Name: "french"; MessagesFile: "compiler:Languages\French.isl"
Name: "brazilianportuguese"; MessagesFile: "compiler:Languages\BrazilianPortuguese.isl"

[CustomMessages]
; The strings this installer owns. A plain entry is the English default; a language prefix
; overrides it for that language only. `%n` is a line break and `%1` is the version argument.
StartMenuComment=Start Papyrus
DesktopShortcutComment=Start Papyrus
DowngradeRefused=This installer is older than the version already installed (%1).%nNothing was changed. To roll back on purpose, run the previous installer with /FORCEDOWNGRADE after papyrus rollback.
AlreadyInstalledConfirm=Papyrus is already installed here, and it may be running.%n%nInstall over it? Papyrus will be stopped first, and your library will not be touched.
AlreadyInstalledDeclined=Papyrus is already installed here.%nNothing was changed.
OlderLauncherWarning=This older Papyrus cannot confirm that every process closed.%n%n
LauncherDidNotRun=Papyrus could not be stopped because its launcher did not run.%nNothing was changed. Close Papyrus and try again.
LauncherFailed=Papyrus could not be stopped because its launcher failed.%nNothing was changed. Close Papyrus and try again.
StillRunningSilent=Papyrus is still running, so the installer stopped before changing anything.%nClose Papyrus and run setup again.
StillRunningRetry=Papyrus is still running. Close it from its window, then click Retry.
StillRunningCancelled=Papyrus is still running.%nNothing was changed.

spanish.StartMenuComment=Iniciar Papyrus
spanish.DesktopShortcutComment=Iniciar Papyrus
spanish.DowngradeRefused=Este instalador es más antiguo que la versión ya instalada (%1).%nNo se cambió nada. Para volver atrás a propósito, ejecuta el instalador anterior con /FORCEDOWNGRADE después de papyrus rollback.
spanish.AlreadyInstalledConfirm=Papyrus ya está instalado aquí y puede que se esté ejecutando.%n%n¿Instalar encima? Primero se detendrá Papyrus y tu biblioteca no se tocará.
spanish.AlreadyInstalledDeclined=Papyrus ya está instalado aquí.%nNo se cambió nada.
spanish.OlderLauncherWarning=Esta versión anterior de Papyrus no puede confirmar que todos los procesos se hayan cerrado.%n%n
spanish.LauncherDidNotRun=Papyrus no se pudo detener porque su lanzador no se ejecutó.%nNo se cambió nada. Cierra Papyrus e inténtalo de nuevo.
spanish.LauncherFailed=Papyrus no se pudo detener porque su lanzador falló.%nNo se cambió nada. Cierra Papyrus e inténtalo de nuevo.
spanish.StillRunningSilent=Papyrus sigue en ejecución, así que el instalador se detuvo antes de cambiar nada.%nCierra Papyrus y vuelve a ejecutar el instalador.
spanish.StillRunningRetry=Papyrus sigue en ejecución. Ciérralo desde su ventana y luego haz clic en Reintentar.
spanish.StillRunningCancelled=Papyrus sigue en ejecución.%nNo se cambió nada.

french.StartMenuComment=Démarrer Papyrus
french.DesktopShortcutComment=Démarrer Papyrus
french.DowngradeRefused=Ce programme d'installation est plus ancien que la version déjà installée (%1).%nRien n'a été modifié. Pour revenir volontairement à une version antérieure, exécutez le programme d'installation précédent avec /FORCEDOWNGRADE après papyrus rollback.
french.AlreadyInstalledConfirm=Papyrus est déjà installé ici et il est peut-être en cours d'exécution.%n%nVoulez-vous installer par-dessus ? Papyrus sera d'abord arrêté et votre bibliothèque ne sera pas modifiée.
french.AlreadyInstalledDeclined=Papyrus est déjà installé ici.%nRien n'a été modifié.
french.OlderLauncherWarning=Cette version plus ancienne de Papyrus ne peut pas confirmer que tous les processus se sont arrêtés.%n%n
french.LauncherDidNotRun=Papyrus n'a pas pu être arrêté car son lanceur ne s'est pas exécuté.%nRien n'a été modifié. Fermez Papyrus et réessayez.
french.LauncherFailed=Papyrus n'a pas pu être arrêté car son lanceur a échoué.%nRien n'a été modifié. Fermez Papyrus et réessayez.
french.StillRunningSilent=Papyrus est toujours en cours d'exécution, le programme d'installation s'est donc arrêté avant de modifier quoi que ce soit.%nFermez Papyrus et relancez le programme d'installation.
french.StillRunningRetry=Papyrus est toujours en cours d'exécution. Fermez-le depuis sa fenêtre, puis cliquez sur Réessayer.
french.StillRunningCancelled=Papyrus est toujours en cours d'exécution.%nRien n'a été modifié.

brazilianportuguese.StartMenuComment=Iniciar Papyrus
brazilianportuguese.DesktopShortcutComment=Iniciar Papyrus
brazilianportuguese.DowngradeRefused=Este instalador é mais antigo que a versão já instalada (%1).%nNada foi alterado. Para voltar de propósito, execute o instalador anterior com /FORCEDOWNGRADE depois de papyrus rollback.
brazilianportuguese.AlreadyInstalledConfirm=O Papyrus já está instalado aqui e pode estar em execução.%n%nInstalar por cima? O Papyrus será interrompido primeiro e sua biblioteca não será alterada.
brazilianportuguese.AlreadyInstalledDeclined=O Papyrus já está instalado aqui.%nNada foi alterado.
brazilianportuguese.OlderLauncherWarning=Esta versão mais antiga do Papyrus não consegue confirmar que todos os processos foram fechados.%n%n
brazilianportuguese.LauncherDidNotRun=Não foi possível parar o Papyrus porque o inicializador não foi executado.%nNada foi alterado. Feche o Papyrus e tente novamente.
brazilianportuguese.LauncherFailed=Não foi possível parar o Papyrus porque o inicializador falhou.%nNada foi alterado. Feche o Papyrus e tente novamente.
brazilianportuguese.StillRunningSilent=O Papyrus ainda está em execução, então o instalador parou antes de alterar qualquer coisa.%nFeche o Papyrus e execute o instalador novamente.
brazilianportuguese.StillRunningRetry=O Papyrus ainda está em execução. Feche-o pela janela e clique em Tentar novamente.
brazilianportuguese.StillRunningCancelled=O Papyrus ainda está em execução.%nNada foi alterado.

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"; Flags: unchecked

[Files]
; ignoreversion because this installer carries one payload version at a time. Comparing timestamps
; to decide what to copy means a rebuild of the same source produces an install that silently
; keeps half the old files, and the reader gets a mix of two builds that both claim the same
; version.
Source: "{#SourceRoot}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\{#MyAppName}"; Filename: "{app}\Papyrus.cmd"; IconFilename: "{app}\public\favicon.ico"; Comment: "{cm:StartMenuComment}"
Name: "{group}\Uninstall {#MyAppName}"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#MyAppName}"; Filename: "{app}\Papyrus.cmd"; IconFilename: "{app}\public\favicon.ico"; Comment: "{cm:DesktopShortcutComment}"; Tasks: desktopicon

[Run]
Filename: "{app}\Papyrus.cmd"; Description: "{cm:LaunchProgram,{#MyAppName}}"; Flags: nowait postinstall skipifsilent

[Code]
// Stop Papyrus before installing over it, and say so before doing it.
 //
 // `CurUninstallStepChanged` below covers uninstalling, and it would be natural to assume it covers
 // upgrading too. It does not. An upgrade does not run the old uninstaller: Inno replaces the files
 // in place and the old version's `[Code]` section is never executed, because that code lives in
 // the very files being replaced.
 //
 // The consequence without this is the one the design promises against. The install is a JVM, a web
 // server and two workers; replacing their binaries underneath a running JVM leaves files locked
 // and an install that is half new and half old, reporting a version it is not.
 //
 // Here rather than in `InitializeSetup`, which is the obvious place and cannot do it:
 // `InitializeSetup` runs before the install directory exists, and expanding `{app}` there raises
 // "Internal error: An attempt was made to expand the `"app`" constant before it was initialized"
 // and the setup dies before reading a single file. `PrepareToInstall` runs after the directory
 // page, so `{app}` is settled and this is still the last moment before anything is written.
 //
 // The question is skipped in silent mode rather than asked and auto-answered. A suppressed
 // message box in a deploy script is a hang or a silent yes depending on the host, and neither is
 // worth a sentence a scripted upgrade cannot read.
function InstalledVersion(): String;
var
  Lines: TArrayOfString;
  I: Integer;
begin
  Result := '';

  if not FileExists(ExpandConstant('{app}\version.txt')) then
    Exit;

  if not LoadStringsFromFile(ExpandConstant('{app}\version.txt'), Lines) then
    Exit;

  for I := 0 to GetArrayLength(Lines) - 1 do
    if Pos('version ', Lines[I]) = 1 then
    begin
      Result := Trim(Copy(Lines[I], 9, Length(Lines[I])));
      Exit;
    end;
end;

function VersionPart(const Value: String; Index: Integer): Integer;
var
  I, Part, Start: Integer;
begin
  Part := 0;
  Start := 1;

  for I := 1 to Length(Value) + 1 do
    if (I > Length(Value)) or (Value[I] = '.') then
    begin
      if Part = Index then
      begin
        Result := StrToIntDef(Copy(Value, Start, I - Start), 0);
        Exit;
      end;

      Inc(Part);
      Start := I + 1;
    end;

  Result := 0;
end;

function CompareVersions(const Left, Right: String): Integer;
var
  I, A, B: Integer;
begin
  for I := 0 to 2 do
  begin
    A := VersionPart(Left, I);
    B := VersionPart(Right, I);

    if A < B then
    begin
      Result := -1;
      Exit;
    end;

    if A > B then
    begin
      Result := 1;
      Exit;
    end;
  end;

  Result := 0;
end;

// The escape hatch for a deliberate rollback. `papyrus rollback` prints the command with this
// flag, and the downgrade guard below refuses without it.
function ForcedDowngrade(): Boolean;
begin
  Result := Pos('/FORCEDOWNGRADE', Uppercase(GetCmdTail)) > 0;
end;

// Whether the stop output says a process survived. The launcher before {#StopFixVersion} printed
// this line but still exited zero, so the exit code alone cannot answer the question there.
function StopLogSaysRunning(const Path: String): Boolean;
var
  Lines: TArrayOfString;
  I: Integer;
begin
  Result := False;

  if not LoadStringsFromFile(Path, Lines) then
    Exit;

  for I := 0 to GetArrayLength(Lines) - 1 do
    if Pos('did not stop', Lines[I]) > 0 then
    begin
      Result := True;
      Exit;
    end;
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  ResultCode: Integer;
  Installed: String;
  StopLog: String;
  StopCommand: String;
  Older: String;
begin
  Result := '';

  if not FileExists(ExpandConstant('{app}\runtime\bin\php.exe')) then
  begin
    Exit;
  end;

  if not FileExists(ExpandConstant('{app}\packaging\launcher\papyrus.php')) then
  begin
    Exit;
  end;

  // An older installer over a newer install is a downgrade, and it is refused unless the reader
  // asked for one on purpose. `papyrus rollback` prints the /FORCEDOWNGRADE command after it has
  // restored the reader database, so a real rollback has an explicit path and an accidental one
  // does not.
  Installed := InstalledVersion();

  if (Installed <> '') and
     (CompareVersions(Installed, '{#MyAppVersion}') > 0) and
     (not ForcedDowngrade()) then
  begin
    Result := FmtMessage(CustomMessage('DowngradeRefused'), [Installed]);

    Exit;
  end;

  if (not WizardSilent) and
     (MsgBox(
       CustomMessage('AlreadyInstalledConfirm'),
       mbConfirmation, MB_YESNO
     ) = IDNO) then
  begin
    Result := CustomMessage('AlreadyInstalledDeclined');

    Exit;
  end;

  // Stop, read what the launcher printed, and let the reader retry after closing Papyrus.
  //
  // The launcher before {#StopFixVersion} printed "did not stop" for a surviving process but
  // exited zero, so the exit code is not enough for a reader upgrading from 1.5.2. Redirecting
  // the output catches that line on both the old and new launchers. A retry is the useful
  // action: the reader closes the window and the next stop succeeds.
  StopLog := ExpandConstant('{tmp}\papyrus-stop.txt');
  Older := '';

  if (Installed <> '') and (CompareVersions(Installed, '{#StopFixVersion}') < 0) then
    Older := CustomMessage('OlderLauncherWarning');

  while True do
  begin
    if FileExists(StopLog) then
      DeleteFile(StopLog);

    StopCommand := '/C ""' + ExpandConstant('{app}\runtime\bin\php.exe') + '" "' +
      ExpandConstant('{app}\packaging\launcher\papyrus.php') + '" stop > "' + StopLog + '" 2>&1"';

    if not Exec(
      ExpandConstant('{cmd}'),
      StopCommand,
      ExpandConstant('{app}'),
      SW_HIDE,
      ewWaitUntilTerminated,
      ResultCode
    ) then
    begin
      Result := CustomMessage('LauncherDidNotRun');

      Exit;
    end;

    if (ResultCode = 0) and (not StopLogSaysRunning(StopLog)) then
      Break;

    if not StopLogSaysRunning(StopLog) then
    begin
      Result := CustomMessage('LauncherFailed');

      Exit;
    end;

    if WizardSilent then
    begin
      Result := CustomMessage('StillRunningSilent');

      Exit;
    end;

    if MsgBox(
      Older + CustomMessage('StillRunningRetry'),
      mbConfirmation, MB_RETRYCANCEL
    ) = IDCANCEL then
    begin
      Result := CustomMessage('StillRunningCancelled');

      Exit;
    end;
  end;

  if FileExists(StopLog) then
    DeleteFile(StopLog);
end;

//
 // Stop Papyrus before uninstalling, rather than failing on files in use.
 //
 // The launcher holds the four processes and `stop` is the command documented for bringing them all
 // down, including when the application is too broken to run. An uninstall that leaves a JVM
 // holding `suwayomi\` produces "could not delete file" half way through, which is the worst
 // possible outcome: the install is now half removed and the reader cannot start it to recover.
 //
 // `usUninstall` fires before any file is removed, so the launcher and its `php.exe` still exist
 // when this runs. `usPostUninstall` would be too late and the shortcut would not exist.
 //
procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
var
  ResultCode: Integer;
  Launcher: String;
begin
  if CurUninstallStep <> usUninstall then
  begin
    Exit;
  end;

  Launcher := ExpandConstant('{app}\packaging\launcher\papyrus.php');

  if not FileExists(ExpandConstant('{app}\runtime\bin\php.exe')) then
  begin
    Exit;
  end;

  if not FileExists(Launcher) then
  begin
    Exit;
  end;

  //
   // No error check on purpose.
   //
   // If the launcher cannot run, uninstalling must still finish. Refusing to uninstall because the
   // stop failed would leave the reader with a program they cannot remove and cannot run, which is
   // a worse state than a running JVM, and `CloseApplications` above is already covering locked
   // files. A nonzero code here means something was not running, which is the normal case.
   //
  Exec(
    ExpandConstant('{app}\runtime\bin\php.exe'),
    '"' + Launcher + '" stop',
    ExpandConstant('{app}'),
    SW_HIDE,
    ewWaitUntilTerminated,
    ResultCode
  );
end;

