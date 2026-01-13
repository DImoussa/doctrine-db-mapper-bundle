# Doctrine DB Mapper Bundle

[![Latest Stable Version](https://poser.pugx.org/dimoussa/doctrine-db-mapper-bundle/v/stable)](https://packagist.org/packages/dimoussa/doctrine-db-mapper-bundle)
[![License](https://poser.pugx.org/dimoussa/doctrine-db-mapper-bundle/license)](https://packagist.org/packages/dimoussa/doctrine-db-mapper-bundle)

Bundle Symfony pour générer automatiquement des entités Doctrine depuis une base de données MySQL existante avec support complet des relations bidirectionnelles.

## 🚀 Fonctionnalités

- ✅ Génération automatique d'entités Doctrine depuis une base MySQL
- ✅ Détection automatique des relations **OneToMany** / **ManyToOne**
- ✅ Détection intelligente des relations **ManyToMany**
- ✅ Relations bidirectionnelles complètes avec méthodes add/remove
- ✅ Génération des repositories
- ✅ Support des clés primaires composites
- ✅ Support des contraintes uniques
- ✅ Nettoyage automatique du cache Symfony
- ✅ Compatible Symfony 6.x et 7.x

## 📦 Installation

### Étape 1 : Installer le bundle via Composer

```bash
composer require dimoussa/doctrine-db-mapper-bundle
```

### Étape 2 : Enregistrer le bundle (Symfony < 6.1)

Si vous utilisez Symfony < 6.1 ou si le bundle ne s'enregistre pas automatiquement, ajoutez-le dans `config/bundles.php` :

```php
<?php

return [
    // ... autres bundles
    App\Bundle\DbMapperBundle\DbMapperBundle::class => ['all' => true],
];
```

### Étape 3 : Configuration (Optionnel)

Créez le fichier `config/packages/db_mapper.yaml` :

```yaml
db_mapper:
    entity_namespace: 'App\Entity'
    repository_namespace: 'App\Repository'
    skip_existing: true
    detect_many_to_many: true
    generate_bidirectional: true
```

**Paramètres disponibles :**

- `entity_namespace` : Namespace pour les entités générées (défaut: `App\Entity`)
- `repository_namespace` : Namespace pour les repositories (défaut: `App\Repository`)
- `skip_existing` : Ne pas écraser les entités existantes (défaut: `true`)
- `detect_many_to_many` : Détecter les tables d'association ManyToMany (défaut: `true`)
- `generate_bidirectional` : Générer les relations bidirectionnelles (défaut: `true`)

## 🎯 Utilisation

### Commande de génération

```bash
php bin/console dbmapper:generate-entities src/Entity
```

**Arguments :**

- `output-dir` : Répertoire de sortie pour les entités (ex: `src/Entity`)

### Exemple de résultat

```
📊 Analyse du schéma de la base de données...
🔗 Analyse des relations entre tables...
  → Tables d'association ManyToMany détectées: Entrepreneur_devisTypes, Envoyer, Illustree
⚙️  Génération des entités et repositories...
✅ Entité générée : Chantier [3 OneToMany] [2 ManyToMany]
✅ Repository généré : ChantierRepository
✅ Entité générée : Entrepreneur [1 OneToMany] [5 ManyToMany]
✅ Repository généré : EntrepreneurRepository
⏭️  Table d'association ignorée: Entrepreneur_devisTypes (gérée comme ManyToMany)
🧹 Nettoyage du cache Symfony...
✅ Cache Symfony nettoyé avec succès.
✨ Génération terminée avec succès !
```

## 📋 Exemple de code généré

### Entité avec relation ManyToOne

```php
#[ORM\ManyToOne(targetEntity: Categorie::class, inversedBy: 'entrepreneurs')]
#[ORM\JoinColumn(name: 'idCateg', referencedColumnName: 'idCateg', nullable: true)]
private ?Categorie $categorie = null;

public function getCategorie(): ?Categorie
{
    return $this->categorie;
}

public function setCategorie(?Categorie $categorie): self
{
    $this->categorie = $categorie;
    return $this;
}
```

### Entité avec relation OneToMany

```php
#[ORM\OneToMany(targetEntity: Entrepreneur::class, mappedBy: 'categorie')]
private Collection $entrepreneurs;

public function __construct()
{
    $this->entrepreneurs = new ArrayCollection();
}

public function getEntrepreneurs(): Collection
{
    return $this->entrepreneurs;
}

public function addEntrepreneur(Entrepreneur $entrepreneur): static
{
    if (!$this->entrepreneurs->contains($entrepreneur)) {
        $this->entrepreneurs->add($entrepreneur);
        $entrepreneur->setCategorie($this);
    }
    return $this;
}

public function removeEntrepreneur(Entrepreneur $entrepreneur): static
{
    if ($this->entrepreneurs->removeElement($entrepreneur)) {
        if ($entrepreneur->getCategorie() === $this) {
            $entrepreneur->setCategorie(null);
        }
    }
    return $this;
}
```

### Entité avec relation ManyToMany

```php
#[ORM\ManyToMany(targetEntity: DevisType::class, inversedBy: 'entrepreneurs')]
#[ORM\JoinTable(
    name: 'Entrepreneur_devisTypes',
    joinColumns: [new ORM\JoinColumn(name: 'idEntrepreneur', referencedColumnName: 'idEntrepreneur')],
    inverseJoinColumns: [new ORM\JoinColumn(name: 'idDevisType', referencedColumnName: 'idDevisType')]
)]
private Collection $devisTypes;

public function __construct()
{
    $this->devisTypes = new ArrayCollection();
}

public function getDevisTypes(): Collection
{
    return $this->devisTypes;
}

public function addDevisType(DevisType $devisType): static
{
    if (!$this->devisTypes->contains($devisType)) {
        $this->devisTypes->add($devisType);
        $devisType->addEntrepreneur($this);
    }
    return $this;
}

public function removeDevisType(DevisType $devisType): static
{
    if ($this->devisTypes->removeElement($devisType)) {
        $devisType->removeEntrepreneur($this);
    }
    return $this;
}
```

## 🔍 Détection des relations ManyToMany

Le bundle détecte automatiquement les tables d'association ManyToMany selon ces critères :

- La table contient exactement **2 clés étrangères**
- Ces 2 clés étrangères constituent la **clé primaire composite**
- Pas de colonnes métier supplémentaires (sauf `created_at`, `updated_at`)

## 🛠️ Prérequis

- PHP 8.1 ou supérieur
- Symfony 6.0 ou supérieur
- Doctrine ORM 2.14 ou supérieur
- Base de données MySQL/MariaDB configurée

## 📝 Configuration de la base de données

Assurez-vous que votre fichier `.env` contient la configuration de la base de données :

```env
DATABASE_URL="mysql://user:password@127.0.0.1:3306/database_name?serverVersion=8.0&charset=utf8mb4"
```

## 🤝 Contribution

Les contributions sont les bienvenues ! N'hésitez pas à :

- Signaler des bugs
- Proposer des nouvelles fonctionnalités
- Soumettre des pull requests

## 📄 Licence

Ce bundle est sous licence MIT. Voir le fichier [LICENSE](LICENSE) pour plus de détails.

## 👤 Auteur

**Diallo Moussa**

- GitHub: [@DImoussa](https://github.com/DImoussa)
- Email: moussadou128@gmail.com

## 🙏 Support

Si vous trouvez ce bundle utile, n'hésitez pas à lui donner une ⭐ sur GitHub !

## 📚 Documentation supplémentaire

Pour plus d'informations sur Doctrine et les relations, consultez :

- [Documentation Doctrine ORM](https://www.doctrine-project.org/projects/doctrine-orm/en/latest/)
- [Relations Doctrine](https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/association-mapping.html)

---

Développé avec ❤️ pour la communauté Symfony

