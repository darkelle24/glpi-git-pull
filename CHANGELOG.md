# Historique des versions

## 0.2.10 — 2026-11-05

### Corrections

- Transmettre aux processus enfants les chemins `GLPI_*_DIR` et le type
  d’environnement résolus par GLPI pour retrouver la configuration SQL sur
  les volumes Docker pendant l’installation et l’activation des plugins.
- Préciser le chemin et la lisibilité de la configuration SQL dans les erreurs
  d’initialisation des commandes CLI.
- Ajouter un test de régression avec un vrai processus PHP, sans connexion SQL.
