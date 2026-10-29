# Vérification 0.2.1

- 20 fichiers PHP : syntaxe vérifiée avec PHP CLI 8.5.12 sous Windows.
- Stockage et diagnostics : 26 vérifications réussies.
- Chemins et scripts : 39 vérifications réussies.
- Lanceur/prérequis : 10 vérifications réussies.
- Lanceur avec proc_open désactivée : 11 vérifications réussies.

Les erreurs d'exécutable absent, de commande/argument invalide et de dossier
de travail absent sont testées. La disponibilité effective de proc_open et
la détection de PHP CLI sont vérifiées. Ces tests ne lancent pas de sous-processus.

Les tests Linux d'exécution réelle et de liens symboliques restent à lancer
sous www-data dans le conteneur. Le build Docker, le rendu Twig et le lancement
Git depuis votre serveur web ne sont pas vérifiés ici. Aucun clone distant,
script post-pull réel ni déploiement effectué dans cet environnement.
