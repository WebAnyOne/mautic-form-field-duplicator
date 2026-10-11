# Contribuer

Le dépôt contient le bundle à sa racine. La cible de référence est Mautic 6.0.9. Installer une copie dans `plugins/WebAnyOneFormFieldDuplicatorBundle` d’un checkout Mautic de test avec ses dépendances Composer de développement, SimpleXML et les extensions requises par Mautic.

Depuis la racine de cette instance Mautic :

```sh
php -d memory_limit=1G bin/phpunit -c plugins/WebAnyOneFormFieldDuplicatorBundle/phpunit.xml.dist
```

Les tests fonctionnels héritent de `MauticMysqlTestCase` et recréent/réinitialisent la base `DB_NAME`. Utiliser une base jetable et les outils `mysql`/`mysqldump` dans le PATH. Les variables `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWD` et `MAUTIC_DB_PREFIX` doivent viser cette base dédiée. Pour exécuter les seuls tests unitaires, ajouter `plugins/WebAnyOneFormFieldDuplicatorBundle/Tests/Unit` à la commande.

Le workflow CI prépare un checkout Mautic neuf et une base jetables. `python3 scripts/prepare-tests.py --mautic /chemin/checkout-neuf` refuse une instance déjà configurée et un dossier de plugin existant. Ce script est réservé aux tests.

Depuis la racine du dépôt du plugin :

```sh
node --check Assets/library/js/duplicator.js
python3 -m unittest discover -s Tests/Packaging -v
python3 scripts/build-release.py --version 1.0.0
```

Pour les workflows, utiliser [actionlint](https://github.com/rhysd/actionlint) : `actionlint .github/workflows/ci.yml .github/workflows/release.yml`. Un parseur YAML seul ne valide pas les contextes GitHub. Le port dynamique de MySQL est résolu dans l’étape PHPUnit, où le contexte `job` est disponible.

Décrire le problème, conserver les contrôles d’accès et CSRF, ajouter un test de régression utile puis exécuter les tests concernés. Le plugin doit continuer à utiliser les champs, brouillons et rendus natifs sans modification du cœur. Voir [la publication](docs/PUBLISHING.md) et [la sécurité](SECURITY.md).
