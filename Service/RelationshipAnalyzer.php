<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Service;

/**
 * Service d'analyse des relations entre tables pour la génération des OneToMany et ManyToMany
 *
 * @author Diallo Moussa <moussadou128@gmail.com>
 */
class RelationshipAnalyzer
{
    private array $tableRelations = [];
    private array $manyToManyTables = [];
    private array $manyToManyRelationsPerTable = [];
    private array $relationPropertyRegistry = [];
    private array $manyToManyPairCounts = [];

    /**
     * Analyse toutes les relations de la base de données
     *
     * @param array $allTablesData Format: ['tableName' => ['foreignKeys' => [...], 'primaryKeys' => [...], 'columns' => [...]]]
     */
    public function analyzeRelationships(array $allTablesData): void
    {
        $this->tableRelations = [];
        $this->manyToManyTables = [];
        $this->manyToManyRelationsPerTable = [];
        $this->relationPropertyRegistry = [];
        $this->manyToManyPairCounts = [];

        foreach ($allTablesData as $tableName => $data) {
            $foreignKeys = $data['foreignKeys'] ?? [];
            $primaryKeys = $data['primaryKeys'] ?? [];
            $columns = $data['columns'] ?? [];

            // Détecter les tables ManyToMany (tables d'association pures)
            if ($this->isPureManyToManyTable($columns, $primaryKeys, $foreignKeys)) {
                $this->manyToManyTables[$tableName] = $foreignKeys;
            }

            // Indexer les relations inverses
            foreach ($foreignKeys as $fk) {
                $referencedTable = $fk['REFERENCED_TABLE_NAME'];
                if (!isset($this->tableRelations[$referencedTable])) {
                    $this->tableRelations[$referencedTable] = [];
                }

                // Créer une clé unique pour éviter les doublons
                $uniqueKey = $tableName . '::' . $fk['COLUMN_NAME'] . '->' . $referencedTable;

                // Vérifier si cette relation n'existe pas déjà
                $exists = false;
                foreach ($this->tableRelations[$referencedTable] as $existingRel) {
                    $existingKey = $existingRel['sourceTable'] . '::' . $existingRel['columnName'] . '->' . $referencedTable;
                    if ($existingKey === $uniqueKey) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $this->tableRelations[$referencedTable][] = [
                        'sourceTable' => $tableName,
                        'columnName' => $fk['COLUMN_NAME'],
                        'referencedColumn' => $fk['REFERENCED_COLUMN_NAME'],
                        'isManyToMany' => isset($this->manyToManyTables[$tableName]),
                        'foreignKey' => $fk
                    ];
                }
            }
        }

        $this->buildManyToManyRelations();
    }

    /**
     * Vérifie si une table est une vraie table d'association ManyToMany
     * Critères : exactement 2 FK, les 2 FK = PK composite, pas d'autres colonnes métier significatives
     */
    private function isPureManyToManyTable(array $columns, array $primaryKeys, array $foreignKeys): bool
    {
        // Doit avoir exactement 2 clés étrangères
        if (count($foreignKeys) !== 2) {
            return false;
        }

        // Les FK doivent constituer la clé primaire composite
        $foreignKeyColumns = array_column($foreignKeys, 'COLUMN_NAME');
        sort($foreignKeyColumns);
        sort($primaryKeys);

        if ($foreignKeyColumns !== $primaryKeys) {
            return false;
        }

        // Vérifier qu'il n'y a pas de colonnes métier significatives
        // On tolère : les 2 FK + created_at, updated_at, et les colonnes de type timestamp
        $allowedColumns = array_merge($foreignKeyColumns, ['created_at', 'updated_at']);
        $significantColumnsCount = 0;

        foreach ($columns as $column) {
            $columnName = $column['COLUMN_NAME'];
            $dataType = strtolower($column['DATA_TYPE'] ?? '');

            // Ignorer les colonnes autorisées
            if (in_array($columnName, $allowedColumns, true)) {
                continue;
            }

            // Ignorer les colonnes de timestamp/datetime automatiques
            if (in_array($dataType, ['timestamp', 'datetime']) &&
                (stripos($columnName, 'created') !== false || stripos($columnName, 'updated') !== false)) {
                continue;
            }

            // Si on arrive ici, c'est une colonne métier significative
            $significantColumnsCount++;
        }

        // C'est une table d'association pure si elle n'a pas de colonnes métier significatives
        return $significantColumnsCount === 0;
    }

    /**
     * Récupère les relations inverses OneToMany d'une table
     * @return array
     */
    public function getInverseRelations(string $tableName): array
    {
        $relations = $this->tableRelations[$tableName] ?? [];

        // Filtrer pour ne garder que les relations OneToMany (exclure ManyToMany)
        return array_filter(
            $relations,
            fn($rel) => !$rel['isManyToMany']
        );
    }

    /**
     * Récupère les relations ManyToMany d'une table
     * @return array
     */
    public function getManyToManyRelations(string $tableName): array
    {
        return $this->manyToManyRelationsPerTable[$tableName] ?? [];
    }

    /**
     * Vérifie si une table est une table d'association ManyToMany pure
     */
    public function isManyToManyTable(string $tableName): bool
    {
        return isset($this->manyToManyTables[$tableName]);
    }

    /**
     * Retourne toutes les tables d'association ManyToMany
     * @return array
     */
    public function getAllManyToManyTables(): array
    {
        return array_keys($this->manyToManyTables);
    }

