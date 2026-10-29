# gitpull — GLPI 12.x

Version 0.2.2 · GPL-3.0-or-later · Linux · Git · proc_open et POSIX.
Le plugin sépare le manifeste de configuration des états et journaux jetables.

## Installation et mise à jour

Copier le dossier gitpull dans GLPI_ROOT/plugins/gitpull. Installer/activer le
plugin dans GLPI. Le bouton Paramètres du plugin et le menu Configuration >
gitpull ouvrent le tableau de bord. Chaque ligne possède un bouton Modifier
avec engrenage, qui ouvre une page dédiée front/repository.form.php?id=IDENTIFIANT.
Le nom du dépôt n'est plus un lien d'édition.

Depuis 0.1.x, remplacer le code du plugin en conservant le manifeste externe.
Les anciennes destinations absolues situées dans GLPI sont converties en
chemins relatifs en mémoire puis enregistrées sous cette forme à la prochaine
sauvegarde. Les lectures/cron ne réécrivent jamais le manifeste.
Les anciens scripts absolus ne sont acceptés que s'ils sont dans le dépôt.
Les scripts externes comme /opt/scripts/install.sh doivent être déplacés dans
le dépôt ou remplacés par un script du dépôt.
GITPULL_ALLOWED_ROOTS n'est plus utilisé : la frontière est toujours GLPI_ROOT.
Les empreintes de cache peuvent changer après conversion ; l'état est recréé.

## Configuration

Le manifeste est GLPI_CONFIG_DIR/gitpull_repos.json. Pour l'image officielle
habituellement utilisée ici : /var/glpi/config/gitpull_repos.json. Le bandeau
de diagnostic donne toujours le chemin réellement chargé.

Exemple avec destination par défaut et script optionnel :

```json
{
  "version": 1,
  "repositories": [
    {
      "id": "monplugin",
      "url": "https://github.com/organisation/monplugin.git",
      "post_pull": {"type": "bash", "path": "scripts/install.sh"},
      "hard_pull": false,
      "auto_check": true,
      "auto_pull": false,
      "check_interval": 5,
      "pull_interval": 30
    }
  ]
}
```

Le champ path est optionnel. Absent ou égal à "", il donne
GLPI_ROOT/plugins/<nom-du-dépôt>, ici GLPI_ROOT/plugins/monplugin.
Si le nom interne d'un plugin GLPI diffère du nom de son dépôt, renseigner
explicitement plugins/<nom-interne> pour respecter son dossier d'installation.
Le suffixe .git est retiré du nom. id est l'identifiant de configuration,
pas le nom utilisé pour déduire le dossier.

Pour choisir une autre destination à l'intérieur de GLPI :

```json
"path": "plugins/monplugin"
```

Les sous-dossiers sont possibles, par exemple extensions/equipe/monplugin.
Leurs parents sont créés au moment du clonage si les permissions le permettent ;
l'affichage et l'enregistrement de la configuration ne créent pas de dossiers.

Le préfixe GLPI_ROOT est affiché devant le champ dans le formulaire. Un aperçu
affiche la destination absolue calculée et se met à jour lorsque l'URL ou le
chemin change.

Les chemins ".", "..", les traversées, séparateurs Windows, chemins externes,
segments vides, accès aux dossiers .git et liens symboliques sont refusés.
Deux dépôts ne peuvent pas partager une destination ou être imbriqués.
La racine GLPI et le code du plugin gitpull ne peuvent pas être remplacés.
Les chemins absolus saisis/hérités ne sont acceptés que s'ils sont strictement
dans GLPI ; ils sont normalisés en chemins relatifs.

Le script post-pull est optionnel (post_pull: null pour le désactiver).
Son champ path part du dépôt cloné : scripts/install.sh signifie
GLPI_ROOT/plugins/monplugin/scripts/install.sh dans cet exemple. Un chemin
absolu vers ce même fichier interne est également accepté et normalisé.
Le plugin calcule le chemin absolu, vérifie qu'il reste dans le dépôt et lance
l’interpréteur Bash ou PHP CLI détecté avec le dépôt comme répertoire courant.
Aucun script situé à l'extérieur du dépôt n'est exécuté.

