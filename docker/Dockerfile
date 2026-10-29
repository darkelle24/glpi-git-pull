FROM glpi/glpi:latest

USER root

# Installation des dépendances (git, bash, ca-certificates)
RUN apt-get update \
    && apt-get install -y --no-install-recommends git bash ca-certificates \
    && rm -rf /var/lib/apt/lists/*

USER www-data

# Injection directe de la logique d'initialisation et de synchronisation du plugin
ENTRYPOINT ["/bin/bash", "-c", "\
    echo '[init] Vérification et initialisation des répertoires...' && \
    mkdir -p /var/www/glpi/plugins/gitpull /var/glpi/config && \
    echo '[init] Configuration du fichier gitpull_repos.json...' && \
    if [ ! -f /var/glpi/config/gitpull_repos.json ]; then \
    echo '{\"version\":1,\"repositories\":[{\"id\":\"gitpull\",\"url\":\"https://github.com\",\"path\":\"/var/www/glpi/plugins/gitpull\",\"post_pull\":null,\"hard_pull\":false,\"auto_check\":true,\"auto_pull\":false,\"check_interval\":60,\"pull_interval\":1440}]}' > /var/glpi/config/gitpull_repos.json; \
    fi && \
    echo '[init] Clonage ou mise à jour du plugin gitpull...' && \
    if [ -d /var/www/glpi/plugins/gitpull/.git ]; then \
    git -C /var/www/glpi/plugins/gitpull fetch origin main && \
    git -C /var/www/glpi/plugins/gitpull reset --hard origin/main; \
    else \
    git clone --depth 1 --branch main https://github.com /var/www/glpi/plugins/gitpull; \
    fi && \
    echo '[init] Initialisation terminée. Lancement de GLPI...' && \
    exec /entrypoint.sh \
    "]