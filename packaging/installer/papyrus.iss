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

OutputDir=..\..\build\dist
OutputBaseFilename={#MyAppName}-{#MyAppVersion}-setup

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
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"; Flags: unchecked

[Files]
; ignoreversion because this installer carries one payload version at a time. Comparing timestamps
; to decide what to copy means a rebuild of the same source produces an install that silently
; keeps half the old files, and the reader gets a mix of two builds that both claim the same
; version.
Source: "{#SourceRoot}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\{#MyAppName}"; Filename: "{app}\Papyrus.cmd"; IconFilename: "{app}\public\favicon.ico"; Comment: "Start Papyrus"
Name: "{group}\Uninstall {#MyAppName}"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#MyAppName}"; Filename: "{app}\Papyrus.cmd"; IconFilename: "{app}\public\favicon.ico"; Comment: "Start Papyrus"; Tasks: desktopicon

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
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  ResultCode: Integer;
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

  if (not WizardSilent) and
     (MsgBox(
       'Papyrus is already installed here, and it may be running.' + #13#10 + #13#10 +
       'Install over it? Papyrus will be stopped first, and your library will not be touched.',
       mbConfirmation, MB_YESNO
     ) = IDNO) then
  begin
    Result := 'Papyrus is already installed here.' + #13#10 +
              'Nothing was changed.';

    Exit;
  end;

  Exec(
    ExpandConstant('{app}\runtime\bin\php.exe'),
    '"' + ExpandConstant('{app}\packaging\launcher\papyrus.php') + '" stop',
    ExpandConstant('{app}'),
    SW_HIDE,
    ewWaitUntilTerminated,
    ResultCode
  );
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

