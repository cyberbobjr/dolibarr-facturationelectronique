# Guide de l'utilisateur - Module de Facturation Électronique B2B (France)

Ce module permet d'intégrer Dolibarr avec la Plateforme de Dématérialisation Partenaire (PDP) agréée **SuperPDP**, afin de gérer l'envoi et la réception de factures électroniques au format standardisé (Factur-X) conformément à la réglementation française.

---

## 1. Configuration du Module

La configuration générale du module s'effectue via l'interface d'administration de Dolibarr, sous **Configuration -> Modules/Applications**, puis en cliquant sur l'icône d'engrenage du module **Facturation Électronique**.

### 1.1 Fournisseur de service (PDP)
Le module s'appuie sur la PDP agréée **SuperPDP**, mise en évidence en haut de la page de configuration.

*   **SuperPDP** :
    *   **Mode de fonctionnement** : Permet de basculer entre le mode *Bac à sable (Sandbox)* pour les tests et le mode *Production*.
    *   **Identifiants** : Renseignez l'**Identifiant Client (Client ID)** et le **Secret Client (Client Secret)** fournis par la plateforme.
    *   **Actions** : Vous pouvez sauvegarder vos modifications et cliquer sur **Tester la connexion** pour valider vos accès auprès de l'API SuperPDP.

### 1.2 Recherche SIREN Interactive
Un outil de recherche est disponible dans l'onglet de configuration pour interroger directement l'**Annuaire National** à partir d'un numéro SIREN à 9 chiffres. Cela permet de vérifier la présence et les détails d'une entreprise avant toute transaction.

### 1.3 Récupération Automatique
Les factures d'achat entrantes sont récupérées périodiquement par une tâche planifiée (Cron). Un bouton d'accès rapide permet également d'accéder directement à l'écran opérationnel d'importation.

![Configuration du module](images/setup_page_fields.png)

---

## 2. Gestion des Factures Reçues (Achats)

Accessible via le menu **Facturation Électronique > Factures Réseau Reçues**.

Cet écran répertorie toutes les factures fournisseurs disponibles sur le réseau national (transmises par vos fournisseurs sur le PDP).

*   **Reconnaissance automatique des Tiers** : Le module analyse le SIREN de l'émetteur. Si le tiers existe déjà dans votre base Dolibarr, un lien direct vers sa fiche tiers s'affiche avec un badge vert **Existe**.
*   **Importation** : Vous pouvez sélectionner une ou plusieurs factures et lancer l'importation automatique sous forme de factures fournisseurs de type brouillon dans Dolibarr.

![Factures Réseau Disponibles](images/inbound_list_with_menu.png)

---

## 3. Gestion des Factures Émises (Ventes)

Accessible via le menu **Facturation Électronique > Factures Réseau Émises**.

Cet écran répertorie les factures Dolibarr émises à destination de vos clients, avec leur statut de transmission sur le réseau.

![Factures Réseau Émises](images/outbound_list_page.png)

### 3.1 Gestion automatique des remises et acomptes (lignes négatives)

Dolibarr enregistre certaines opérations comme des **lignes de facture à montant négatif** : remises globales, déductions d'acompte, ou avoirs partiels intégrés. Or la norme européenne de facturation électronique **EN16931** interdit qu'une ligne de facture porte un prix négatif (règle **BR-27**, champ BT-146). Une facture contenant de telles lignes est donc **rejetée à la validation** par les plateformes.

Le module gère ce cas automatiquement, sans action de votre part :

*   Chaque ligne négative est convertie en **remise au niveau document** (bloc BG-20 de la norme), qui est la forme correcte pour une réduction globale.
*   Les totaux sont recalculés séparément : somme des lignes positives (BT-106) d'un côté, somme des remises (BT-107) de l'autre, afin que le net à payer reste cohérent avec le contrôle effectué par la plateforme.
*   **Cas limite** : si une facture ne contient *que* des lignes négatives (aucune ligne de prestation ou de produit positive), la transmission est bloquée avec un message explicite. Une facture entièrement négative doit en effet être émise sous la forme d'un **avoir** (voir la gestion des avoirs, qui référence automatiquement la facture d'origine conformément à la règle BR-55).

> **En pratique** : vous pouvez transmettre normalement une facture comportant un acompte déduit ou une remise commerciale globale ; le module produit un Factur-X conforme là où un envoi brut serait refusé.

---

## 4. Intégration sur la Fiche Facture Client

### 4.1 Bandeau de statut et champs personnalisés
Lorsqu'une facture est validée et transmise, un bandeau d'information vert s'affiche en haut de sa fiche dans Dolibarr :
*   **ID technique PDP** : L'identifiant de la facture sur la plateforme.
*   **Date de transmission** : La date à laquelle la transmission a été effectuée.

Les attributs supplémentaires de la facture sont mis à jour en base de données pour en assurer le suivi.

![Fiche Facture avec bandeau](images/invoice_card_banner.png)

### 4.2 Fichiers Joints (Factur-X)
Dans la section des fichiers joints de la facture, vous retrouverez :
*   Le PDF standard généré par Dolibarr.
*   Le fichier PDF certifié Factur-X (reconnaissable au suffixe `_facturX.pdf`), contenant les métadonnées XML structurées requises par l'administration fiscale.

### 4.3 Onglet "Facturation Électronique"
Cet onglet dédié sur la fiche facture permet d'accéder au détail technique de la transmission :
*   **Lien direct portail** : Un bouton **Ouvrir sur le portail SuperPDP** permet d'accéder à la facture directement sur la console web du fournisseur.
*   **Historique des Événements Asynchrones** : Une chronologie visuelle liste les étapes clés du traitement réseau :
    1.  *Téléversée* (`api:uploaded`)
    2.  *Déposée (validée)* (`fr:200`)
    3.  *Émise par la plateforme* (`fr:201`)
    4.  *Rejetée* (`fr:213`) en cas d'erreur de validation.

![Onglet Facturation Électronique](images/invoice_facturelect_tab.png)

---

## 5. Journal d'Audit

Accessible via le menu **Facturation Électronique > Journal d'audit**.

Il offre une traçabilité complète de l'ensemble des échanges API entre votre Dolibarr et les serveurs du PDP. Chaque ligne indique la date, le fournisseur concerné et l'action/endpoint API sollicité (ex: `/oauth2/token`, `/v1.beta/invoices`, etc.).

![Journal d'audit API](images/audit_logs_page.png)
