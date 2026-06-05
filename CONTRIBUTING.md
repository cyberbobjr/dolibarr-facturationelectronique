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

Lorsqu'une PR est fusionnée dans `main` :
- Le pipeline CI/CD incrémente automatiquement la version de build alpha.
- Il met à jour la version dans le fichier `core/modules/modFacturationElectronique.class.php`.
- Un tag Git est créé et poussé.
- Une nouvelle release GitHub est générée avec le package ZIP auto-généré attaché.

> [!IMPORTANT]
> **Contournement de la protection de branche** : Pour permettre au workflow de release de commiter l'incrément de version sur la branche protégée `main`, vous devez créer un secret de dépôt (Repository Secret) nommé `RELEASE_TOKEN` dans les paramètres de votre dépôt GitHub. Ce secret doit contenir un **Personal Access Token (PAT)** d'administrateur avec les permissions de contourner la protection de branche (bypass branch protection rules) et l'accès en écriture au dépôt (`contents: write`).

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
