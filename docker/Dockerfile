# --- Étape 1 : Récupération de Git depuis une image externe ---
FROM alpine/git:latest AS git-source

# --- Étape 2 : Image GLPI Finale ---
FROM glpi/glpi:latest

USER root

# On copie le binaire Git et ses dépendances directement depuis l'image Git Alpine
COPY --from=git-source /usr/bin/git /usr/bin/git
COPY --from=git-source /usr/libexec/git-core/ /usr/libexec/git-core/

USER www-data

# Logique d'initialisation au DÉMARRAGE (exécutée après le montage des disques TrueNAS)
ENTRYPOINT ["/bin/bash", "-c", "\
    echo '[init] Vérification et initialisation des répertoires...' && \
    mkdir -p /var/www/glpi/plugins/gitpull /var/glpi/config && \
    \
    if [ ! -f /var/glpi/config/gitpull_repos.json ]; then \
    echo '[init] Configuration du fichier gitpull_repos.json...' && \
    echo '{\"version\":1,\"repositories\":[{\"id\":\"gitpull\",\"url\":\"https://github.com\",\"path\":\"/var/www/glpi/plugins/gitpull\",\"post_pull\":null,\"hard_pull\":false,\"auto_check\":true,\"auto_pull\":false,\"check_interval\":60,\"pull_interval\":1440}]}' > /var/glpi/config/gitpull_repos.json; \
    fi && \
    \
    echo '[init] Clonage ou mise à jour du plugin gitpull...' && \
    if [ -d /var/www/glpi/plugins/gitpull/.git ]; then \
    git -C /var/www/glpi/plugins/gitpull fetch origin main && \
    git -C /var/www/glpi/plugins/gitpull reset --hard origin/main; \
    else \
    git clone --depth 1 --branch main https://github.com /var/www/glpi/plugins/gitpull; \
    fi && \
    \
    echo '[init] Initialisation terminée. Lancement d’Apache et GLPI...' && \
    exec apache2-foreground \
    "]