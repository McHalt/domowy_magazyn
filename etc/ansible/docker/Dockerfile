FROM python:3.12-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends openssh-client sshpass git \
 && rm -rf /var/lib/apt/lists/*
RUN pip install --no-cache-dir ansible-core==2.21.6
COPY requirements.yml /tmp/requirements.yml
RUN ansible-galaxy collection install -r /tmp/requirements.yml -p /usr/share/ansible/collections \
 && ansible-galaxy role install -r /tmp/requirements.yml -p /usr/share/ansible/roles
# Repozytorium montowane z hosta ma innego właściciela (git archive w deploy.yaml).
RUN git config --system --add safe.directory /repo
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
ENV LC_ALL=C.UTF-8 ANSIBLE_CONFIG=/repo/etc/ansible/ansible.cfg ANSIBLE_COLLECTIONS_PATH=/usr/share/ansible/collections ANSIBLE_ROLES_PATH=/usr/share/ansible/roles
WORKDIR /repo/etc/ansible
ENTRYPOINT ["/bin/sh", "/usr/local/bin/entrypoint.sh"]