Tous les autres champs sont obligatoires. Les fréquences sont des minutes
entières de 1 à 525600 ; les cases sont des booléens JSON.
Les champs inconnus, les identifiants dupliqués et le JSON invalide sont refusés.
Aucun statut, date, compteur ou journal n'est écrit dans le manifeste.

## Droits et Docker

Toutes les commandes Git/scripts doivent réellement s'exécuter sous www-data ;
le plugin ne change pas l'utilisateur du processus et n'élève pas ses droits.

Sur le volume de plugins, www-data doit pouvoir écrire dans le parent d'un
nouveau clone (par défaut GLPI_ROOT/plugins) et dans les dépôts existants.
Une destination existante peut être mise à jour sans exiger l'écriture dans son
parent ; Git doit néanmoins pouvoir modifier les fichiers et .git.

Pour enregistrer le manifeste, www-data doit pouvoir lire/écrire le fichier,
écrire son dossier et ouvrir son fichier .lock. Le dossier de configuration
doit être monté en volume persistant ; un montage du fichier seul peut empêcher
son remplacement atomique. Adapter les ACL sur l'hôte si nécessaire, sans 777.

Le formulaire affiche les erreurs et confirme une sauvegarde réussie.
Les écritures utilisent un verrou latéral stable, une révision SHA-256 contre
les éditions concurrentes et un remplacement atomique.
Un éditeur externe doit coordonner ses écritures avec ce même verrou.

## Démarrage et automatisation

Le code de gitpull doit déjà être présent dans l'image ou le volume. Après
l'installation de GLPI et avant de servir le trafic, lancer sous www-data :

```sh
docker exec --user www-data glpi php /var/www/glpi/plugins/gitpull/bin/bootstrap.php
```

Adapter le nom du conteneur et le chemin de GLPI. Dans un entrypoint, utiliser
l'outil disponible pour lancer PHP sous www-data après l'installation de GLPI.
Le bootstrap initialise le Kernel GLPI 12 et exige une base disponible.
Il clone les dépôts absents et lance leurs scripts. Les dépôts présents sont
conservés. Retour 0 en cas de réussite, 1 en cas d'échec.

Ce clonage ne remplace pas l'installation/activation SQL des autres plugins :
prévoir si nécessaire des scripts administrateurs idempotents adaptés à GLPI.

L'installation de gitpull enregistre l'action Sync en mode CLI, toutes les
minutes. Elle doit être activée dans les Actions automatiques. Utiliser
l'ordonnanceur fourni par votre image ou appeler front/cron.php sous www-data.
Ne pas multiplier les ordonnanceurs si l'image en fournit déjà un.

Le cron lit le manifeste et :
- clone toute cible absente, même si les deux cases auto sont désactivées ;
- attend la fréquence pull avant de réessayer un clone échoué ;
- lance un pull automatique dû en priorité, avec vérification incluse ;
- sinon exécute un check automatique dû.

Les échéances partent de la dernière tentative, tandis que les dates de
réussite sont conservées séparément. Les timers indiquent une échéance ;
l'exécution dépend du prochain passage réel du cron.
Les actions manuelles Vérifier/Pull s'exécutent dans la requête web, avec
libération de la session pour laisser une autre page consulter l'état.
Adapter les délais PHP-FPM/proxy si nécessaire.

## Git et scripts

Dépôts publics HTTPS uniquement, sans token, SSH, identifiants ou prompt.
Sous-modules et redirections HTTP désactivés. Les adresses DNS privées/réservées
sont rejetées ; une politique réseau sortante Docker doit aussi empêcher les
accès internes, la validation DNS ne suffisant pas contre un changement DNS.

