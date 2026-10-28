# Vérification 0.2.0

- Syntaxe : 18 fichiers PHP vérifiés avec PHP CLI 8.5.12 sous Windows.
- Stockage et diagnostics : 26 vérifications réussies.
- Chemins, manifeste et scripts : 39 vérifications réussies.
- Aperçu de destination JavaScript : 6 vérifications réussies.

Les scénarios couvrent notamment la destination omise/vide, le nom du dépôt,
les chemins relatifs imbriqués, la conversion des anciens chemins internes,
les traversées et chemins externes refusés, les destinations en doublon ou
imbriquées, les scripts absents/externes et la résolution absolue du script.
La validation et la consultation ne créent pas les dossiers du déploiement.

Le test de liens symboliques n'a pas pu s'exécuter sous Windows ; son scénario
est inclus dans tests/paths.php, et tests/linux.php est fourni pour le conteneur.
Les tests JavaScript utilisent un document simulé, pas une instance GLPI.

Le rendu Twig, l'icône, les droits www-data et la navigation dans une instance
GLPI 12 avec SQL restent à vérifier dans le conteneur cible.
Aucun clone distant, script réel, hard reset ou déploiement n'a été exécuté.
