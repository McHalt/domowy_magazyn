#!/bin/sh
set -eu
key="${SSH_KEY:-id_ed25519}"
install -d -m 700 /root/.ssh
install -m 600 "/ssh-host/$key" "/root/.ssh/$key"
if [ -f /ssh-host/known_hosts ]; then
  install -m 600 /ssh-host/known_hosts /root/.ssh/known_hosts
fi
cat > /root/.ssh/config <<CONFIG
Host kchroscinski-prod
  HostName kchroscinski.pl
  User mchalt
  IdentityFile /root/.ssh/$key
  IdentitiesOnly yes
CONFIG
exec ansible-playbook "$@"