Le clone se fait dans un dossier temporaire voisin, publié par renommage.
Un clone échoué peut laisser un dossier .gitpull-* à examiner manuellement.
Un dossier existant non Git ou un origin différent n'est pas adopté.
Une branche suivant origin est requise ; HEAD détachée refusée.

Check : fetch puis comptage des commits distants absents de HEAD.
Pull : git pull --ff-only --no-rebase ; une divergence bloque l'opération.
Hard Pull : fetch puis git reset --hard sur le commit distant, avec perte des
modifications locales des fichiers suivis ; aucun git clean n'est lancé.

Le script est exécuté après le clone et chaque pull réussi, même sans nouveau
commit. Il doit être idempotent. Son échec laisse l'opération en erreur sans
mettre à jour la date de dernier pull réussi, même si Git a déjà changé le code.
Un nouveau pull le rejoue. Le bootstrap seul ne rejoue pas les scripts des
dépôts déjà présents.

Git et scripts utilisent proc_open avec tableau d'arguments, sans shell.
Git utilise /usr/bin/git ; environnement minimal, configuration globale/système,
hooks et helpers d'identifiants désactivés. Timeout 120 s par commande Git,
300 s par script. Le timeout couvre le processus direct ; les processus
détachés d'un script doivent être gérés par ce script ou la supervision Docker.

Les scripts et plugins clonés exécutent du code sous www-data. Réserver leur
configuration et le droit config/UPDATE à des administrateurs de confiance.

## Diagnostics, cache et journaux

Le bandeau des deux pages affiche le manifeste, son chemin réel, ses droits,
le verrou, l'utilisateur PHP, la date de modification et le nombre de dépôts.
Les cibles refusées restent affichées avec leurs erreurs et des boutons Git
désactivés ; le bouton Modifier reste disponible.

GLPI_VAR_DIR/_plugins/gitpull/diagnostics.json conserve les 50 derniers événements.
Les fichiers par empreinte de configuration conservent les statuts, dates,
32 Kio de sortie récents et les 20 derniers événements par dépôt.
En cas d'échec d'écriture du journal, consulter le journal d'erreurs PHP.

Ces fichiers sont jetables, dans un dossier 0700, avec fichiers 0600.
GLPI_VAR_DIR doit rester hors de toute exposition HTTP. La suppression du cache
ne supprime ni les clones ni le manifeste. Un état en cours sans verrou actif
est signalé comme interrompu.

Les verrous utilisent la destination absolue calculée. Une seule opération
peut modifier une cible à la fois. Cette base vise une instance GLPI active ;
un déploiement multi-réplicas exige un stockage et des verrous cohérents.
Ne jamais purger les fichiers .lock pendant l'exécution des workers.

## Fichiers principaux

- setup.php, hook.php : métadonnées, bouton Paramètres, menu et cron.
- src/Paths.php : destinations relatives, valeurs par défaut et confinement.
- src/Manifest.php, AtomicJson.php : schéma et sauvegardes atomiques.
- src/StateStore.php, Diagnostics.php : cache et journaux séparés.
- src/RepositoryManager.php, Process.php, Scheduler.php : exécution et cron.
- front/dashboard.php : visualisation et actions.
- front/repository.form.php : page séparée d'ajout/édition.
- front/config.php : compatibilité avec les anciennes URL.
- templates/ : Twig ; public/js/ : ressources d'interface.
- bin/bootstrap.php : initialisation Docker.
- tests/storage.php, paths.php, linux.php : tests autonomes.

## Vérification

```sh
php tests/storage.php
php tests/paths.php
php tests/process.php
# Sous www-data, dans le conteneur Linux :
php tests/linux.php
```

Les tests ne nécessitent pas de base GLPI. Valider ensuite dans votre image
GLPI 12 : rendu et navigation, création/édition, clone public, permissions
refusées, scripts internes, cron, divergence et hard pull.

