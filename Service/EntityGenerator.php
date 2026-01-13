<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Service;

use Doctrine\DBAL\Types\Types;

class EntityGenerator
{
    private RelationshipAnalyzer $relationshipAnalyzer;

    public function __construct(RelationshipAnalyzer $relationshipAnalyzer)
    {
        $this->relationshipAnalyzer = $relationshipAnalyzer;
    }

    private const REPOSITORY_TEMPLATE = <<<'PHP'
<?php

namespace App\Repository;

use App\Entity\%1$s;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<%1$s>
 */
class %1$sRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, %1$s::class);
    }

    //    /**
    //     * @return %1$s[] Returns an array of %1$s objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('e.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?%1$s
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}

PHP;

    public function generateEntityCode(
        string $tableName,
        array $columns,
        array $primaryKeys,
        array $foreignKeys = [],
        array $inverseRelations = [],
        array $manyToManyRelations = [],
        array $uniqueConstraints = []
    ): string
    {
        $className = $this->snakeToCamel($tableName, true);

        if (empty($primaryKeys)) {
            $primaryKeys = $this->detectPrimaryKey($tableName, $columns);
        }

        $isAssociationTable = $this->isAssociationTable($primaryKeys, $foreignKeys);

        // Créer un index des colonnes uniques
        $uniqueColumns = [];
        foreach ($uniqueConstraints as $constraint) {
            $uniqueColumns[] = $constraint['COLUMN_NAME'];
        }

        // Construire le header avec repository
        $code = "<?php\n\nnamespace App\\Entity;\n\n";
        $code .= "use App\\Repository\\{$className}Repository;\n";
        $code .= "use Doctrine\\ORM\\Mapping as ORM;\n";
        $code .= "use Doctrine\\Common\\Collections\\ArrayCollection;\n";
        $code .= "use Doctrine\\Common\\Collections\\Collection;\n";
        $code .= "use Doctrine\\DBAL\\Types\\Types;\n\n";
        $code .= "#[ORM\\Entity(repositoryClass: {$className}Repository::class)]\n";
        $code .= "#[ORM\\Table(name: \"$tableName\")]\n";
        $code .= "class $className\n{\n";

        $foreignCols = array_map('strtolower', array_column($foreignKeys, 'COLUMN_NAME'));
        $definedProperties = [];
        $generatedAccessors = [];
        $relationPropertyNames = [];
        $constructorNeeded = false;
        $constructorCode = "";

        // === STEP 1: Colonnes scalaires (non-FK) ===
        foreach ($columns as $column) {
            $columnName = $column['COLUMN_NAME'];
            if (in_array(strtolower($columnName), $foreignCols, true)) {
                continue;
            }

            $propertyName = $this->snakeToCamel($columnName);
            if (isset($definedProperties[$propertyName])) {
                continue;
            }

            $isPrimaryKey = in_array($columnName, $primaryKeys, true) && !$isAssociationTable;
            $isUnique = in_array($columnName, $uniqueColumns, true);
            $code .= $this->generatePropertyCode($column, $isPrimaryKey, $isUnique);
            $definedProperties[$propertyName] = true;
        }

        // === STEP 2: Relations ManyToOne (FK) ===
        // Détecter les FK multiples vers la même table pour générer des noms distincts
        $fkGroupedByTarget = [];
        foreach ($foreignKeys as $fk) {
            $targetTable = $fk['REFERENCED_TABLE_NAME'];
            if (!isset($fkGroupedByTarget[$targetTable])) {
                $fkGroupedByTarget[$targetTable] = [];
            }
            $fkGroupedByTarget[$targetTable][] = $fk;
        }

        // Stocker les inversedBy générés pour les réutiliser en STEP 3 côté inverse
        $generatedInversedBy = [];

        foreach ($foreignKeys as $fk) {
            $targetEntity = $this->snakeToCamel($fk['REFERENCED_TABLE_NAME'], true);
            $targetTable = $fk['REFERENCED_TABLE_NAME'];

            // Si plusieurs FK pointent vers la même table, utiliser un nom sémantique basé sur la colonne
            $hasMultipleFKToSameTable = count($fkGroupedByTarget[$targetTable]) > 1;

            if ($hasMultipleFKToSameTable) {
                // Extraire un nom sémantique de la colonne (ex: sender_id → sender, receiver_id → receiver)
                $columnName = $fk['COLUMN_NAME'];
                $propertyName = $this->extractSemanticName($columnName, $targetTable);
            } else {
                // Nom standard basé sur l'entité au singulier
                $propertyName = $this->cleanRelationPropertyName($fk['COLUMN_NAME'], $fk['REFERENCED_TABLE_NAME']);
            }

            // Gérer les collisions de noms
            $originalPropertyName = $propertyName;
            $counter = 2;
            while (isset($definedProperties[$propertyName])) {
                $propertyName = $originalPropertyName . $counter;
                $counter++;
            }

            $columnDefinition = $this->getColumnDefinition($columns, $fk['COLUMN_NAME']);
            $nullable = $columnDefinition['IS_NULLABLE'] ?? 'YES';
            $isPrimaryFk = in_array($fk['COLUMN_NAME'], $primaryKeys, true);

            if ($isPrimaryFk) {
                $code .= "    #[ORM\\Id]\n";
            }

            // inversedBy avec un nom basé sur la propriété si multiples FK vers même table
            if ($hasMultipleFKToSameTable) {
                // Ex: $sender → inversedBy: 'sentMessages', $receiver → inversedBy: 'receivedMessages'
                $inversedByName = $this->generateInversedByName($propertyName, lcfirst($className));
            } else {
                // inversedBy standard avec le nom au pluriel de l'entité courante
                $inversedByName = $this->pluralize(lcfirst($className));
            }

            // Stocker pour STEP 3
            $generatedInversedBy[$targetTable][$fk['COLUMN_NAME']] = [
                'inversedBy' => $inversedByName,
                'mappedBy' => $propertyName,
            ];

            $code .= "    #[ORM\\ManyToOne(targetEntity: {$targetEntity}::class, inversedBy: '{$inversedByName}')]\n";
            $code .= "    #[ORM\\JoinColumn(name: \"{$fk['COLUMN_NAME']}\", referencedColumnName: \"{$fk['REFERENCED_COLUMN_NAME']}\", nullable: " . ($nullable === 'YES' ? 'true' : 'false') . ")]\n";
            $code .= "    private ?$targetEntity \$$propertyName = null;\n\n";
            $definedProperties[$propertyName] = true;
            $relationPropertyNames[$fk['COLUMN_NAME']] = $propertyName;
        }

        // === STEP 3: Relations OneToMany (côté inverse) ===
        $addedInverseRelations = []; // Pour éviter les doublons

        // Grouper les relations inverses par table source pour détecter les FK multiples
        $inverseGroupedBySource = [];
        foreach ($inverseRelations as $relation) {
            $sourceTable = $relation['sourceTable'];
            if (!isset($inverseGroupedBySource[$sourceTable])) {
                $inverseGroupedBySource[$sourceTable] = [];
            }
            $inverseGroupedBySource[$sourceTable][] = $relation;
        }

        foreach ($inverseRelations as $relation) {
            $targetEntity = $this->snakeToCamel($relation['sourceTable'], true);
            $sourceTable = $relation['sourceTable'];
            $columnName = $relation['columnName'];

            // Vérifier s'il y a plusieurs FK de la table source vers cette table
            $hasMultipleFKFromSource = count($inverseGroupedBySource[$sourceTable]) > 1;

            // Déterminer le mappedBy (doit correspondre au nom de propriété généré en STEP 2 de l'entité source)
            if ($hasMultipleFKFromSource) {
                // Utiliser extractSemanticName pour retrouver le nom exact
                $mappedByProperty = $this->extractSemanticName($columnName, $tableName);
            } else {
                $mappedByProperty = $this->cleanRelationPropertyName($columnName, $tableName);
            }

            // Créer une clé unique pour cette relation
            $relationKey = $targetEntity . '::' . $mappedByProperty;

            // Éviter les doublons (même entité + même mappedBy)
            if (isset($addedInverseRelations[$relationKey])) {
                continue;
            }
            $addedInverseRelations[$relationKey] = true;

            // Nom de propriété basé sur inversedBy qui serait généré côté source
            if ($hasMultipleFKFromSource) {
                // Générer le même nom qu'en STEP 2 de l'entité source
                $propertyName = $this->generateInversedByName($mappedByProperty, lcfirst($targetEntity));
            } else {
                // Nom standard au pluriel
                $propertyName = $this->pluralize(lcfirst($targetEntity));
            }

            // Gérer les collisions de noms de propriété
            $counter = 2;
            while (isset($definedProperties[$propertyName])) {
                if ($hasMultipleFKFromSource) {
                    $propertyName = $this->generateInversedByName($mappedByProperty, lcfirst($targetEntity)) . $counter;
                } else {
                    $propertyName = $this->pluralize(lcfirst($targetEntity)) . $counter;
                }
                $counter++;
            }

            $code .= "    #[ORM\\OneToMany(mappedBy: '{$mappedByProperty}', targetEntity: {$targetEntity}::class)]\n";
            $code .= "    private Collection \${$propertyName};\n\n";
            $definedProperties[$propertyName] = true;

            $constructorNeeded = true;
            $constructorCode .= "        \$this->{$propertyName} = new ArrayCollection();\n";
        }

        // === STEP 4: Relations ManyToMany ===
        foreach ($manyToManyRelations as $relation) {
            $targetEntity = $this->snakeToCamel($relation['targetEntity'], true);
            $propertyName = $this->pluralize(lcfirst($targetEntity));

            $counter = 2;
            while (isset($definedProperties[$propertyName])) {
                $propertyName = $this->pluralize(lcfirst($targetEntity)) . $counter;
                $counter++;
            }

            if ($relation['isOwner']) {
                // Côté propriétaire
                $code .= "    #[ORM\\ManyToMany(targetEntity: {$targetEntity}::class, inversedBy: '" . $this->pluralize(lcfirst($className)) . "')]\n";
                $code .= "    #[ORM\\JoinTable(name: '{$relation['joinTable']}')]\n";
                // Utiliser les vraies colonnes de référence de la base de données
                $code .= "    #[ORM\\JoinColumn(name: '{$relation['joinColumn']}', referencedColumnName: '{$relation['joinReferencedColumn']}')]\n";
                $code .= "    #[ORM\\InverseJoinColumn(name: '{$relation['inverseJoinColumn']}', referencedColumnName: '{$relation['inverseJoinReferencedColumn']}')]\n";
            } else {
                // Côté inverse
                $code .= "    #[ORM\\ManyToMany(targetEntity: {$targetEntity}::class, mappedBy: '" . $this->pluralize(lcfirst($className)) . "')]\n";
            }

            $code .= "    private Collection \${$propertyName};\n\n";
            $definedProperties[$propertyName] = true;

            $constructorNeeded = true;
            $constructorCode .= "        \$this->{$propertyName} = new ArrayCollection();\n";
        }

        // === STEP 5: Constructeur si nécessaire ===
        if ($constructorNeeded) {
            $code .= "    public function __construct()\n    {\n";
            $code .= $constructorCode;
            $code .= "    }\n\n";
        }

        // === STEP 6: Getters/Setters pour colonnes scalaires ===
        foreach ($columns as $column) {
            $columnName = $column['COLUMN_NAME'];
            if (in_array(strtolower($columnName), $foreignCols, true)) {
                continue;
            }

            $propertyName = $this->snakeToCamel($columnName);
            if (isset($generatedAccessors[$propertyName])) {
                continue;
            }

            $isPrimaryKey = in_array($columnName, $primaryKeys, true) && !$isAssociationTable;
            $code .= $this->generateGetterSetterCode($column, $isPrimaryKey);
            $generatedAccessors[$propertyName] = true;
        }

        // === STEP 7: Getters/Setters pour ManyToOne ===
        foreach ($foreignKeys as $fk) {
            $targetEntity = $this->snakeToCamel($fk['REFERENCED_TABLE_NAME'], true);
            $propertyName = $relationPropertyNames[$fk['COLUMN_NAME']] ?? $this->cleanRelationPropertyName($fk['COLUMN_NAME'], $fk['REFERENCED_TABLE_NAME']);

            if (isset($generatedAccessors[$propertyName])) {
                continue;
            }

            $methodName = ucfirst($propertyName);
            $code .= "    public function get$methodName(): ?$targetEntity\n    {\n        return \$this->$propertyName;\n    }\n\n";
            $code .= "    public function set$methodName(?$targetEntity \$$propertyName): static\n    {\n        \$this->$propertyName = \$$propertyName;\n\n        return \$this;\n    }\n\n";
            $generatedAccessors[$propertyName] = true;
        }

        // === STEP 8: Méthodes pour OneToMany ===
        $processedOneToManyMethods = [];

        // Grouper à nouveau pour correspondre à STEP 3
        $inverseGroupedBySource = [];
        foreach ($inverseRelations as $relation) {
            $sourceTable = $relation['sourceTable'];
            if (!isset($inverseGroupedBySource[$sourceTable])) {
                $inverseGroupedBySource[$sourceTable] = [];
            }
            $inverseGroupedBySource[$sourceTable][] = $relation;
        }

        foreach ($inverseRelations as $relation) {
            $targetEntity = $this->snakeToCamel($relation['sourceTable'], true);
            $sourceTable = $relation['sourceTable'];
            $columnName = $relation['columnName'];

            // Vérifier s'il y a plusieurs FK de la table source vers cette table
            $hasMultipleFKFromSource = count($inverseGroupedBySource[$sourceTable]) > 1;

            // Déterminer le mappedBy
            if ($hasMultipleFKFromSource) {
                $mappedByProperty = $this->extractSemanticName($columnName, $tableName);
            } else {
                $mappedByProperty = $this->cleanRelationPropertyName($columnName, $tableName);
            }

            // Créer la même clé unique que dans STEP 3
            $relationKey = $targetEntity . '::' . $mappedByProperty;

            // Éviter les doublons
            if (isset($processedOneToManyMethods[$relationKey])) {
                continue;
            }
            $processedOneToManyMethods[$relationKey] = true;

            // Retrouver le nom réel de la propriété définie en STEP 3
            if ($hasMultipleFKFromSource) {
                $realPropertyName = $this->generateInversedByName($mappedByProperty, lcfirst($targetEntity));
            } else {
                $realPropertyName = $this->pluralize(lcfirst($targetEntity));
            }

            // Vérifier que la collection existe
            if (!isset($definedProperties[$realPropertyName])) {
                continue;
            }

            // Nom de la méthode basé sur le nom de la collection pour éviter les doublons
            $singularCollectionName = $this->singularize($realPropertyName);
            $methodBase = ucfirst($singularCollectionName);
            $collectionMethodName = ucfirst($realPropertyName);

            $code .= "    /**\n";
            $code .= "     * @return Collection<int, {$targetEntity}>\n";
            $code .= "     */\n";
            $code .= "    public function get{$collectionMethodName}(): Collection\n    {\n        return \$this->{$realPropertyName};\n    }\n\n";

            $code .= "    public function add{$methodBase}({$targetEntity} \${$singularCollectionName}): static\n    {\n";
            $code .= "        if (!\$this->{$realPropertyName}->contains(\${$singularCollectionName})) {\n";
            $code .= "            \$this->{$realPropertyName}->add(\${$singularCollectionName});\n";
            $code .= "            \${$singularCollectionName}->set" . ucfirst($mappedByProperty) . "(\$this);\n";
            $code .= "        }\n\n";
            $code .= "        return \$this;\n    }\n\n";

            $code .= "    public function remove{$methodBase}({$targetEntity} \${$singularCollectionName}): static\n    {\n";
            $code .= "        if (\$this->{$realPropertyName}->removeElement(\${$singularCollectionName})) {\n";
            $code .= "            if (\${$singularCollectionName}->get" . ucfirst($mappedByProperty) . "() === \$this) {\n";
                $code .= "                \${$singularCollectionName}->set" . ucfirst($mappedByProperty) . "(null);\n";
            $code .= "            }\n";
            $code .= "        }\n\n";
            $code .= "        return \$this;\n    }\n\n";
        }

        // === STEP 9: Méthodes pour ManyToMany ===
        $processedManyToManyMethods = [];
        foreach ($manyToManyRelations as $relation) {
            $targetEntity = $this->snakeToCamel($relation['targetEntity'], true);

            // Retrouver le vrai nom de propriété ManyToMany défini en STEP 4
            $realPropertyName = null;
            foreach ($definedProperties as $prop => $val) {
                if (str_starts_with($prop, lcfirst($targetEntity))) {
                    if (!isset($processedManyToManyMethods[$prop]) && !isset($processedOneToManyMethods[$prop])) {
                        $realPropertyName = $prop;
                        break;
                    }
                }
            }

            if (!$realPropertyName) {
                continue;
            }
            $processedManyToManyMethods[$realPropertyName] = true;

            $singularName = rtrim($realPropertyName, 's');
            $methodBase   = ucfirst($singularName);
            $collectionMethodName = ucfirst($realPropertyName);

            $code .= "    /**\n";
            $code .= "     * @return Collection<int, {$targetEntity}>\n";
            $code .= "     */\n";
            $code .= "    public function get{$collectionMethodName}(): Collection\n    {\n        return \$this->{$realPropertyName};\n    }\n\n";

            $code .= "    public function add{$methodBase}({$targetEntity} \${$singularName}): static\n    {\n";
            $code .= "        if (!\$this->{$realPropertyName}->contains(\${$singularName})) {\n";
            $code .= "            \$this->{$realPropertyName}->add(\${$singularName});\n";
            if (!$relation['isOwner']) {
                $code .= "            \${$singularName}->add" . ucfirst(lcfirst($className)) . "(\$this);\n";
            }
            $code .= "        }\n\n";
            $code .= "        return \$this;\n    }\n\n";

            $code .= "    public function remove{$methodBase}({$targetEntity} \${$singularName}): static\n    {\n";
            $code .= "        if (\$this->{$realPropertyName}->removeElement(\${$singularName})) {\n";
            if (!$relation['isOwner']) {
                $code .= "            \${$singularName}->remove" . ucfirst(lcfirst($className)) . "(\$this);\n";
            }
            $code .= "        }\n\n";
            $code .= "        return \$this;\n    }\n\n";
        }

        $code .= "}\n";

        return $code;
    }

    /**
     * Extrait un nom sémantique d'une colonne FK
     * Ex: sender_id → sender, author_id → author, receiver_id → receiver
     */
    private function extractSemanticName(string $columnName, string $targetTable): string
    {
        // Convertir en camelCase
        $camelName = $this->snakeToCamel($columnName);

        // Enlever le préfixe "id" si présent
        if (str_starts_with($camelName, 'id')) {
            $camelName = lcfirst(substr($camelName, 2));
        }

        // Si le nom se termine par "Id", l'enlever
        if (str_ends_with($camelName, 'Id')) {
            $camelName = substr($camelName, 0, -2);
        }

        // Si on a quelque chose de valide, le retourner
        if (!empty($camelName)) {
            return $camelName;
        }

        // Sinon, utiliser le nom de la table cible au singulier
        return lcfirst($this->singularize($this->snakeToCamel($targetTable)));
    }

    /**
     * Génère un inversedBy adapté pour les relations multiples
     * Ex: sender → sentMessages, receiver → receivedMessages, author → authoredPosts
     */
    private function generateInversedByName(string $propertyName, string $entityName): string
    {
        // Règles de transformation
        $transforms = [
            'sender' => 'sent',
            'receiver' => 'received',
            'author' => 'authored',
            'creator' => 'created',
            'owner' => 'owned',
            'parent' => 'child',
        ];

        $lowerProperty = strtolower($propertyName);

        // Chercher une transformation connue
        foreach ($transforms as $key => $prefix) {
            if (str_contains($lowerProperty, $key)) {
                return $prefix . ucfirst($this->pluralize($entityName));
            }
        }

        // Par défaut : propertyName + entityNamePlural
        // Ex: user → userMessages, friend → friendMessages
        return $propertyName . ucfirst($this->pluralize($entityName));
    }

    /**
     * Nettoie le nom de propriété pour les relations en enlevant les préfixes "id"
     * et en mettant au singulier (convention Doctrine pour ManyToOne)
     */
    private function cleanRelationPropertyName(string $columnName, string $referencedTable): string
    {
        $propertyName = $this->snakeToCamel($columnName);
        $referencedEntityName = $this->snakeToCamel($referencedTable);

        // Si la propriété commence par "id", on l'enlève
        if (str_starts_with($propertyName, 'id')) {
            $withoutId = substr($propertyName, 2);
            // Vérifier si ce qui reste correspond à l'entité référencée
            if (strcasecmp($withoutId, $referencedEntityName) === 0) {
                return lcfirst($withoutId);
            }
        }

        // Sinon, utiliser le nom de l'entité référencée au SINGULIER
        return lcfirst($this->singularize($referencedEntityName));
    }

    /**
     * Convertit un nom au singulier (simple heuristique)
     */
    private function singularize(string $word): string
    {
        // Cas spéciaux
        $irregulars = [
            'People' => 'Person',
            'people' => 'person',
            'Children' => 'Child',
            'children' => 'child',
        ];

        if (isset($irregulars[$word])) {
            return $irregulars[$word];
        }

        // Règle simple: enlever le 's' final si présent
        if (str_ends_with($word, 's') && strlen($word) > 1) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * Convertit un nom au pluriel (heuristique simple)
     */
    private function pluralize(string $word): string
    {
        // Cas spéciaux
        $irregulars = [
            'person' => 'people',
            'Person' => 'People',
            'child' => 'children',
            'Child' => 'Children',
        ];

        if (isset($irregulars[$word])) {
            return $irregulars[$word];
        }

        // Ne pas ajouter de 's' si déjà au pluriel
        if (str_ends_with($word, 's')) {
            return $word;
        }

        // Règles simples de pluralisation
        if (str_ends_with($word, 'y') && !in_array(substr($word, -2, 1), ['a', 'e', 'i', 'o', 'u'])) {
            return substr($word, 0, -1) . 'ies';
        }

        if (str_ends_with($word, 'ch') || str_ends_with($word, 'sh') ||
            str_ends_with($word, 'ss') || str_ends_with($word, 'x') || str_ends_with($word, 'z')) {
            return $word . 'es';
        }

        return $word . 's';
    }


    /**
     * Trouve le nom de la propriété inverse pour mappedBy/inversedBy
     */
    private function findInverseSide(string $currentTable, array $foreignKey, array $inverseRelations): ?string
    {
        // Le currentTable est la table qui CONTIENT la FK
        // On cherche dans inverseRelations les relations où la table REFERENCEE = REFERENCED_TABLE_NAME
        // et où la colonne FK = COLUMN_NAME

        foreach ($inverseRelations as $relation) {
            // Si cette relation inverse pointe vers notre table actuelle avec cette FK
            if ($relation['sourceTable'] === $currentTable &&
                $relation['columnName'] === $foreignKey['COLUMN_NAME']) {
                // Le nom de la collection dans l'entité référencée
                $targetEntity = $this->snakeToCamel($currentTable, true);
                return lcfirst($targetEntity) . 's';
            }
        }
        return null;
    }

    /**
     * Trouve la clé primaire d'une table (simplifié pour les JoinColumn)
     */
    private function findPrimaryKey(string $tableName): string
    {
        // Par défaut, on suppose id + nom de table
        return 'id' . $this->snakeToCamel($tableName, true);
    }

    private function generatePropertyCode(array $column, bool $isPrimaryKey, bool $isUnique = false): string
    {
        $phpType = $this->mapDoctrineTypeToPhp($column['DATA_TYPE']);
        $nullable = $column['IS_NULLABLE'] === 'YES';

        $attributes = [];

        if ($isPrimaryKey) {
            $attributes[] = "    #[ORM\Id]";
            if (in_array($column['DATA_TYPE'], ['int', 'bigint', 'smallint'])) {
                $attributes[] = "    #[ORM\GeneratedValue]";
            }
            // Les IDs auto-générés sont toujours nullable avant persist
            $nullable = true;
        }

        $ormType = $this->mapToORMType($column['DATA_TYPE'], $column['COLUMN_TYPE']);

        // Ajouter le nom de la colonne explicitement
        $columnAttr = "    #[ORM\Column(name: \"{$column['COLUMN_NAME']}\", type: $ormType";
        if (preg_match('/char|string/', $column['DATA_TYPE'])) {
            if (preg_match('/\((\d+)\)/', $column['COLUMN_TYPE'], $matches)) {
                $length = $matches[1];
                $columnAttr .= ", length: $length";
            }
        }

        // Ajouter unique: true si la colonne a une contrainte UNIQUE
        if ($isUnique && !$isPrimaryKey) {
            $columnAttr .= ", unique: true";
        }

        if ($nullable && !$isPrimaryKey) {
            $columnAttr .= ", nullable: true";
        }

        $columnAttr .= ")]";

        $attributes[] = $columnAttr;

        $propertyName = $this->snakeToCamel($column['COLUMN_NAME']);

        $code  = implode("\n", $attributes) . "\n";
        $code .= "    private " . ($nullable ? '?' : '') . "$phpType \$$propertyName";

        // Initialiser les types nullables
        if ($nullable) {
            $code .= " = null";
        }

        $code .= ";\n\n";

        return $code;
    }

    private function generateGetterSetterCode(array $column, bool $isPrimaryKey = false): string
    {
        $phpType = $this->mapDoctrineTypeToPhp($column['DATA_TYPE']);
        $nullable = $column['IS_NULLABLE'] === 'YES';
        $propertyName = $this->snakeToCamel($column['COLUMN_NAME']);
        $methodName = ucfirst($propertyName);

        // Les IDs auto-générés sont toujours nullable avant persist
        if ($isPrimaryKey) {
            $nullable = true;
        }

        $nullableType = $nullable ? '?' : '';

        // Getter
        $code  = "    public function get$methodName(): {$nullableType}$phpType\n    {\n";
        $code .= "        return \$this->$propertyName;\n    }\n\n";

        // Setter : NE PAS générer pour les clés primaires auto-générées
        if (!$isPrimaryKey) {
            $code .= "    public function set$methodName({$nullableType}$phpType \$$propertyName): self\n    {\n";
            $code .= "        \$this->$propertyName = \$$propertyName;\n\n";
            $code .= "        return \$this;\n    }\n\n";
        }

        return $code;
    }

    public function snakeToCamel(string $string, bool $capitalizeFirst = false): string
    {
        $result = str_replace('_', '', ucwords($string, '_'));
        if (!$capitalizeFirst) {
            $result = lcfirst($result);
        }
        return $result;
    }

    private function mapDoctrineTypeToPhp(string $doctrineType): string
    {
        $type = strtolower($doctrineType);

        return match ($type) {
            // Numériques entiers
            'int', 'integer', 'bigint', 'smallint', 'mediumint' => 'int',

            // Booléens
            'bool', 'boolean', 'tinyint' => 'bool',

            // Chaînes de caractères / textes
            'varchar', 'char', 'string', 'text', 'longtext', 'mediumtext', 'enum', 'set' => 'string',

            // Dates & temps
            'datetime', 'datetimetz', 'timestamp' => '\\DateTimeInterface',
            'datetime_immutable', 'datetimetz_immutable' => '\\DateTimeImmutable',
            'date', 'date_immutable' => '\\DateTimeInterface',
            'time', 'time_immutable' => '\\DateTimeInterface',

            // Numériques à virgule
            'float', 'double', 'real' => 'float',
            // Pour decimal/numeric, on peut préférer string pour éviter les pertes de précision
            'decimal', 'numeric' => 'string',

            // JSON → tableau typé plutôt que mixed
            'json' => 'array',

            // Fallback : on ne renvoie plus mixed, on reste sur string qui est plus sûr
            default => 'string',
        };
    }

    private function mapToORMType(string $dataType, string $columnType): string
    {
        $dataType = strtolower($dataType);

        return match ($dataType) {
            // Numériques entiers
            'int', 'integer', 'smallint' => 'Types::INTEGER',
            'bigint' => 'Types::BIGINT',
            'mediumint' => 'Types::INTEGER', // mappé sur INTEGER côté Doctrine

            // Chaînes
            'varchar', 'char', 'string' => 'Types::STRING',
            'text', 'longtext', 'mediumtext' => 'Types::TEXT',
            'enum', 'set' => 'Types::STRING',

            // Dates & temps
            'datetime' => 'Types::DATETIME_MUTABLE',
            'datetimetz' => 'Types::DATETIMETZ_MUTABLE',
            'datetime_immutable' => 'Types::DATETIME_IMMUTABLE',
            'datetimetz_immutable' => 'Types::DATETIMETZ_IMMUTABLE',
            'timestamp' => 'Types::DATETIME_MUTABLE',
            'date' => 'Types::DATE_MUTABLE',
            'date_immutable' => 'Types::DATE_IMMUTABLE',
            'time' => 'Types::TIME_MUTABLE',
            'time_immutable' => 'Types::TIME_IMMUTABLE',

            // Numériques
            'float', 'double', 'real' => 'Types::FLOAT',
            'decimal', 'numeric' => 'Types::DECIMAL',

            // Booléens
            'bool', 'boolean', 'tinyint' => 'Types::BOOLEAN',

            // JSON
            'json' => 'Types::JSON',

            // Fallback
            default => 'Types::STRING',
        };
    }

    public function generateRepositoryCode(string $entityClass): string
    {
        return sprintf(self::REPOSITORY_TEMPLATE, $entityClass);
    }

    /**
     * Détecte automatiquement la clé primaire si elle n'est pas définie dans la base
     * Cherche une colonne nommée "id" + nom de la table (ex: idProprietaire pour Proprietaire)
     * ou simplement "id"
     */
    public function detectPrimaryKey(string $tableName, array $columns): array
    {
        // Liste des patterns possibles pour les clés primaires
        $patterns = [
            'id' . $tableName,
            'id' . ucfirst($tableName),
            'id' . strtolower($tableName),
            'id' . lcfirst($tableName),
            'id_' . strtolower($tableName),
            'id',
            $tableName . 'Id',
            $tableName . '_id',
            strtolower($tableName) . '_id',
        ];

        foreach ($columns as $column) {
            $columnName = $column['COLUMN_NAME'];

            // Vérifier si le nom de colonne correspond à un pattern
            if (in_array($columnName, $patterns, true)) {
                return [$columnName];
            }

            // Vérifier si la colonne contient "id" et le nom de la table (insensible à la casse)
            $lowerColumn = strtolower($columnName);
            $lowerTable = strtolower($tableName);
            if (str_contains($lowerColumn, 'id') && str_contains($lowerColumn, $lowerTable)) {
                return [$columnName];
            }
        }

        return [];
    }

    private function getColumnDefinition(array $columns, string $columnName): ?array
    {
        foreach ($columns as $column) {
            if (strcasecmp($column['COLUMN_NAME'], $columnName) === 0) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Vérifie si une table est une table d'association
     * (toutes les clés primaires sont des clés étrangères)
     */
    private function isAssociationTable(array $primaryKeys, array $foreignKeys): bool
    {
        if (empty($primaryKeys)) {
            return false;
        }

        $foreignKeyColumns = array_column($foreignKeys, 'COLUMN_NAME');

        foreach ($primaryKeys as $pk) {
            if (!in_array($pk, $foreignKeyColumns, true)) {
                return false;
            }
        }

        return true;
    }

    public function isCompositePrimaryKey(array $primaryKeys): bool
    {
        return false; // Désactiver l'utilisation d'Embeddable
    }

    public function generateEmbeddableCode(string $tableName, array $columns, array $primaryKeys): string
    {
        $className = $this->snakeToCamel($tableName, true) . 'Id';

        $code = "<?php\n\nnamespace App\\Entity;\n\nuse Doctrine\\ORM\\Mapping as ORM;\nuse Doctrine\\DBAL\\Types\\Types;\n\n#[ORM\\Embeddable]\nclass $className\n{\n";

        foreach ($primaryKeys as $pk) {
            $columnDefinition = $this->getColumnDefinition($columns, $pk);
            if (!$columnDefinition) {
                continue;
            }
            $code .= $this->generateEmbeddablePropertyCode($columnDefinition);
        }

        foreach ($primaryKeys as $pk) {
            $columnDefinition = $this->getColumnDefinition($columns, $pk);
            if (!$columnDefinition) {
                continue;
            }
            $code .= $this->generateEmbeddableGetterSetterCode($columnDefinition);
        }

        $code .= "}\n";

        return $code;
    }

    private function generateEmbeddablePropertyCode(array $column): string
    {
        $phpType = $this->mapDoctrineTypeToPhp($column['DATA_TYPE']);
        $nullable = $column['IS_NULLABLE'] === 'YES';
        $ormType = $this->mapToORMType($column['DATA_TYPE'], $column['COLUMN_TYPE']);
        $propertyName = $this->snakeToCamel($column['COLUMN_NAME']);

        $columnAttr = "    #[ORM\\Column(name: \"{$column['COLUMN_NAME']}\", type: $ormType";

        if (preg_match('/char|string/', $column['DATA_TYPE'])) {
            if (preg_match('/\\((\\d+)\\)/', $column['COLUMN_TYPE'], $matches)) {
                $length = $matches[1];
                $columnAttr .= ", length: $length";
            }
        }

        if ($nullable) {
            $columnAttr .= ", nullable: true";
        }

        $columnAttr .= ")]";

        $code  = "$columnAttr\n";
        $code .= "    private " . ($nullable ? '?' : '') . "$phpType \$$propertyName;\n\n";

        return $code;
    }

    private function generateEmbeddableGetterSetterCode(array $column): string
    {
        $phpType = $this->mapDoctrineTypeToPhp($column['DATA_TYPE']);
        $nullable = $column['IS_NULLABLE'] === 'YES';
        $propertyName = $this->snakeToCamel($column['COLUMN_NAME']);
        $methodName = ucfirst($propertyName);
        $nullableType = $nullable ? '?' : '';

        $code  = "    public function get$methodName(): {$nullableType}$phpType\n    {\n        return \$this->$propertyName;\n    }\n\n";
        $code .= "    public function set$methodName({$nullableType}$phpType \$$propertyName): self\n    {\n        \$this->$propertyName = \$$propertyName;\n\n        return \$this;\n    }\n\n";

        return $code;
    }

    private function resolveRelationPropertyName(array $fk, array $definedProperties, bool $preferTargetName = false, string $embeddedId = 'id'): string
    {
        $baseName = $preferTargetName
            ? $this->snakeToCamel($fk['REFERENCED_TABLE_NAME'])
            : $this->snakeToCamel($fk['COLUMN_NAME']);

        if ($baseName === $embeddedId) {
            $baseName = $this->snakeToCamel($fk['REFERENCED_TABLE_NAME']);
        }

        $propertyName = $baseName;
        $suffix = 1;

        while (isset($definedProperties[$propertyName])) {
            $propertyName = $baseName . ++$suffix;
        }

        return $propertyName;
    }
}
