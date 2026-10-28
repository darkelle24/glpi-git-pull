# Vérification de la livraison

- 15 fichiers PHP : syntaxe vérifiée avec PHP CLI 8.5.12 sous Windows.
- 13 vérifications autonomes du stockage : réussies (tests/storage.php).
- Sources GLPI 12.0.0 consultées pour les méthodes du cron, les menus, le rendu
  Twig et l'initialisation du noyau.
- Fichiers encodés en UTF-8 sans BOM.
- Le manifeste d'exemple ne contient aucun état dynamique.
- Les pages Twig et la commande de démarrage sont fournies mais leur exécution
  n'a pas été vérifiée dans une instance GLPI 12 avec SQL.
- tests/linux.php doit être lancé sous www-data dans le conteneur cible.
- Aucun clone distant, script post-pull, hard reset ou déploiement réel n'a été
  exécuté dans cet environnement Windows.

Avant mise en service : effectuer les scénarios d'intégration du README dans
une instance de test. La livraison est un socle de développement, pas une
certification de production.
