# Vérification 0.1.1

Résultats : les 16 fichiers PHP passent le contrôle de syntaxe et les
26 vérifications autonomes de tests/storage.php sont réussies.

Scénarios ajoutés : dépôt à cible refusée toujours listable, validation stricte
avant exécution, erreur JSON avec suffixe %, diagnostic du chemin réellement
chargé, présence/absence du fichier, journal borné et absence de modification
du manifeste pendant les diagnostics. Les tests de stockage existants restent
présents : écritures atomiques, conflit de révision, verrouillage et cache.

L'environnement disponible est Windows, avec PHP CLI 8.5.12. Les pages Twig,
les droits effectifs de www-data et les montages Docker n'ont pas été testés
dans votre instance GLPI. Aucun déploiement ni commande Git distante effectué.
