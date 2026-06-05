# Contributing to Facturation Électronique B2B Module

Thank you for your interest in contributing to this Dolibarr module! To ensure a smooth development process and maintain high code quality, please follow these guidelines.

---

## Development Workflow

### 1. Dedicated Repository
This module is versioned independently of Dolibarr core. Ensure you are working directly inside the custom module repository:
`custom/facturationelectronique/`

### 2. Branching & Pull Requests
- **Never push directly to the `main` branch.** It is protected.
- Create a feature branch for your changes:
  ```bash
  git checkout -b feature/your-feature-name
  ```
- Push your branch to GitHub and open a **Pull Request (PR)** targeting the `main` branch.
- Your PR must be approved by at least one maintainer and pass the automated CI/CD checks before it can be merged.

### 3. Automatic Versioning & Release
When a PR is merged into `main`:
- The CI/CD pipeline automatically increments the alpha build version.
- It updates the version in `core/modules/modFacturationElectronique.class.php`.
- A Git tag is created and pushed.
- A new GitHub Release is compiled with the auto-generated ZIP package attached.

---

## Coding Standards

This module adheres to native Dolibarr coding standards:
- **Indentation**: Use **Tab** indentation for PHP, JS, and CSS files.
- **PHP Syntax**: Use clean object-oriented PHP compatible with PHP 8.1+. Do not use closing `?>` tags at the end of PHP-only files.
- **Aesthetic Excellence**: All UI modifications must match Dolibarr's native styling classes (e.g., `liste centpercent`, `oddeven`, `butAction`) to guarantee responsiveness and visual integration across all themes.

---

## Running Unit Tests Locally

We use **PHPUnit** to run unit tests. To avoid the overhead of loading a full Dolibarr instance and database, we use a minimal mock bootstrap.

### Prerequisites

Make sure you have PHP and Composer installed on your system.

### Install dependencies

Run Composer in the module root to install testing tools:
```bash
composer install
```

### Run tests

To run the test suite locally, execute:
```bash
./vendor/bin/phpunit --configuration phpunit.xml
```

### Adding Tests

All unit tests should be placed in the `tests/` directory and suffixed with `Test.php`.
If you need to mock Dolibarr database objects or global settings:
- Use the mock functions defined in `tests/bootstrap.php`.
- Extend or register mock properties inside `tests/bootstrap.php` if new Dolibarr helper functions are introduced.
