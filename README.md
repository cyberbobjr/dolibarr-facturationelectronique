# Module de Facturation Électronique B2B pour Dolibarr (Version Alpha)

Ce module permet de mettre en conformité Dolibarr avec la future réglementation française de facturation électronique B2B et d'e-reporting. Il s'intègre avec les plateformes de facturation (PDP) pour transmettre les factures clients (Factur-X), récupérer les factures fournisseurs, rechercher les entreprises dans l'annuaire national (PEPPOL) et déclarer les données de paiement.

> [!WARNING]
> **VERSION ALPHA** : Cette version est destinée exclusivement aux tests en environnement de bac à sable (sandbox) et pour des testeurs volontaires. **Ne pas utiliser en production.** Assurez-vous de sauvegarder votre base de données avant l'installation ou la mise à jour.

> [!IMPORTANT]
> **Compatibilité des Plateformes** : Ce module fonctionne exclusivement avec **SuperPDP**, plateforme agréée DGFiP, dont l'intégration a été entièrement validée.

---

## Prérequis

- **Dolibarr** : Version 20.0+ (requis pour la gestion native des colonnes SIREN/SIRET en base de données).
- **PHP** : Version 8.1 ou supérieure.
- **Accès PDP** : Un compte actif auprès de **SuperPDP** avec des identifiants et clés d'API valides.

---

## Fonctionnalités

1. **Recherche dans l'annuaire national (Directory)** :
   - Recherche d'entreprises par nom et code postal directement depuis l'interface de Dolibarr.
   - Association en un clic du SIREN et des identifiants de routage PEPPOL.
   - **Double choix de synchronisation** : *Synchronisation complète* (mise à jour du nom et de l'adresse du tiers avec les coordonnées officielles de l'annuaire) ou *Synchronisation partielle* (liaison technique du SIREN et du routage sans écraser vos données locales).

2. **Factures Émises (Ventes)** :
   - Conversion automatique des factures clients validées au format certifié Factur-X (PDF avec XML embarqué).
   - Transmission sécurisée vers la plateforme PDP avec gestion des retours d'états et bandeau de notification.
   - **Validation pré-envoi** : le module vérifie que le SIREN de l'émetteur et du destinataire sont présents et composés exactement de 9 chiffres (détection automatique de la confusion SIREN/SIRET). L'envoi est bloqué avec un message d'erreur explicite en cas d'anomalie.
   - **Avertissement de routage PPF** (mode production) : si le tiers destinataire n'a pas encore été associé à son identifiant PEPPOL via l'annuaire, un bandeau d'alerte signale le risque de non-délivrance et propose de lancer la recherche d'association.

3. **Factures Reçues (Achats)** :
   - Récupération planifiée des factures fournisseurs depuis le PDP via des tâches cron Dolibarr.
   - Importation et création automatique des factures fournisseurs dans Dolibarr avec liaison automatique des tiers, des lignes de TVA et des taux associés.

4. **Déclaration des Paiements (E-Reporting)** :
   - Triggers interceptant les encaissements clients (`PAYMENT_CUSTOMER_CREATE`) et les paiements fournisseurs (`PAYMENT_SUPPLIER_CREATE`).
   - Transmission automatique des statuts de paiement (`fr:212` / `MEN` pour les ventes, `fr:211` / `MPA` pour les achats).
   - Ventilation et répartition proportionnelle de la TVA au prorata du montant payé pour les factures multi-taux.

5. **Console d'Audit** :
   - Interface administrative permettant de suivre, d'auditer et de déboguer l'ensemble des appels API échangés (requêtes, payloads, réponses, temps d'exécution et messages d'erreur).

---

## Installation

1. Téléchargez l'archive ZIP `module_facturationelectronique-VERSION.zip`.
2. Extrayez l'archive dans le répertoire `custom/` de votre Dolibarr.
   - Le chemin final doit être : `htdocs/custom/facturationelectronique/`.
3. Connectez-vous à Dolibarr en tant qu'administrateur.
4. Allez dans **Configuration -> Modules/Applications**.
5. Recherchez le module **Facturation Électronique** (dans la famille "Interface") et cliquez sur **Activer**.

---

## Configuration

1. Cliquez sur l'icône d'engrenage de configuration du module.
2. Renseignez vos identifiants d'API **SuperPDP** : sélectionnez le mode (**Bac à sable** ou **Production**) et saisissez le *Client ID* et le *Client Secret* correspondants.
3. Cliquez sur **Enregistrer** pour sauvegarder les modifications.
5. Validez la connexion avec le bouton de **Test de connexion** intégré.

---

## Développement & Build

Un script de construction est fourni dans `build/pack.php` pour générer automatiquement le ZIP de distribution. Pour l'exécuter :
```bash
php build/pack.php
```
Le fichier ZIP généré sera écrit dans le dossier `build/`.

---

## Licence

Ce module est distribué sous licence **GNU General Public License v3** (ou ultérieure) - voir le fichier [LICENSE](LICENSE) pour plus de détails.
