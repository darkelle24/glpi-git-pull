
FROM glpi/glpi:latest

USER root

# Installation des dépendances
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    git bash ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Copie de notre script personnalisé
COPY --chown=www-data:www-data \
    docker/entrypoint.sh \
    /usr/local/bin/git-pull-entrypoint.sh

# Autorisation d'exécution
RUN chmod 755 /usr/local/bin/git-pull-entrypoint.sh

# Exécution avec l'utilisateur officiel
USER www-data

# Entrypoint personnalisé
ENTRYPOINT ["/bin/bash", "/usr/local/bin/git-pull-entrypoint.sh"]

# Commande de démarrage officielle
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
