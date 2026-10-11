# Publier la version 1.0.0 sur GitHub

Dépôt : [WebAnyOne/mautic-form-field-duplicator](https://github.com/WebAnyOne/mautic-form-field-duplicator). La préparation locale ajoute les workflows, les notes et le constructeur de ZIP. Elle ne pousse aucun commit ou tag et ne crée aucune release distante.

## Envoyer la préparation

Le clone existant est `/Applications/MAMP/htdocs/mautic-form-field-duplicator`. Une copie autonome des sources est également fournie sous `deliverables/mautic-form-field-duplicator/github/mautic-form-field-duplicator`. Le bundle se trouve à la racine du dépôt ; conserver les fichiers cachés `.github`, `.gitignore`, `.gitattributes` et `.editorconfig`.

Depuis le clone contenant les fichiers préparés :

```sh
cd /Applications/MAMP/htdocs/mautic-form-field-duplicator
git status --short
git add .
git commit -m "Prepare GitHub release v1.0.0"
git push -u origin main
```

Si GitHub répond encore `Permission denied to PierreAmmeloot`, le compte lié au jeton doit disposer de l’accès en écriture au dépôt WebAnyOne. Changer le nom saisi dans le terminal ne change pas l’identité du jeton. Pour un jeton finement limité, sélectionner WebAnyOne comme propriétaire, ce dépôt et les permissions **Contents: Read and write** et **Workflows: Read and write** puisque ce commit ajoute des workflows ; faire approuver le jeton par l’organisation si nécessaire. Pour un jeton classique, l’écriture des fichiers de workflow requiert le scope `workflow` en plus de l’accès au dépôt. Une clé SSH associée au compte autorisé convient également. Aucun jeton ne doit être enregistré dans les fichiers. [Documentation GitHub des jetons](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens).

L’installation locale de `gh` n’est pas nécessaire : les commandes ci-dessus utilisent Git, et le runner GitHub fournit `gh` pour la release.

## Créer le tag puis publier le brouillon

1. Dans l’onglet **Actions**, attendre la réussite de **Tests** sur le commit envoyé. Il contrôle Composer, PHP, JavaScript, les tests unitaires/fonctionnels sur Mautic 6.0.9 avec PHP 8.1/8.2/8.3 et MySQL 5.7, ainsi que la construction et les contrôles du ZIP. MySQL 5.7 reproduit l’environnement de validation ; le choix concerne les tests.
2. Depuis le même clone, créer le tag sur ce commit :

   ```sh
   git tag -a v1.0.0 -m "WebAnyOne Form Field Duplicator 1.0.0"
   git push origin v1.0.0
   ```

3. **Draft release** relance **Tests** sur le tag. Après réussite, il crée un brouillon intitulé **WebAnyOne Form Field Duplicator 1.0.0**, avec les notes de `docs/releases/1.0.0.md` et les deux pièces jointes :

   - `WebAnyOneFormFieldDuplicatorBundle-1.0.0.zip`
   - `WebAnyOneFormFieldDuplicatorBundle-1.0.0.zip.sha256`

4. Ouvrir **Releases**, relire le brouillon, vérifier les pièces jointes, puis cliquer **Publish release**. [Comportement de `gh release create --draft --verify-tag`](https://cli.github.com/manual/gh_release_create).

Le workflow utilise le `GITHUB_TOKEN` automatique : aucun secret PAT supplémentaire à configurer. Les tests disposent de `contents: read` ; seul le job de release reçoit `contents: write`. Les règles de l’organisation doivent autoriser ces Actions et cette permission. [Documentation GitHub des permissions](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#permissions).

## Construire ou publier manuellement

Depuis la racine des sources préparées :

```sh
python3 -m unittest discover -s Tests/Packaging -v
python3 scripts/build-release.py --version 1.0.0
cd dist
shasum -a 256 -c WebAnyOneFormFieldDuplicatorBundle-1.0.0.zip.sha256
```

Le constructeur exige la même version dans `Config/config.php`, des notes de release présentes, les fichiers obligatoires et aucun lien symbolique dans les dossiers distribués. L’archive est reproductible avec le même environnement Python/zlib et les mêmes sources ; elle exclut les workflows, scripts, dépendances, fichiers cachés, configurations locales, dumps et clés. La liste des extensions admises est définie dans le constructeur.

Si Actions est indisponible, exécuter les tests PHP documentés dans `CONTRIBUTING.md`, créer/pousser le tag, puis créer un brouillon dans l’interface GitHub en copiant les notes et en joignant les deux fichiers de `dist/`. Ne pas distribuer « Source code (zip) » comme ZIP installable Mautic.

Si une exécution a déjà créé un brouillon mais échoué pendant l’ajout des pièces jointes, compléter ce brouillon dans l’interface GitHub avec les fichiers reconstruits et vérifiés. Le workflow ne remplace pas automatiquement une release existante.

## Versions suivantes

Modifier `Config/config.php`, `CHANGELOG.md` et les exemples de version du README, puis ajouter `docs/releases/VERSION.md`. Le constructeur et la CI déduisent la version de `Config/config.php` ; le workflow de release vérifie qu’elle correspond au tag `vVERSION`. La version Composer provient du tag Git, sans champ `version` fixe dans le manifeste.

Tester aussi la mise à jour depuis la version précédente sur une instance jetable de Mautic avant de publier. Conserver le nom du bundle et du paquet `webanyone/form-field-duplicator`. Ne pas déplacer un tag publié. Packagist est une publication distincte, facultative pour les installations ZIP ou VCS.
