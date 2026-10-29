# Vérification 0.2.5

- 21 fichiers PHP : syntaxe vérifiée avec PHP CLI 8.5.12 sous Windows.
- Tableau de bord et formulaire : 51 vérifications réussies (dont les actions et lectures JSON sans redirection).
- Stockage et diagnostics : 26 vérifications réussies.
- Chemins et scripts : 51 vérifications réussies.
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
dans une instance GLPI 12 réelle.

Actions sans rechargement : test dans un navigateur avec réponses serveur simulées.
Vérifications : état immédiat, boutons désactivés pendant l’action, envoi du jeton
CSRF et de l’action, actualisation de l’étape en cours, confirmation de réussite,
erreur de pull, réactivation des boutons, journal conservé ouvert et contenu du
journal inséré comme texte. L’URL reste inchangée après check et pull.
Les tests PHP couvrent aussi le verrou actif, l’étape interrompue et le refus
d’une action JSON invalide. Le stockage (26) et les chemins (39) passent toujours. Le test de liens symboliques reste à lancer
sous Linux. Aucun déploiement ni commande Git distante effectué ici.

Chargement JavaScript : URL versionnée par empreinte du fichier, initialisation
après DOMContentLoaded et interception avant propagation aux formulaires GLPI.
Test navigateur avec script externe chargé avant le DOM : clic Check et
soumission sans submitter interceptés, URL inchangée, aucun gestionnaire
de soumission global déclenché. Réponses serveur simulées.

Suppression depuis la modification : bouton rouge et confirmation native testée
dans le navigateur (annulation sans soumission, confirmation avec soumission).
Tests PHP : confirmation requise, CSRF, ancienne révision, identifiant incohérent,
formulaire d’ajout, dépôt inconnu, redirection, autres dépôts et fichiers conservés,
suppression du dernier dépôt avec manifeste vide valide. Stockage : 26 contrôles OK.