Références : [architecture](https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/objects.html),
[conventions web et ressources](https://glpi-developer-documentation.readthedocs.io/en/master/upgradeguides/glpi-11.0.html),
[sources GLPI 12](https://github.com/glpi-project/glpi/tree/12.0.0).

## Diagnostic « Lancement du processus impossible » (0.2.1)

Le bandeau affiche maintenant les exécutables détectés (Git, PHP CLI, Bash),
la disponibilité de proc_open, le mode PHP et la restriction open_basedir.
Un exécutable absent/non exécutable, un dossier de travail absent et une
fonction PHP désactivée ont des messages distincts. Un refus de proc_open
conserve l'avertissement PHP/système, le binaire et le dossier de travail.
L'entrée standard est un pipe immédiatement fermé, sans ouverture de /dev/null
par PHP, ce qui évite une restriction open_basedir sur ce descripteur.

Git doit être installé dans le conteneur GLPI qui exécute le plugin.
Le service séparé alpine/git ne partage ni ses exécutables ni ses bibliothèques
avec GLPI. Le Dockerfile officiel consulté installe Git dans son étage de
construction, pas dans l'étage applicatif.

Un exemple docker/Dockerfile dérive de l'image utilisée dans votre Compose,
installe Git/Bash/certificats pendant le build puis rétablit USER www-data :

```sh
# Sur l'hôte TrueNAS, après copie de cette version dans le volume plugins
sudo docker build -t glpi-gitpull:local /mnt/Apps/GLPI/plugins/gitpull/docker
```

Dans le service glpi du Compose, remplacer uniquement image: glpi/glpi:latest
par image: glpi-gitpull:local puis redéployer. Conserver ses volumes, variables,
réseaux et dépendances. Cette image locale doit être reconstruite pour intégrer
une nouvelle image GLPI de base. Le build n'a pas été exécuté ici.

Vérification depuis le conteneur GLPI sous www-data :

```sh
php /var/www/glpi/plugins/gitpull/bin/diagnose.php
```

Cette commande lit les prérequis sans connexion SQL et sans lancer Git.
Elle retourne 1 si Git ou proc_open est indisponible. Les réglages PHP CLI
peuvent différer de ceux du serveur web : le bandeau montre le contexte web.
Les interpréteurs sont détectés parmi /usr/local/bin, /usr/bin et /bin ;
PHP n'est plus supposé être dans /usr/bin/php.

Un path vide signifie toujours plugins/<nom-du-dépôt>. Pour le manifeste qui
déclare le dépôt glpi-git-pull, cela donnerait plugins/glpi-git-pull, pas gitpull.
Ne pas utiliser ce dépôt pour auto-mettre à jour le plugin : son installation
reste assurée par le service Docker. Déclarer les autres plugins dans le manifeste.

Références :
[Dockerfile officiel](https://github.com/glpi-project/docker-images/blob/main/glpi/Dockerfile),
[proc_open](https://www.php.net/manual/en/function.proc-open.php).

## Tableau de bord et configuration repliable (0.2.2)

Les actions Vérifier/Pull retournent au tableau de bord via une redirection 303
hors du bloc d'interception d'erreurs. La sauvegarde d'un dépôt utilise le même
principe. Cela évite d'intercepter par erreur la RedirectException de GLPI 12,
qui pouvait afficher une liste vide ou présenter une sauvegarde comme échouée.

Un message confirme l'action ou indique son échec/verrouillage. L'état d'erreur
d'un seul dépôt n'empêche plus d'afficher les autres lignes ; les chemins
calculés sont présents sur toutes les lignes.

Le panneau Configuration et diagnostics est un élément details natif, replié
à chaque chargement. Son titre garde le chemin du manifeste visible et signale
un diagnostic à vérifier. Il s'ouvre et se ferme au clic ou au clavier, sans
JavaScript supplémentaire. Le journal intérieur reste repliable séparément.

Les tests tests/dashboard.php simulent le contrat de redirection GLPI et les
résultats d'actions, sans lancer Git ni nécessiter de base GLPI.
