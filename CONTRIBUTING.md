# Contribution au module Facturation Électronique B2B

Merci de l'intérêt que vous portez à la contribution de ce module Dolibarr ! Afin de garantir un processus de développement fluide et de maintenir un niveau élevé de qualité de code, merci de suivre ces directives.

---

## Flux de travail (Workflow)

### 1. Dépôt dédié
Ce module est versionné indépendamment du cœur de Dolibarr. Assurez-vous de travailler directement dans le répertoire du module personnalisé :
`custom/facturationelectronique/`

### 2. Branches et Pull Requests
- **Ne poussez jamais directement sur la branche `main`.** Celle-ci est protégée.
- Créez une branche de fonctionnalité (feature branch) pour vos modifications :
  ```bash
  git checkout -b feature/nom-de-votre-fonctionnalite
  ```
- Poussez votre branche sur GitHub et ouvrez une **Pull Request (PR)** ciblant la branche `main`.
- Votre PR doit être approuvée par au moins un mainteneur et passer les vérifications CI/CD automatisées avant de pouvoir être fusionnée.

### 3. Versioning et Release automatiques

Le processus de livraison (release) est entièrement automatisé par GitHub Actions :
1. Développez vos fonctionnalités et corrections sur votre branche et ouvrez une Pull Request ciblant `main`.
2. Une fois la Pull Request approuvée et fusionnée sur `main` :
   - Le pipeline CI/CD de release s'exécute sur `main`.
   - Il calcule automatiquement la nouvelle version sémantique (SemVer) en analysant l'historique des commits depuis le dernier tag (Breaking change = Major, Feat = Minor, Fix/Chore = Patch/Alpha).
   - Il met à jour le fichier `CHANGELOG.md` en français et le numéro de version dans le descripteur du module.
   - Il commite et pousse ces changements directement sur la branche `main` (avec la mention `[skip ci]` pour éviter les boucles).
   - Il crée le tag Git correspondant, génère l'archive de distribution ZIP propre (sans les fichiers de test/dev) et publie la release sur GitHub avec les notes de version associées.

---

## Normalisation des messages de commit

Pour que le calcul automatique de la version SemVer et du CHANGELOG fonctionne correctement, ce projet exige la normalisation des messages de commit selon la convention **Conventional Commits**.

> [!WARNING]
> **Validation stricte** : Toutes les Pull Requests font l'objet d'une validation automatique des messages de commits. Si un seul commit ne respecte pas le format, le test CI échouera et la fusion (merge) sera bloquée.

### Format requis
```
<type>(<scope>): <description>
```
Le type et la description sont obligatoires. Le scope est facultatif.

### Types autorisés
- `feat` : Une nouvelle fonctionnalité (déclenche une hausse de version **mineure**).
- `fix` : Une correction de bug (déclenche une hausse de version **correctif/patch**).
- `docs` : Modifications de la documentation.
- `style` : Changements n'affectant pas le sens du code (espaces, formatage, etc.).
- `refactor` : Modification du code qui ne corrige pas un bug et n'ajoute pas de fonctionnalité.
- `perf` : Modification du code pour améliorer les performances.
- `test` : Ajout ou correction de tests.
- `chore` : Tâches de maintenance (ex: mise à jour des workflows de CI/CD).
- `ci` : Modifications des fichiers et scripts de configuration de la CI.
- `revert` : Annulation d'un commit précédent.

### Indicateur de changement majeur (Breaking Change)
Si votre modification introduit un changement majeur incompatible avec les versions précédentes, vous devez ajouter un point d'exclamation `!` après le type ou le scope (ex: `feat!: changer l'URL d'API par défaut` ou `feat(api)!: supprimer l'endpoint X`), ou ajouter `BREAKING CHANGE:` en description du commit. Cela déclenchera automatiquement une hausse de version **majeure** (SemVer).

### Comment corriger vos commits en cas d'échec de la CI ?
Si la CI échoue en signalant un message non valide, vous pouvez réécrire l'historique de votre branche locale avant de la repousser :
- Pour le dernier commit : `git commit --amend`
- Pour plusieurs commits : `git rebase -i origin/main` (remplacez `pick` par `reword` pour les commits à modifier) puis force-poussez votre branche : `git push --force-with-lease`

---

## Normes de codage

Ce module respecte les normes de codage natives de Dolibarr :
- **Indentation** : Utilisez des **Tabulations** pour l'indentation des fichiers PHP, JS et CSS.
- **Syntaxe PHP** : Utilisez un code orienté objet propre et compatible avec PHP 8.1+. Ne pas mettre de balise de fermeture `?>` à la fin des fichiers contenant uniquement du PHP.
- **Excellence esthétique** : Toutes les modifications d'interface utilisateur (UI) doivent utiliser les classes de style natives de Dolibarr (ex. `liste centpercent`, `oddeven`, `butAction`) afin de garantir la responsivité et l'intégration graphique avec tous les thèmes.

---

## Exécution locale des tests unitaires

Nous utilisons **PHPUnit** pour exécuter des tests unitaires. Afin d'éviter la lourdeur du chargement d'une instance complète de Dolibarr et de sa base de données, nous utilisons un bootstrap minimal de simulation (mock).

### Prérequis

Assurez-vous d'avoir PHP et Composer installés sur votre système.

### Installation des dépendances

Exécutez Composer à la racine du module pour installer les outils de test :
```bash
composer install
```

### Exécuter les tests

Pour lancer la suite de tests localement, exécutez :
```bash
./vendor/bin/phpunit --configuration phpunit.xml
```

### Ajouter des tests

Tous les tests unitaires doivent être placés dans le répertoire `tests/` et avoir le suffixe `Test.php`.
Si vous devez simuler des objets de base de données Dolibarr ou des paramètres globaux :
- Utilisez les fonctions factices (mocks) définies dans `tests/bootstrap.php`.
- Étendez ou enregistrez des propriétés simulées dans `tests/bootstrap.php` si de nouvelles fonctions d'aide de Dolibarr sont introduites.
