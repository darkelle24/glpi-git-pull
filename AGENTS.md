
# Instructions pour les agents IA

## Règles générales

- Éviter les modifications sans rapport avec la demande.
- Ne jamais supprimer de fonctionnalités sans justification.

## Gestion obligatoire des versions

Après chaque tâche ayant modifié le code :

1. Identifier le fichier contenant la version du projet.
2. Augmenter la version en respectant SemVer :
   - PATCH (1.0.0 → 1.0.1) : correction de bug.
   - MINOR (1.0.0 → 1.1.0) : nouvelle fonctionnalité compatible.
   - MAJOR (1.0.0 → 2.0.0) : rupture de compatibilité.
3. Mettre à jour toutes les références à cette version.
4. Ajouter une entrée au fichier CHANGELOG.md.
5. Vérifier la cohérence des versions.

Ne modifier la version qu'une fois par tâche, même si
plusieurs fichiers sont modifiés.

Ne pas augmenter la version pour une simple consultation,
une modification de documentation ou un changement
sans impact sur le code.

Demander confirmation avant une version MAJOR.

Ne pas créer de tag Git ou publier de release sans demande.
