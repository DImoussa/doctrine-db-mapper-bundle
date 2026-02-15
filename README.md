# Doctrine DB Mapper Bundle

[![Latest Stable Version](https://poser.pugx.org/dimoussa/doctrine-db-mapper-bundle/v/stable)](https://packagist.org/packages/dimoussa/doctrine-db-mapper-bundle)
[![License](https://poser.pugx.org/dimoussa/doctrine-db-mapper-bundle/license)](https://packagist.org/packages/dimoussa/doctrine-db-mapper-bundle)


> **[English](#english)** | **[Français](#français)**

---

## English

A Symfony bundle to automatically generate Doctrine entities from a MySQL database with full relationship support (OneToMany, ManyToOne, ManyToMany).

### 📦 Installation

```bash
composer require dimoussa/doctrine-db-mapper-bundle
```

### ⚙️ Configuration

Configure your MySQL connection in `.env`:

```env
DATABASE_URL="mysql://user:password@localhost:3306/my_database"
```

### 🚀 Usage

#### Generate all entities

```bash
php bin/console dbmapper:generate-entities src/Entity
```

#### Generate a specific table

```bash
php bin/console dbmapper:generate-entities src/Entity --table=users
```

#### Manage the database (modify, view the schema...)

```bash
php bin/console dbmapper:modify-entities
```

#### Interactive mode example

The `dbmapper:modify-entities` command launches a fully interactive menu to manage your database schema:

```
[DbMapper] Interactive schema modification mode

What do you want to do?
  [0] List tables
  [1] Choose a table to modify
  [2] Show current change plan
  [3] Preview SQL to be executed
  [4] Apply changes to the database
  [5] Quit
 > 1

Choose a table to modify:
  [0] users
  [1] posts
  [2] comments
 > 0

Selected table: users
What do you want to do?
  [0] View attributes (columns)
  [1] Add a new attribute
  [2] Manage relations
  [3] Back to main menu
 > 0

Columns of the table users:
 - id (integer), nullable: NO
 - username (string), nullable: NO
 - email (string), nullable: NO
 - created_at (datetime), nullable: YES

Selected table: users
What do you want to do?
  [0] View attributes (columns)
  [1] Add a new attribute
  [2] Manage relations
  [3] Back to main menu
 > 1

New attribute (column) name: avatar
Doctrine type (e.g.: string, integer, text, boolean, datetime, float, decimal): string
Can this attribute be NULL?
  [0] no
  [1] yes
 > 1

New column summary:
 - Table : users
 - Name  : avatar
 - Type  : string
 - NULL  : yes
Confirm adding this column to the change plan?
  [0] no
  [1] yes
 > 1

Column added to the change plan (no actual changes made yet).

What do you want to do?
  [0] List tables
  [1] Choose a table to modify
  [2] Show current change plan
  [3] Preview SQL to be executed
  [4] Apply changes to the database
  [5] Quit
 > 3

SQL queries to be executed:
  ALTER TABLE users ADD avatar VARCHAR(255) DEFAULT NULL;

 > 4

Change plan:
 - [ADD COLUMN] Table users : avatar (string), nullable: yes

SQL queries to be executed:
  ALTER TABLE users ADD avatar VARCHAR(255) DEFAULT NULL;

Are you sure you want to apply these changes to the database?
  [0] no
  [1] yes
 > 1

Applying changes...
✓ All changes have been applied successfully!
 ✓ ALTER TABLE users ADD avatar VARCHAR(255) DEFAULT NULL

The change plan has been cleared.
```

### ✨ What the bundle generates

- ✅ Doctrine entities with correct PHP types
- ✅ Automatic relationships (OneToMany, ManyToOne, ManyToMany)
- ✅ Getters and setters
- ✅ `add`/`remove` methods for collections
- ✅ Repositories

### 📋 Output example

```
📊 Analyzing the database schema...
🔗 Analyzing relationships between tables...
⚙️  Generating entities and repositories...
✅ User.php generated
✅ Post.php generated
✅ Comment.php generated
🎉 3 entities successfully created!
```

### 📝 Requirements

- PHP >= 8.1
- Symfony ^6.0 | ^7.0
- Doctrine ORM ^2.14 | ^3.0
- MySQL/MariaDB database

### 📄 License

MIT

### 👤 Author

**Diallo Moussa**
- Email: moussadou128@gmail.com
- GitHub: [@DImoussa](https://github.com/DImoussa)

---

## Français

Bundle Symfony pour générer automatiquement des entités Doctrine depuis une base de données MySQL avec support complet des relations (OneToMany, ManyToOne, ManyToMany).

### 📦 Installation

```bash
composer require dimoussa/doctrine-db-mapper-bundle
```

### ⚙️ Configuration

Configurez votre connexion MySQL dans `.env` :

```env
DATABASE_URL="mysql://user:password@localhost:3306/ma_base"
```

### 🚀 Utilisation

#### Générer toutes les entités

```bash
php bin/console dbmapper:generate-entities src/Entity
```

#### Générer une table spécifique

```bash
php bin/console dbmapper:generate-entities src/Entity --table=users
```

#### Gérer la base de donnée (modification, visualisation de la base...)

```bash
php bin/console dbmapper:modify-entities
```

#### Exemple du mode interactif

La commande `dbmapper:modify-entities` lance un menu entièrement interactif pour gérer le schéma de votre base de données :

```
[DbMapper] Mode interactif de modification du schéma

Que veux-tu faire ?
  [0] Lister les tables
  [1] Choisir une table à modifier
  [2] Afficher le plan des changements en cours
  [3] Prévisualiser le SQL qui sera exécuté
  [4] Appliquer les changements dans la base de données
  [5] Quitter
 > 1

Choisis une table à modifier :
  [0] users
  [1] posts
  [2] comments
 > 0

Table sélectionnée : users
Que veux-tu faire ?
  [0] Voir les attributs (colonnes)
  [1] Ajouter un nouvel attribut
  [2] Gérer les relations
  [3] Revenir au menu principal
 > 0

Colonnes de la table users :
 - id (integer), nullable: NO
 - username (string), nullable: NO
 - email (string), nullable: NO
 - created_at (datetime), nullable: YES

Table sélectionnée : users
Que veux-tu faire ?
  [0] Voir les attributs (colonnes)
  [1] Ajouter un nouvel attribut
  [2] Gérer les relations
  [3] Revenir au menu principal
 > 1

Nom du nouvel attribut (colonne) : avatar
Type Doctrine du nouvel attribut (ex: string, integer, text, boolean, datetime, float, decimal) : string
Ce nouvel attribut peut-il être NULL ?
  [0] non
  [1] oui
 > 1

Récapitulatif de la nouvelle colonne :
 - Table : users
 - Nom   : avatar
 - Type  : string
 - NULL  : oui
Confirmer l'ajout de cette colonne au plan de changements ?
  [0] non
  [1] oui
 > 1

Colonne ajoutée au plan de changements (aucune modification réelle effectuée pour l'instant).

Que veux-tu faire ?
  [0] Lister les tables
  [1] Choisir une table à modifier
  [2] Afficher le plan des changements en cours
  [3] Prévisualiser le SQL qui sera exécuté
  [4] Appliquer les changements dans la base de données
  [5] Quitter
 > 3

Requêtes SQL qui seront exécutées :
  ALTER TABLE users ADD avatar VARCHAR(255) DEFAULT NULL;

 > 4

Plan de changements :
 - [ADD COLUMN] Table users : avatar (string), nullable: oui

Requêtes SQL qui seront exécutées :
  ALTER TABLE users ADD avatar VARCHAR(255) DEFAULT NULL;

Êtes-vous sûr de vouloir appliquer ces changements dans la base de données ?
  [0] non
  [1] oui
 > 1

Application des changements...
✓ Tous les changements ont été appliqués avec succès !
 ✓ ALTER TABLE users ADD avatar VARCHAR(255) DEFAULT NULL

Le plan de changements a été vidé.
```

### ✨ Ce que le bundle génère

- ✅ Entités Doctrine avec types PHP corrects
- ✅ Relations automatiques (OneToMany, ManyToOne, ManyToMany)
- ✅ Getters et setters
- ✅ Méthodes `add`/`remove` pour les collections
- ✅ Repositories

### 📋 Exemple de sortie

```
📊 Analyse du schéma de la base de données...
🔗 Analyse des relations entre tables...
⚙️  Génération des entités et repositories...
✅ User.php généré
✅ Post.php généré
✅ Comment.php généré
🎉 3 entités créées avec succès !
```

### 📝 Prérequis

- PHP >= 8.1
- Symfony ^6.0 | ^7.0
- Doctrine ORM ^2.14 | ^3.0
- Base de données MySQL/MariaDB

### 📄 Licence

MIT

### 👤 Auteur

**Diallo Moussa**
- Email: moussadou128@gmail.com
- GitHub: [@DImoussa](https://github.com/DImoussa)

[![Latest Stable Version](https://poser.pugx.org/dimoussa/doctrine-db-mapper-bundle/v/stable)](https://packagist.org/packages/dimoussa/doctrine-db-mapper-bundle)
[![Total Downloads](https://poser.pugx.org/dimoussa/doctrine-db-mapper-bundle/downloads)](https://packagist.org/packages/dimoussa/doctrine-db-mapper-bundle)
[![License](https://poser.pugx.org/dimoussa/doctrine-db-mapper-bundle/license)](https://packagist.org/packages/dimoussa/doctrine-db-mapper-bundle)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/symfony-%5E6.0%7C%5E7.0-brightgreen)](https://symfony.com/)