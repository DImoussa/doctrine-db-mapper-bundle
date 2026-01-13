# Doctrine DB Mapper Bundle

[![Latest Stable Version](https://poser.pugx.org/dimoussa/doctrine-db-mapper-bundle/v/stable)](https://packagist.org/packages/dimoussa/doctrine-db-mapper-bundle)
[![License](https://poser.pugx.org/dimoussa/doctrine-db-mapper-bundle/license)](https://packagist.org/packages/dimoussa/doctrine-db-mapper-bundle)

Bundle Symfony pour générer automatiquement des entités Doctrine depuis une base de données MySQL avec support complet des relations (OneToMany, ManyToOne, ManyToMany).

## 📦 Installation

```bash
composer require dimoussa/doctrine-db-mapper-bundle
```

## ⚙️ Configuration

Configurez votre connexion MySQL dans `.env` :

```env
DATABASE_URL="mysql://user:password@localhost:3306/ma_base"
```

## 🚀 Utilisation

### Générer toutes les entités

```bash
php bin/console dbmapper:generate-entities src/Entity
```

### Générer une table spécifique

```bash
php bin/console dbmapper:generate-entities src/Entity --table=users
```

## ✨ Ce que le bundle génère

- ✅ Entités Doctrine avec types PHP corrects
- ✅ Relations automatiques (OneToMany, ManyToOne, ManyToMany)
- ✅ Getters et setters
- ✅ Méthodes `add`/`remove` pour les collections
- ✅ Repositories

## 📋 Exemple de sortie

```
📊 Analyse du schéma de la base de données...
🔗 Analyse des relations entre tables...
⚙️  Génération des entités et repositories...
✅ User.php généré
✅ Post.php généré
✅ Comment.php généré
🎉 3 entités créées avec succès !
```

## 📝 Prérequis

- PHP >= 8.1
- Symfony ^6.0 | ^7.0
- Doctrine ORM ^2.14 | ^3.0
- Base de données MySQL/MariaDB

## 📄 Licence

MIT

## 👤 Auteur

**Diallo Moussa**
- Email: moussadou128@gmail.com
- GitHub: [@DImoussa](https://github.com/DImoussa)

---




[![Latest Stable Version](https://poser.pugx.org/dimoussa/doctrine-db-mapper-bundle/v/stable)](https://packagist.org/packages/dimoussa/doctrine-db-mapper-bundle)
[![Total Downloads](https://poser.pugx.org/dimoussa/doctrine-db-mapper-bundle/downloads)](https://packagist.org/packages/dimoussa/doctrine-db-mapper-bundle)
[![License](https://poser.pugx.org/dimoussa/doctrine-db-mapper-bundle/license)](https://packagist.org/packages/dimoussa/doctrine-db-mapper-bundle)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/symfony-%5E6.0%7C%5E7.0-brightgreen)](https://symfony.com/)

