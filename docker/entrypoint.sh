
#!/bin/bash
set -euo pipefail

echo "=== Initialisation GitPull ==="

PLUGIN_DIR="/var/www/glpi/plugins/gitpull"
CONFIG_DIR="/var/glpi/config"

# URL du véritable dépôt Git du plugin
REPO_URL="${GITPULL_REPO_URL:-}"
REPO_BRANCH="${GITPULL_REPO_BRANCH:-main}"

if [ -n "$REPO_URL" ]; then

    if [ -d "$PLUGIN_DIR/.git" ]; then
        echo "[GitPull] Mise à jour du dépôt..."

        git -C "$PLUGIN_DIR" fetch origin "$REPO_BRANCH"
        git -C "$PLUGIN_DIR" reset --hard FETCH_HEAD

    else
        echo "[GitPull] Clonage du dépôt..."

        if [ -d "$PLUGIN_DIR" ] && \
           [ -n "$(ls -A "$PLUGIN_DIR")" ]; then
            echo "[ERREUR] Dossier non vide, sans dépôt Git."
            exit 1
        fi

        mkdir -p "$(dirname "$PLUGIN_DIR")"

        git clone --depth 1 \
            --branch "$REPO_BRANCH" \
            "$REPO_URL" \
            "$PLUGIN_DIR"
    fi

else
    echo "[GitPull] URL non configurée : synchronisation ignorée."
fi

echo "=== Vérification de la configuration ==="

if [ ! -f "$CONFIG_DIR/gitpull_repos.json" ]; then
    echo "[GitPull] Aucun fichier de configuration existant."
fi

echo "=== Démarrage GLPI officiel ==="

exec /opt/glpi/entrypoint.sh "$@"
