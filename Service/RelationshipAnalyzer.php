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

    /**
     * Analyse toutes les relations de la base de données
     *
     * @param array $allTablesData Format: ['tableName' => ['foreignKeys' => [...], 'primaryKeys' => [...], 'columns' => [...]]]
     */
    public function analyzeRelationships(array $allTablesData): void
    {
        $this->tableRelations = [];
        $this->manyToManyTables = [];

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
        $relations = [];

        foreach ($this->manyToManyTables as $joinTable => $foreignKeys) {
            $referencedTables = array_column($foreignKeys, 'REFERENCED_TABLE_NAME');

            if (in_array($tableName, $referencedTables, true)) {
                // Cette table participe à une relation ManyToMany via $joinTable
                $otherTable = array_values(array_diff($referencedTables, [$tableName]))[0] ?? null;

                if ($otherTable) {
                    // Trouver les colonnes de jointure et leurs références
                    $ownColumn = null;
                    $inverseColumn = null;
                    $ownReferencedColumn = null;
                    $inverseReferencedColumn = null;

                    foreach ($foreignKeys as $fk) {
                        if ($fk['REFERENCED_TABLE_NAME'] === $tableName) {
                            $ownColumn = $fk['COLUMN_NAME'];
                            $ownReferencedColumn = $fk['REFERENCED_COLUMN_NAME'];
                        } else {
                            $inverseColumn = $fk['COLUMN_NAME'];
                            $inverseReferencedColumn = $fk['REFERENCED_COLUMN_NAME'];
                        }
                    }

                    // Déterminer le côté propriétaire (owning side) par ordre alphabétique
                    // Le côté propriétaire est celui dont le nom de table vient en premier alphabétiquement
                    $isOwner = strcasecmp($tableName, $otherTable) < 0;

                    $relations[] = [
                        'targetEntity' => $otherTable,
                        'joinTable' => $joinTable,
                        'joinColumn' => $ownColumn,
                        'inverseJoinColumn' => $inverseColumn,
                        'joinReferencedColumn' => $ownReferencedColumn,
                        'inverseJoinReferencedColumn' => $inverseReferencedColumn,
                        'isOwner' => $isOwner,
                    ];
                }
            }
        }

        return $relations;
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
}

