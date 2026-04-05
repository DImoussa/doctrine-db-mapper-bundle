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

        $uniqueColumns = [];
        foreach ($uniqueConstraints as $constraint) {
            $uniqueColumns[] = $constraint['COLUMN_NAME'];
        }

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

        // Colonnes scalaires
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
            $code .= $this->generatePropertyCode($column, $isPrimaryKey, $isUnique, count($primaryKeys) > 1);
            $definedProperties[$propertyName] = true;
        }

        // Relations ManyToOne â€” regrouper les FK par table cible pour dÃ©tecter les FK multiples
        $fkGroupedByTarget = [];
        foreach ($foreignKeys as $fk) {
            $targetTable = $fk['REFERENCED_TABLE_NAME'];
            $fkGroupedByTarget[$targetTable][] = $fk;
        }

        $generatedInversedBy = [];

        foreach ($foreignKeys as $fk) {
            $targetEntity = $this->snakeToCamel($fk['REFERENCED_TABLE_NAME'], true);
            $targetTable = $fk['REFERENCED_TABLE_NAME'];
            $hasMultipleFKToSameTable = count($fkGroupedByTarget[$targetTable]) > 1;

            if ($hasMultipleFKToSameTable) {
                $propertyName = $this->extractSemanticName($fk['COLUMN_NAME'], $targetTable);
            } else {
                $propertyName = $this->cleanRelationPropertyName($fk['COLUMN_NAME'], $fk['REFERENCED_TABLE_NAME']);
            }

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

            if ($hasMultipleFKToSameTable) {
                $inversedByName = $this->generateInversedByName($propertyName, lcfirst($className));
            } else {
                $inversedByName = $this->pluralize(lcfirst($className));
            }

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

        // Relations OneToMany (cÃ´tÃ© inverse)
        $addedInverseRelations = [];
        $inverseGroupedBySource = [];
        foreach ($inverseRelations as $relation) {
            $inverseGroupedBySource[$relation['sourceTable']][] = $relation;
        }

        foreach ($inverseRelations as $relation) {
            $targetEntity = $this->snakeToCamel($relation['sourceTable'], true);
            $sourceTable = $relation['sourceTable'];
            $columnName = $relation['columnName'];
            $hasMultipleFKFromSource = count($inverseGroupedBySource[$sourceTable]) > 1;
            $mappedByProperty = $hasMultipleFKFromSource
                ? $this->extractSemanticName($columnName, $tableName)
                : $this->cleanRelationPropertyName($columnName, $tableName);

            $relationKey = $targetEntity . '::' . $mappedByProperty;
            if (isset($addedInverseRelations[$relationKey])) {
                continue;
            }
            $addedInverseRelations[$relationKey] = true;

            $propertyName = $hasMultipleFKFromSource
                ? $this->generateInversedByName($mappedByProperty, lcfirst($targetEntity))
                : $this->pluralize(lcfirst($targetEntity));

            $counter = 2;
            while (isset($definedProperties[$propertyName])) {
                $propertyName = $hasMultipleFKFromSource
                    ? $this->generateInversedByName($mappedByProperty, lcfirst($targetEntity)) . $counter
                    : $this->pluralize(lcfirst($targetEntity)) . $counter;
                $counter++;
            }

            $code .= "    #[ORM\\OneToMany(mappedBy: '{$mappedByProperty}', targetEntity: {$targetEntity}::class)]\n";
            $code .= "    private Collection \${$propertyName};\n\n";
            $definedProperties[$propertyName] = true;
            $constructorNeeded = true;
            $constructorCode .= "        \$this->{$propertyName} = new ArrayCollection();\n";
        }

        // Relations ManyToMany
        $manyToManyPropertyMap = [];
        foreach ($manyToManyRelations as $relation) {
            $targetEntity = $this->snakeToCamel($relation['targetEntity'], true);
            $propertyName = $relation['propertyName'] ?? $this->pluralize(lcfirst($targetEntity));

            if ($relation['isOwner']) {
                $inverseProperty = $relation['inversedBy'] ?? $this->pluralize(lcfirst($className));
                $code .= "    #[ORM\\ManyToMany(targetEntity: {$targetEntity}::class, inversedBy: '{$inverseProperty}')]\n";
                $code .= "    #[ORM\\JoinTable(name: '{$relation['joinTable']}')]\n";
                $code .= "    #[ORM\\JoinColumn(name: '{$relation['joinColumn']}', referencedColumnName: '{$relation['joinReferencedColumn']}')]\n";
                $code .= "    #[ORM\\InverseJoinColumn(name: '{$relation['inverseJoinColumn']}', referencedColumnName: '{$relation['inverseJoinReferencedColumn']}')]\n";
            } else {
                $mappedBy = $relation['mappedBy'] ?? $this->pluralize(lcfirst($className));
                $code .= "    #[ORM\\ManyToMany(targetEntity: {$targetEntity}::class, mappedBy: '{$mappedBy}')]\n";
            }

            $code .= "    private Collection \${$propertyName};\n\n";
            $definedProperties[$propertyName] = true;
            $constructorNeeded = true;
            $constructorCode .= "        \$this->{$propertyName} = new ArrayCollection();\n";

            $manyToManyPropertyMap[] = [
                'propertyName' => $propertyName,
                'targetEntity' => $targetEntity,
                'relation' => $relation,
            ];
        }

        if ($constructorNeeded) {
            $code .= "    public function __construct()\n    {\n";
            $code .= $constructorCode;
            $code .= "    }\n\n";
        }

        // Getters/Setters pour colonnes scalaires
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

        // Getters/Setters pour ManyToOne
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

        // MÃ©thodes de collection pour OneToMany
        $processedOneToManyMethods = [];
        $inverseGroupedBySource = [];
        foreach ($inverseRelations as $relation) {
            $inverseGroupedBySource[$relation['sourceTable']][] = $relation;
        }

        foreach ($inverseRelations as $relation) {
            $targetEntity = $this->snakeToCamel($relation['sourceTable'], true);
            $sourceTable = $relation['sourceTable'];
            $columnName = $relation['columnName'];
            $hasMultipleFKFromSource = count($inverseGroupedBySource[$sourceTable]) > 1;

            $mappedByProperty = $hasMultipleFKFromSource
                ? $this->extractSemanticName($columnName, $tableName)
                : $this->cleanRelationPropertyName($columnName, $tableName);

            $relationKey = $targetEntity . '::' . $mappedByProperty;
            if (isset($processedOneToManyMethods[$relationKey])) {
                continue;
            }
            $processedOneToManyMethods[$relationKey] = true;

            $realPropertyName = $hasMultipleFKFromSource
                ? $this->generateInversedByName($mappedByProperty, lcfirst($targetEntity))
                : $this->pluralize(lcfirst($targetEntity));

            if (!isset($definedProperties[$realPropertyName])) {
                continue;
            }

            $singularCollectionName = $this->singularize($realPropertyName);
            $methodBase = ucfirst($singularCollectionName);
            $collectionMethodName = ucfirst($realPropertyName);
            $code .= "    /**\n";
            $code .= "     * @return Collection<int, {$targetEntity}>\n";
            $code .= "     */\n";
            $code .= "    public function get{$collectionMethodName}(): Collection\n    {\n        return \$this->{$realPropertyName};\n    }\n\n";

            $singularCollectionVar = '$' . $singularCollectionName;
            $code .= "    public function add{$methodBase}({$targetEntity} {$singularCollectionVar}): static\n    {\n";
            $code .= "        if (!\$this->{$realPropertyName}->contains({$singularCollectionVar})) {\n";
            $code .= "            \$this->{$realPropertyName}->add({$singularCollectionVar});\n";
            $code .= '            ' . $singularCollectionVar . '->set' . ucfirst($mappedByProperty) . "(\$this);\n";
            $code .= "        }\n\n";
            $code .= "        return \$this;\n    }\n\n";

            $code .= "    public function remove{$methodBase}({$targetEntity} {$singularCollectionVar}): static\n    {\n";
            $code .= "        if (\$this->{$realPropertyName}->removeElement({$singularCollectionVar})) {\n";
            $code .= "            if ({$singularCollectionVar}->get" . ucfirst($mappedByProperty) . "() === \$this) {\n";
            $code .= '                ' . $singularCollectionVar . '->set' . ucfirst($mappedByProperty) . "(null);\n";
            $code .= "            }\n";
            $code .= "        }\n\n";
            $code .= "        return \$this;\n    }\n\n";
        }

        // MÃ©thodes de collection pour ManyToMany
        foreach ($manyToManyPropertyMap as $entry) {
            $relation = $entry['relation'];
            $targetEntity = $entry['targetEntity'];
            $realPropertyName = $entry['propertyName'];

            $singularName = $this->singularize($realPropertyName);
            $methodBase   = ucfirst($singularName);
            $collectionMethodName = ucfirst($realPropertyName);
            $singularVar = '$' . $singularName;

            $code .= "    /**\n";
            $code .= "     * @return Collection<int, {$targetEntity}>\n";
            $code .= "     */\n";
            $code .= "    public function get{$collectionMethodName}(): Collection\n    {\n        return \$this->{$realPropertyName};\n    }\n\n";

            $code .= "    public function add{$methodBase}({$targetEntity} {$singularVar}): static\n    {\n";
            $code .= "        if (!\$this->{$realPropertyName}->contains({$singularVar})) {\n";
            $code .= "            \$this->{$realPropertyName}->add({$singularVar});\n";
            if (!$relation['isOwner']) {
                $code .= '            ' . $singularVar . '->add' . ucfirst(lcfirst($className)) . "(\$this);\n";
            }
            $code .= "        }\n\n";
            $code .= "        return \$this;\n    }\n\n";

            $code .= "    public function remove{$methodBase}({$targetEntity} {$singularVar}): static\n    {\n";
            $code .= "        if (\$this->{$realPropertyName}->removeElement({$singularVar})) {\n";
            if (!$relation['isOwner']) {
                $code .= '            ' . $singularVar . '->remove' . ucfirst(lcfirst($className)) . "(\$this);\n";
            }
            $code .= "        }\n\n";
            $code .= "        return \$this;\n    }\n\n";
        }

        $code .= "}\n";

        return $code;
    }

    /**
     * Extrait un nom sÃ©mantique depuis une colonne FK.
     * Ex: sender_id â†’ sender, author_id â†’ author
     */
    private function extractSemanticName(string $columnName, string $targetTable): string
    {
        $camelName = $this->snakeToCamel($columnName);

        if (str_starts_with($camelName, 'id')) {
            $camelName = lcfirst(substr($camelName, 2));
        }

        if (str_ends_with($camelName, 'Id')) {
            $camelName = substr($camelName, 0, -2);
        }

        if (!empty($camelName)) {
            return $camelName;
        }

        return lcfirst($this->singularize($this->snakeToCamel($targetTable)));
    }

    /**
     * GÃ©nÃ¨re un nom inversedBy pour les FK multiples vers une mÃªme table.
     * Ex: sender â†’ sentMessages, receiver â†’ receivedMessages
     */
    private function generateInversedByName(string $propertyName, string $entityName): string
    {
        $transforms = [
            'sender'   => 'sent',
            'receiver' => 'received',
            'author'   => 'authored',
            'creator'  => 'created',
            'owner'    => 'owned',
            'parent'   => 'child',
        ];

        foreach ($transforms as $key => $prefix) {
            if (str_contains(strtolower($propertyName), $key)) {
                return $prefix . ucfirst($this->pluralize($entityName));
            }
        }

        return $propertyName . ucfirst($this->pluralize($entityName));
    }

    /**
     * Extrait le nom de propriÃ©tÃ© depuis une colonne FK.
     * Ex: id_constat â†’ constat, idProprietaire â†’ proprietaire, chantier_id â†’ chantier
     */
    private function cleanRelationPropertyName(string $columnName, string $referencedTable): string
    {
        $camelName = $this->snakeToCamel($columnName);

        // PrÃ©fixe id : id_constat â†’ constat, idProprietaire â†’ proprietaire
        if (str_starts_with($camelName, 'id') && strlen($camelName) > 2) {
            return lcfirst(substr($camelName, 2));
        }

        // Suffixe Id : chantierId â†’ chantier
        if (str_ends_with($camelName, 'Id') && strlen($camelName) > 2) {
            return lcfirst(substr($camelName, 0, -2));
        }

        return lcfirst($this->singularize($this->snakeToCamel($referencedTable)));
    }

    private function singularize(string $word): string
    {
        $irregulars = [
            'People' => 'Person', 'people' => 'person',
            'Children' => 'Child', 'children' => 'child',
        ];

        if (isset($irregulars[$word])) {
            return $irregulars[$word];
        }

        if (str_ends_with($word, 's') && strlen($word) > 1) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    private function pluralize(string $word): string
    {
        $irregulars = [
            'person' => 'people', 'Person' => 'People',
            'child' => 'children', 'Child' => 'Children',
        ];

        if (isset($irregulars[$word])) {
            return $irregulars[$word];
        }

        if (str_ends_with($word, 's')) {
            return $word;
        }

        if (str_ends_with($word, 'y') && !in_array(substr($word, -2, 1), ['a', 'e', 'i', 'o', 'u'])) {
            return substr($word, 0, -1) . 'ies';
        }

        if (str_ends_with($word, 'ch') || str_ends_with($word, 'sh') ||
            str_ends_with($word, 'ss') || str_ends_with($word, 'x') || str_ends_with($word, 'z')) {
            return $word . 'es';
        }

        return $word . 's';
    }

    private function findInverseSide(string $currentTable, array $foreignKey, array $inverseRelations): ?string
    {
        foreach ($inverseRelations as $relation) {
            if ($relation['sourceTable'] === $currentTable &&
                $relation['columnName'] === $foreignKey['COLUMN_NAME']) {
                $targetEntity = $this->snakeToCamel($currentTable, true);
                return lcfirst($targetEntity) . 's';
            }
        }
        return null;
    }

    private function findPrimaryKey(string $tableName): string
    {
        return 'id' . $this->snakeToCamel($tableName, true);
    }

    private function generatePropertyCode(array $column, bool $isPrimaryKey, bool $isUnique = false, bool $isCompositePk = false): string
    {
        $phpType = $this->mapDoctrineTypeToPhp($column['DATA_TYPE']);
        $nullable = $column['IS_NULLABLE'] === 'YES';
        $attributes = [];

        if ($isPrimaryKey) {

            if ($phpType === 'bool') {
                $phpType = 'int';
            }
            $attributes[] = "    #[ORM\\Id]";

            if (!$isCompositePk && in_array($column['DATA_TYPE'], ['int', 'bigint', 'smallint', 'tinyint', 'mediumint'])) {
                $attributes[] = "    #[ORM\\GeneratedValue]";
            }
            $nullable = true;
        }

        $ormType = $this->mapToORMType($column['DATA_TYPE'], $column['COLUMN_TYPE']);

        if ($isPrimaryKey && $ormType === 'Types::BOOLEAN') {
            $ormType = 'Types::INTEGER';
        }
        $columnAttr = "    #[ORM\Column(name: \"{$column['COLUMN_NAME']}\", type: $ormType";

        if ($this->columnSupportsLength($column['DATA_TYPE'])) {
            $length = $this->resolveStringLength($column);
            if ($length !== null) {
                $columnAttr .= ", length: $length";
            }
        }

        if ($this->isDecimalColumn($column)) {
            $precision = $this->resolveNumericPrecision($column);
            $scale = $this->resolveNumericScale($column);
            if ($precision !== null) { $columnAttr .= ", precision: $precision"; }
            if ($scale !== null) { $columnAttr .= ", scale: $scale"; }
        }

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
        if ($nullable) { $code .= " = null"; }
        $code .= ";\n\n";

        return $code;
    }

    private function generateGetterSetterCode(array $column, bool $isPrimaryKey = false): string
    {
        $phpType = $this->mapDoctrineTypeToPhp($column['DATA_TYPE']);

        if ($isPrimaryKey && $phpType === 'bool') {
            $phpType = 'int';
        }
        $nullable = $column['IS_NULLABLE'] === 'YES';
        $propertyName = $this->snakeToCamel($column['COLUMN_NAME']);
        $methodName = ucfirst($propertyName);

        if ($isPrimaryKey) {
            $nullable = true;
        }

        $nullableType = $nullable ? '?' : '';
        $code  = "    public function get$methodName(): {$nullableType}$phpType\n    {\n";
        $code .= "        return \$this->$propertyName;\n    }\n\n";

        // Pas de setter pour les PK auto-gÃ©nÃ©rÃ©es
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
        return match (strtolower($doctrineType)) {
            'int', 'integer', 'bigint', 'smallint', 'mediumint'                          => 'int',
            'bool', 'boolean', 'tinyint'                                                  => 'bool',
            'varchar', 'char', 'string', 'text', 'longtext', 'mediumtext', 'enum', 'set' => 'string',
            'datetime', 'datetimetz', 'timestamp', 'date', 'time'                         => '\\DateTimeInterface',
            'datetime_immutable', 'datetimetz_immutable'                                  => '\\DateTimeImmutable',
            'date_immutable', 'time_immutable'                                            => '\\DateTimeInterface',
            'float', 'double', 'real'                                                     => 'float',
            'decimal', 'numeric'                                                          => 'string',
            'json'                                                                        => 'array',
            default                                                                       => 'string',
        };
    }

    private function mapToORMType(string $dataType, string $columnType): string
    {
        return match (strtolower($dataType)) {
            'int', 'integer', 'smallint', 'mediumint' => 'Types::INTEGER',
            'bigint'                                  => 'Types::BIGINT',
            'varchar', 'char', 'string'               => 'Types::STRING',
            'text', 'longtext', 'mediumtext'          => 'Types::TEXT',
            'enum', 'set'                             => 'Types::STRING',
            'datetime', 'timestamp'                   => 'Types::DATETIME_MUTABLE',
            'datetimetz'                              => 'Types::DATETIMETZ_MUTABLE',
            'datetime_immutable'                      => 'Types::DATETIME_IMMUTABLE',
            'datetimetz_immutable'                    => 'Types::DATETIMETZ_IMMUTABLE',
            'date'                                    => 'Types::DATE_MUTABLE',
            'date_immutable'                          => 'Types::DATE_IMMUTABLE',
            'time'                                    => 'Types::TIME_MUTABLE',
            'time_immutable'                          => 'Types::TIME_IMMUTABLE',
            'float', 'double', 'real'                 => 'Types::FLOAT',
            'decimal', 'numeric'                      => 'Types::DECIMAL',
            'bool', 'boolean', 'tinyint'              => 'Types::BOOLEAN',
            'json'                                    => 'Types::JSON',
            default                                   => 'Types::STRING',
        };
    }

    public function generateRepositoryCode(string $entityClass): string
    {
        return sprintf(self::REPOSITORY_TEMPLATE, $entityClass);
    }

    /**
     * DÃ©tecte la clÃ© primaire Ã  partir des conventions de nommage courantes.
     */
    public function detectPrimaryKey(string $tableName, array $columns): array
    {
        $patterns = [
            'id' . $tableName, 'id' . ucfirst($tableName),
            'id' . strtolower($tableName), 'id' . lcfirst($tableName),
            'id_' . strtolower($tableName), 'id',
            $tableName . 'Id', $tableName . '_id',
            strtolower($tableName) . '_id',
        ];

        foreach ($columns as $column) {
            $columnName = $column['COLUMN_NAME'];

            if (in_array($columnName, $patterns, true)) {
                return [$columnName];
            }

            $lowerColumn = strtolower($columnName);
            $lowerTable  = strtolower($tableName);
            if (str_contains($lowerColumn, 'id') && str_contains($lowerColumn, $lowerTable)) {
                return [$columnName];
            }
        }

        return [];
    }

    private function columnSupportsLength(string $dataType): bool
    {
        $type = strtolower($dataType);
        return str_contains($type, 'char') || $type === 'string' || $type === 'binary' || $type === 'varbinary';
    }

    private function resolveStringLength(array $column): ?int
    {
        if (isset($column['CHARACTER_MAXIMUM_LENGTH']) && $column['CHARACTER_MAXIMUM_LENGTH'] !== null) {
            return (int) $column['CHARACTER_MAXIMUM_LENGTH'];
        }

        if (!empty($column['COLUMN_TYPE']) && preg_match('/\((\d+)\)/', $column['COLUMN_TYPE'], $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function isDecimalColumn(array $column): bool
    {
        return in_array(strtolower($column['DATA_TYPE']), ['decimal', 'numeric'], true);
    }

    private function resolveNumericPrecision(array $column): ?int
    {
        if (isset($column['NUMERIC_PRECISION']) && $column['NUMERIC_PRECISION'] !== null) {
            return (int) $column['NUMERIC_PRECISION'];
        }
        [$precision] = $this->parseNumericMetaFromColumnType($column);
        return $precision;
    }

    private function resolveNumericScale(array $column): ?int
    {
        if (isset($column['NUMERIC_SCALE']) && $column['NUMERIC_SCALE'] !== null) {
            return (int) $column['NUMERIC_SCALE'];
        }
        [, $scale] = $this->parseNumericMetaFromColumnType($column);
        return $scale;
    }

    private function parseNumericMetaFromColumnType(array $column): array
    {
        if (empty($column['COLUMN_TYPE'])) {
            return [null, null];
        }

        if (preg_match('/\((\d+)(?:,(\d+))?\)/', $column['COLUMN_TYPE'], $matches)) {
            return [
                isset($matches[1]) ? (int) $matches[1] : null,
                isset($matches[2]) ? (int) $matches[2] : null,
            ];
        }

        return [null, null];
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
     * Retourne vrai si toutes les clÃ©s primaires sont aussi des clÃ©s Ã©trangÃ¨res (table d'association).
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
        return false;
    }

    public function generateEmbeddableCode(string $tableName, array $columns, array $primaryKeys): string
    {
        $className = $this->snakeToCamel($tableName, true) . 'Id';
        $code = "<?php\n\nnamespace App\\Entity;\n\nuse Doctrine\\ORM\\Mapping as ORM;\nuse Doctrine\\DBAL\\Types\\Types;\n\n#[ORM\\Embeddable]\nclass $className\n{\n";

        foreach ($primaryKeys as $pk) {
            $columnDefinition = $this->getColumnDefinition($columns, $pk);
            if (!$columnDefinition) { continue; }
            $code .= $this->generateEmbeddablePropertyCode($columnDefinition);
        }

        foreach ($primaryKeys as $pk) {
            $columnDefinition = $this->getColumnDefinition($columns, $pk);
            if (!$columnDefinition) { continue; }
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

        if ($this->columnSupportsLength($column['DATA_TYPE'])) {
            $length = $this->resolveStringLength($column);
            if ($length !== null) { $columnAttr .= ", length: $length"; }
        }

        if ($this->isDecimalColumn($column)) {
            $precision = $this->resolveNumericPrecision($column);
            $scale = $this->resolveNumericScale($column);
            if ($precision !== null) { $columnAttr .= ", precision: $precision"; }
            if ($scale !== null) { $columnAttr .= ", scale: $scale"; }
        }

        if ($nullable) { $columnAttr .= ", nullable: true"; }
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
