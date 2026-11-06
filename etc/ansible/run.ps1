# Ansible w kontenerze (Windows bez WSL), np.:
#   powershell -ExecutionPolicy Bypass -File etc\ansible\run.ps1 provision.yaml --ask-become-pass --check --diff
#   $env:SSH_KEY = 'haltcore_ed25519'; powershell -ExecutionPolicy Bypass -File etc\ansible\run.ps1 deploy.yaml
$ErrorActionPreference = 'Stop'
$image = 'domowy-magazyn-ansible:1'

docker build -q -t $image -f "$PSScriptRoot\docker\Dockerfile" $PSScriptRoot | Out-Null
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

$repo = (Resolve-Path "$PSScriptRoot\..\..").Path
$tty = if ([Console]::IsInputRedirected) { '-i' } else { '-it' }
# Repozytorium tylko do odczytu (archiwum wydania z gita), zapis tylko w etc/ansible (log).
docker run --rm $tty `
    -v "${repo}:/repo:ro" `
    -v "${PSScriptRoot}:/repo/etc/ansible" `
    -v "$HOME\.ssh:/ssh-host:ro" `
    -e "SSH_KEY=$(if ($env:SSH_KEY) { $env:SSH_KEY } else { 'id_ed25519' })" `
    $image @args
exit $LASTEXITCODE
