# Vérification 0.2.2

- 21 fichiers PHP : syntaxe vérifiée avec PHP CLI 8.5.12 sous Windows.
- Tableau de bord et formulaire : 30 vérifications réussies.
- Stockage et diagnostics : 26 vérifications réussies.
- Chemins et scripts : 39 vérifications réussies.
- Lanceur : 10 vérifications réussies, puis 11 avec proc_open désactivée.

Le test tests/dashboard.php échoue sur le retour du POST de l'ancienne version,
puis passe après correction. Il exécute les vrais contrôleurs avec des services
GLPI simulés reproduisant la redirection par exception de GLPI 12, et des
résultats Git simulés. Aucun Git ni base SQL ne sont utilisés par ce test.

Scénarios : succès de check, échec de pull, exception backend, verrou occupé,
action/dépôt inconnu, cache scalaire invalide, affichage du dépôt suivant,
sauvegarde effective puis redirection, protection CSRF et conservation du
manifeste pendant les actions. Le GET ne relance pas l'action précédente.
Les chemins GLPI avec sous-répertoire sont conservés.

Le panneau de configuration utilise details/summary sans attribut open et
sans JavaScript ; le chemin du manifeste reste dans le titre visible.
Sa structure a été relue, mais le rendu et l'interaction n'ont pas été testés
dans une instance GLPI 12 réelle. Le test de liens symboliques reste à lancer
sous Linux. Aucun déploiement ni commande Git distante effectué ici.
