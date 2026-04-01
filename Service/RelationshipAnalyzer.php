<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Service;

class RelationshipAnalyzer
{
    private array $tableRelations = [];
    private array $manyToManyTables = [];
    private array $manyToManyRelationsPerTable = [];
    private array $relationPropertyRegistry = [];
    private array $manyToManyPairCounts = [];

    /**
     * @param array<string, array{foreignKeys: array, primaryKeys: array, columns: array}> $allTablesData
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
            $columns     = $data['columns'] ?? [];

            if ($this->isPureManyToManyTable($columns, $primaryKeys, $foreignKeys)) {
                $this->manyToManyTables[$tableName] = $foreignKeys;
            }

            foreach ($foreignKeys as $fk) {
                $referencedTable = $fk['REFERENCED_TABLE_NAME'];
                if (!isset($this->tableRelations[$referencedTable])) {
                    $this->tableRelations[$referencedTable] = [];
                }

                $uniqueKey = $tableName . '::' . $fk['COLUMN_NAME'] . '->' . $referencedTable;
                $exists = false;

                foreach ($this->tableRelations[$referencedTable] as $existingRel) {
                    if ($existingRel['sourceTable'] . '::' . $existingRel['columnName'] . '->' . $referencedTable === $uniqueKey) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $this->tableRelations[$referencedTable][] = [
                        'sourceTable'     => $tableName,
                        'columnName'      => $fk['COLUMN_NAME'],
                        'referencedColumn'=> $fk['REFERENCED_COLUMN_NAME'],
                        'isManyToMany'    => isset($this->manyToManyTables[$tableName]),
                        'foreignKey'      => $fk,
                    ];
                }
            }
        }

        $this->buildManyToManyRelations();
    }

    /**
     * Table d'association pure : exactement 2 FK constituant la PK composite, sans colonne métier.
     */
    private function isPureManyToManyTable(array $columns, array $primaryKeys, array $foreignKeys): bool
    {
        if (count($foreignKeys) !== 2) {
            return false;
        }

        $foreignKeyColumns = array_column($foreignKeys, 'COLUMN_NAME');
        sort($foreignKeyColumns);
        sort($primaryKeys);

        if ($foreignKeyColumns !== $primaryKeys) {
            return false;
        }

        $allowedColumns = array_merge($foreignKeyColumns, ['created_at', 'updated_at']);

        foreach ($columns as $column) {
            $columnName = $column['COLUMN_NAME'];
            $dataType   = strtolower($column['DATA_TYPE'] ?? '');

            if (in_array($columnName, $allowedColumns, true)) {
                continue;
            }

            if (in_array($dataType, ['timestamp', 'datetime']) &&
                (stripos($columnName, 'created') !== false || stripos($columnName, 'updated') !== false)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @return array Relations OneToMany pour la table donnée.
     */
    public function getInverseRelations(string $tableName): array
    {
        return array_filter(
            $this->tableRelations[$tableName] ?? [],
            fn($rel) => !$rel['isManyToMany']
        );
    }

    /**
     * @return array Relations ManyToMany pour la table donnée.
     */
    public function getManyToManyRelations(string $tableName): array
    {
        return $this->manyToManyRelationsPerTable[$tableName] ?? [];
    }

    public function isManyToManyTable(string $tableName): bool
    {
        return isset($this->manyToManyTables[$tableName]);
    }

    /** @return array<int, string> */
    public function getAllManyToManyTables(): array
    {
        return array_keys($this->manyToManyTables);
    }

    private function buildManyToManyRelations(): void
    {
        $this->manyToManyPairCounts = [];

        foreach ($this->manyToManyTables as $foreignKeys) {
            if (count($foreignKeys) !== 2) { continue; }
            $pairKey = $this->buildPairKey(
                $foreignKeys[0]['REFERENCED_TABLE_NAME'] ?? '',
                $foreignKeys[1]['REFERENCED_TABLE_NAME'] ?? ''
            );
            $this->manyToManyPairCounts[$pairKey] = ($this->manyToManyPairCounts[$pairKey] ?? 0) + 1;
        }

        foreach ($this->manyToManyTables as $joinTable => $foreignKeys) {
            if (count($foreignKeys) !== 2) { continue; }

            $fkA    = $foreignKeys[0];
            $fkB    = $foreignKeys[1];
            $tableA = $fkA['REFERENCED_TABLE_NAME'];
            $tableB = $fkB['REFERENCED_TABLE_NAME'];

            if (!$tableA || !$tableB) { continue; }

            $pairKey     = $this->buildPairKey($tableA, $tableB);
            $forceSuffix = ($this->manyToManyPairCounts[$pairKey] ?? 0) > 1;

            // Le propriétaire est déterminé par ordre alphabétique pour un résultat stable
            $ownerTable   = strcasecmp($tableA, $tableB) <= 0 ? $tableA : $tableB;
            $inverseTable = $ownerTable === $tableA ? $tableB : $tableA;
            $ownerFk      = $ownerTable === $tableA ? $fkA : $fkB;
            $inverseFk    = $ownerFk === $fkA ? $fkB : $fkA;

            $ownerProperty   = $this->generateRelationPropertyName($ownerTable, $inverseTable, $joinTable, $forceSuffix);
            $inverseProperty = $this->generateRelationPropertyName($inverseTable, $ownerTable, $joinTable, $forceSuffix);

            $this->manyToManyRelationsPerTable[$ownerTable][] = [
                'targetEntity'               => $inverseTable,
                'joinTable'                  => $joinTable,
                'joinColumn'                 => $ownerFk['COLUMN_NAME'],
                'inverseJoinColumn'          => $inverseFk['COLUMN_NAME'],
                'joinReferencedColumn'       => $ownerFk['REFERENCED_COLUMN_NAME'],
                'inverseJoinReferencedColumn'=> $inverseFk['REFERENCED_COLUMN_NAME'],
                'isOwner'                    => true,
                'propertyName'               => $ownerProperty,
                'mappedBy'                   => null,
                'inversedBy'                 => $inverseProperty,
            ];

            $this->manyToManyRelationsPerTable[$inverseTable][] = [
                'targetEntity'               => $ownerTable,
                'joinTable'                  => $joinTable,
                'joinColumn'                 => $inverseFk['COLUMN_NAME'],
                'inverseJoinColumn'          => $ownerFk['COLUMN_NAME'],
                'joinReferencedColumn'       => $inverseFk['REFERENCED_COLUMN_NAME'],
                'inverseJoinReferencedColumn'=> $ownerFk['REFERENCED_COLUMN_NAME'],
                'isOwner'                    => false,
                'propertyName'               => $inverseProperty,
                'mappedBy'                   => $ownerProperty,
                'inversedBy'                 => null,
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

        $suffix    = $this->snakeToCamel($joinTable, true);
        $candidate = $baseName . $suffix;
        $counter   = 2;

        while (in_array($candidate, $registry, true)) {
            $candidate = $baseName . $suffix . $counter++;
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
            'person' => 'people', 'Person' => 'People',
            'child'  => 'children', 'Child' => 'Children',
        ];

        if (isset($irregulars[$word])) { return $irregulars[$word]; }
        if (str_ends_with($word, 's')) { return $word; }

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

    /**
     * Rétrograde une table de jonction ManyToMany en entité standard.
     * Supprime les relations ManyToMany des entités parentes et reconstruit
     * les relations OneToMany correspondantes afin d'éviter le double mapping.
     */
    public function demoteManyToManyTable(string $tableName): void
    {
        if (!isset($this->manyToManyTables[$tableName])) {
            return;
        }

        $foreignKeys = $this->manyToManyTables[$tableName];

        unset($this->manyToManyTables[$tableName]);

        foreach ($foreignKeys as $fk) {
            $referencedTable = $fk['REFERENCED_TABLE_NAME'] ?? null;
            if ($referencedTable && isset($this->manyToManyRelationsPerTable[$referencedTable])) {
                $this->manyToManyRelationsPerTable[$referencedTable] = array_values(array_filter(
                    $this->manyToManyRelationsPerTable[$referencedTable],
                    fn($rel) => $rel['joinTable'] !== $tableName
                ));
            }
        }

        foreach ($foreignKeys as $fk) {
            $referencedTable = $fk['REFERENCED_TABLE_NAME'] ?? null;
            if (!$referencedTable) {
                continue;
            }

            if (!isset($this->tableRelations[$referencedTable])) {
                $this->tableRelations[$referencedTable] = [];
            }

            $uniqueKey = $tableName . '::' . $fk['COLUMN_NAME'] . '->' . $referencedTable;
            $found     = false;

            foreach ($this->tableRelations[$referencedTable] as &$existingRel) {
                if ($existingRel['sourceTable'] . '::' . $existingRel['columnName'] . '->' . $referencedTable === $uniqueKey) {
                    $existingRel['isManyToMany'] = false;
                    $found = true;
                    break;
                }
            }
            unset($existingRel);

            if (!$found) {
                $this->tableRelations[$referencedTable][] = [
                    'sourceTable'      => $tableName,
                    'columnName'       => $fk['COLUMN_NAME'],
                    'referencedColumn' => $fk['REFERENCED_COLUMN_NAME'],
                    'isManyToMany'     => false,
                    'foreignKey'       => $fk,
                ];
            }
        }
    }

    private function buildPairKey(string $tableA, string $tableB): string
    {
        $pair = [strtolower($tableA), strtolower($tableB)];
        sort($pair);

        return $pair[0] . '::' . $pair[1];
    }
}