    private function buildManyToManyRelations(): void
    {
        $this->manyToManyPairCounts = [];

        foreach ($this->manyToManyTables as $foreignKeys) {
            if (count($foreignKeys) !== 2) {
                continue;
            }

            $pairKey = $this->buildPairKey(
                $foreignKeys[0]['REFERENCED_TABLE_NAME'] ?? '',
                $foreignKeys[1]['REFERENCED_TABLE_NAME'] ?? ''
            );

            $this->manyToManyPairCounts[$pairKey] = ($this->manyToManyPairCounts[$pairKey] ?? 0) + 1;
        }

        foreach ($this->manyToManyTables as $joinTable => $foreignKeys) {
            if (count($foreignKeys) !== 2) {
                continue;
            }

            $fkA = $foreignKeys[0];
            $fkB = $foreignKeys[1];
            $tableA = $fkA['REFERENCED_TABLE_NAME'];
            $tableB = $fkB['REFERENCED_TABLE_NAME'];

            if (!$tableA || !$tableB) {
                continue;
            }

            $pairKey = $this->buildPairKey($tableA, $tableB);
            $forceSuffix = ($this->manyToManyPairCounts[$pairKey] ?? 0) > 1;

            // Déterminer le côté propriétaire (ordre alphabétique stable)
            $ownerTable = strcasecmp($tableA, $tableB) <= 0 ? $tableA : $tableB;
            $inverseTable = $ownerTable === $tableA ? $tableB : $tableA;
            $ownerFk = $ownerTable === $tableA ? $fkA : $fkB;
            $inverseFk = $ownerFk === $fkA ? $fkB : $fkA;

            $ownerProperty = $this->generateRelationPropertyName($ownerTable, $inverseTable, $joinTable, $forceSuffix);
            $inverseProperty = $this->generateRelationPropertyName($inverseTable, $ownerTable, $joinTable, $forceSuffix);

            $this->manyToManyRelationsPerTable[$ownerTable][] = [
                'targetEntity' => $inverseTable,
                'joinTable' => $joinTable,
                'joinColumn' => $ownerFk['COLUMN_NAME'],
                'inverseJoinColumn' => $inverseFk['COLUMN_NAME'],
                'joinReferencedColumn' => $ownerFk['REFERENCED_COLUMN_NAME'],
                'inverseJoinReferencedColumn' => $inverseFk['REFERENCED_COLUMN_NAME'],
                'isOwner' => true,
                'propertyName' => $ownerProperty,
                'mappedBy' => null,
                'inversedBy' => $inverseProperty,
            ];

            $this->manyToManyRelationsPerTable[$inverseTable][] = [
                'targetEntity' => $ownerTable,
                'joinTable' => $joinTable,
                'joinColumn' => $inverseFk['COLUMN_NAME'],
                'inverseJoinColumn' => $ownerFk['COLUMN_NAME'],
                'joinReferencedColumn' => $inverseFk['REFERENCED_COLUMN_NAME'],
                'inverseJoinReferencedColumn' => $ownerFk['REFERENCED_COLUMN_NAME'],
                'isOwner' => false,
                'propertyName' => $inverseProperty,
                'mappedBy' => $ownerProperty,
                'inversedBy' => null,
            ];
        }
    }

    private function generateRelationPropertyName(string $sourceTable, string $targetTable, string $joinTable, bool $forceSuffix = false): string
    {
        $baseName = $this->pluralize(lcfirst($this->snakeToCamel($targetTable)));
        $registry = $this->relationPropertyRegistry[$sourceTable] ?? [];

        if (!$forceSuffix && !in_array($baseName, $registry, true)) {
            $this->relationPropertyRegistry[$sourceTable][] = $baseName;
            return $baseName;
        }

        $suffix = $this->snakeToCamel($joinTable, true);
        $candidate = $baseName . $suffix;
        $counter = 2;

        while (in_array($candidate, $registry, true)) {
            $candidate = $baseName . $suffix . $counter;
            $counter++;
        }

        $this->relationPropertyRegistry[$sourceTable][] = $candidate;

        return $candidate;
    }

    private function snakeToCamel(string $value, bool $capitalizeFirst = false): string
    {
        $result = str_replace('_', '', ucwords($value, '_'));

        return $capitalizeFirst ? $result : lcfirst($result);
    }

    private function pluralize(string $word): string
    {
        $irregulars = [
            'person' => 'people',
            'Person' => 'People',
            'child' => 'children',
            'Child' => 'Children',
        ];

        if (isset($irregulars[$word])) {
            return $irregulars[$word];
        }

        if (str_ends_with($word, 's')) {
            return $word;
        }

        $penultimate = strlen($word) > 1 ? substr($word, -2, 1) : '';

        if (str_ends_with($word, 'y') && !in_array($penultimate, ['a', 'e', 'i', 'o', 'u'], true)) {
            return substr($word, 0, -1) . 'ies';
        }

        if (str_ends_with($word, 'ch') || str_ends_with($word, 'sh') ||
            str_ends_with($word, 'ss') || str_ends_with($word, 'x') || str_ends_with($word, 'z')) {
            return $word . 'es';
        }

        return $word . 's';
    }

    private function buildPairKey(string $tableA, string $tableB): string
    {
        $pair = [strtolower($tableA), strtolower($tableB)];
        sort($pair);

        return $pair[0] . '::' . $pair[1];
    }
}
