# Validation de la préparation de release

État au **11 octobre 2026**. La préparation de `v1.0.0` conserve à l’identique les 22 fichiers PHP, JavaScript, Twig et de traduction du plugin. Les parcours navigateur, installation/désinstallation et soumissions de la recette initiale restent décrits dans [VALIDATION.md](VALIDATION.md), avec leurs preuves du 8 octobre.

## Contrôles locaux

| Contrôle | Résultat |
| --- | --- |
| PHPUnit sur PHP 8.1.31 | 34 tests, 320 assertions, réussi |
| PHPUnit sur PHP 8.2.26 | 34 tests, 320 assertions, réussi |
| PHPUnit sur PHP 8.3.14 | 34 tests, 320 assertions, réussi |
| Syntaxe PHP | 18 fichiers, valide sur les trois versions |
| JavaScript | `node --check Assets/library/js/duplicator.js`, réussi |
| Composer | `composer validate --strict`, manifeste valide |
| Installation Composer native | `plugins/WebAnyOneFormFieldDuplicatorBundle`, réussi |
| Workflows | actionlint 1.7.12, aucune erreur |
| Construction du ZIP | 8 tests Python, réussi ; CRC et SHA-256 vérifiés |
| Préparation du checkout | nouvelle instance préparée ; reconfiguration refusée sans modifier `config/local.php` |

La suite PHP a été réexécutée sur un **nouveau** checkout du tag Mautic 6.0.9, avec une copie des dépendances de développement déjà installées, PHPUnit 9.6.34 et une nouvelle instance MySQL 5.7.44 réservée à ces tests. `scripts/prepare-tests.py` a installé le plugin et créé la configuration jetable ; les variables DB_* visaient uniquement la base temporaire. Les sorties sont conservées dans [release-php81.txt](preuves/release-php81.txt), [release-php82.txt](preuves/release-php82.txt) et [release-php83.txt](preuves/release-php83.txt).

Le test Composer utilise composer/installers 1.12.0, un dépôt `path` local sans symlink avec l’option `versions` fixant `webanyone/form-field-duplicator` à `1.0.0`, et un métapaquet de test `mautic/core-lib` 6.0.9. Il ne définit **aucun** chemin personnalisé `installer-paths` : le nom natif est fourni par `extra.install-directory-name`. Il confirme le placement et l’autoload, pas une installation VCS distante avant publication du tag. `extra.installer-name` conserve aussi le nom du dossier pour les projets utilisant leur propre chemin `plugins/{$name}`.

Les tests d’archive vérifient le dossier racine installable, les fichiers essentiels, l’empreinte, la reproductibilité après changement des dates des sources, l’exclusion des fichiers d’environnement/clés/dumps/configurations locales et le refus d’une version incohérente, de notes absentes, d’un fichier obligatoire absent ou d’un lien symbolique. La reproductibilité est vérifiée dans le même environnement Python/zlib.

## Limites

Les workflows GitHub Actions n’ont pas encore été exécutés sur le dépôt distant. La validation actionlint vérifie leur syntaxe, expressions et contextes ; ShellCheck et Pyflakes externes n’étaient pas utilisés. La résolution fraîche des dépendances et le fonctionnement des runners Ubuntu devront être confirmés par le premier workflow **Tests** sur GitHub.

Les nouveaux contrôles n’ont pas répété les parcours navigateur : le code exécuté du plugin reste identique à la recette initiale. Ils ne certifient pas d’autres versions de Mautic ni PHP 8.4.

Aucun commit, tag, push ou brouillon distant n’a été créé pendant cette préparation. Le workflow créera le brouillon après envoi du tag `v1.0.0`, uniquement si les tests réussissent ; la publication du brouillon s’effectue ensuite dans GitHub. Voir [PUBLISHING.md](PUBLISHING.md).
