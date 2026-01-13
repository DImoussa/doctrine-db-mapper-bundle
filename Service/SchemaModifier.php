<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;

/**
 * Service pour appliquer les modifications de schéma dans la base de données.
 */
class SchemaModifier
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DoctrineTypeRegistry $typeRegistry,
    ) {
    }

    /**
     * Applique les changements planifiés dans la base de données.
     *
     * @param array<int, array<string, mixed>> $changes
     * @return array{success: bool, executed: array<string>, errors: array<string>}
     */
    public function applyChanges(array $changes): array
    {
        $executed = [];
        $errors = [];

        foreach ($changes as $change) {
            try {
                if ($change['type'] === 'add_column') {
                    $sql = $this->generateAddColumnSQL(
                        $change['table'],
                        $change['name'],
                        $change['doctrineType'],
                        $change['nullable']
                    );

                    $this->connection->executeStatement($sql);
                    $executed[] = $sql;
                } elseif ($change['type'] === 'add_relation') {
                    $sqls = $this->generateRelationSQL($change['sourceTable'], $change['config']);
                    foreach ($sqls as $sql) {
                        $this->connection->executeStatement($sql);
                        $executed[] = $sql;
                    }
                }
            } catch (DBALException $e) {
                $errors[] = sprintf(
                    'Erreur lors de l\'exécution de "%s": %s',
                    $sql ?? 'commande inconnue',
                    $e->getMessage()
                );
            }
        }

        return [
            'success' => empty($errors),
            'executed' => $executed,
            'errors' => $errors,
        ];
    }

    /**
     * Génère le SQL pour ajouter une colonne.
     */
    private function generateAddColumnSQL(string $table, string $columnName, string $doctrineType, bool $nullable): string
    {
        $mysqlType = $this->mapDoctrineTypeToMySQLType($doctrineType);
        $nullableClause = $nullable ? 'NULL' : 'NOT NULL';

        return sprintf(
            'ALTER TABLE `%s` ADD COLUMN `%s` %s %s',
            $table,
            $columnName,
            $mysqlType,
            $nullableClause
        );
    }

    /**
     * Mappe les types Doctrine vers les types MySQL.
     */
    private function mapDoctrineTypeToMySQLType(string $doctrineType): string
    {
        return match (strtolower($doctrineType)) {
            'string' => 'VARCHAR(255)',
            'text' => 'TEXT',
            'integer' => 'INT',
            'smallint' => 'SMALLINT',
            'bigint' => 'BIGINT',
            'boolean' => 'TINYINT(1)',
            'datetime', 'datetime_immutable' => 'DATETIME',
            'datetimetz' => 'DATETIME',
            'date' => 'DATE',
            'time' => 'TIME',
            'float' => 'DOUBLE',
            'decimal' => 'DECIMAL(10, 2)',
            'json' => 'JSON',
            default => throw new \InvalidArgumentException(sprintf('Type Doctrine non supporté: %s', $doctrineType)),
        };
    }

    /**
     * Prévisualise les requêtes SQL qui seront exécutées sans les appliquer.
     *
     * @param array<int, array<string, mixed>> $changes
     * @return array<string>
     */
    public function previewSQL(array $changes): array
    {
        $sqlStatements = [];

        foreach ($changes as $change) {
            if ($change['type'] === 'add_column') {
                $sqlStatements[] = $this->generateAddColumnSQL(
                    $change['table'],
                    $change['name'],
                    $change['doctrineType'],
                    $change['nullable']
                );
            } elseif ($change['type'] === 'add_relation') {
                $sqls = $this->generateRelationSQL($change['sourceTable'], $change['config']);
                $sqlStatements = array_merge($sqlStatements, $sqls);
            }
        }

        return $sqlStatements;
    }

    /**
     * Génère le SQL pour créer une relation entre tables.
     *
     * @param array<string, mixed> $config
     * @return array<string>
     */
    private function generateRelationSQL(string $sourceTable, array $config): array
    {
        $sqls = [];
        $relationType = $config['relationType']; // many-to-one, one-to-many, etc.
        $targetTable = $config['targetTable'];

        if ($relationType === 'many-to-one') {
            // ManyToOne : ajouter une colonne FK dans la table source
            $fkColumnName = $config['fieldName'] . '_id';
            $nullable = $config['nullable'] ?? true;
            $onDelete = $this->normalizeOnDelete($config['onDelete'] ?? 'SET NULL');

            $sqls[] = sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` BIGINT %s',
                $sourceTable,
                $fkColumnName,
                $nullable ? 'NULL' : 'NOT NULL'
            );

            // Ajouter la contrainte de clé étrangère
            $sqls[] = sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `fk_%s_%s` FOREIGN KEY (`%s`) REFERENCES `%s`(`id`) ON DELETE %s',
                $sourceTable,
                $sourceTable,
                $fkColumnName,
                $fkColumnName,
                $targetTable,
                $onDelete
            );
        } elseif ($relationType === 'one-to-many') {
            // OneToMany : ajouter une colonne FK dans la table cible
            $fkColumnName = ($config['inversedBy'] ?? strtolower($sourceTable)) . '_id';
            $nullable = $config['nullable'] ?? true;
            $onDelete = $this->normalizeOnDelete($config['onDelete'] ?? 'CASCADE');

            $sqls[] = sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` BIGINT %s',
                $targetTable,
                $fkColumnName,
                $nullable ? 'NULL' : 'NOT NULL'
            );

            $sqls[] = sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `fk_%s_%s` FOREIGN KEY (`%s`) REFERENCES `%s`(`id`) ON DELETE %s',
                $targetTable,
                $targetTable,
                $fkColumnName,
                $fkColumnName,
                $sourceTable,
                $onDelete
            );
        } elseif ($relationType === 'many-to-many') {
            // ManyToMany : créer une table de jointure
            $joinTableName = $config['joinTable'] ?? $sourceTable . '_' . $targetTable;
            $sourceColumn = $config['joinColumn'] ?? $sourceTable . '_id';
            $targetColumn = $config['inverseJoinColumn'] ?? $targetTable . '_id';

            $sqls[] = sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (
                    `%s` BIGINT NOT NULL,
                    `%s` BIGINT NOT NULL,
                    PRIMARY KEY (`%s`, `%s`),
                    CONSTRAINT `fk_%s_%s` FOREIGN KEY (`%s`) REFERENCES `%s`(`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_%s_%s` FOREIGN KEY (`%s`) REFERENCES `%s`(`id`) ON DELETE CASCADE
                )',
                $joinTableName,
                $sourceColumn,
                $targetColumn,
                $sourceColumn,
                $targetColumn,
                $joinTableName,
                $sourceTable,
                $sourceColumn,
                $sourceTable,
                $joinTableName,
                $targetTable,
                $targetColumn,
                $targetTable
            );
        } elseif ($relationType === 'one-to-one') {
            // OneToOne : ajouter une colonne FK unique dans la table source
            $fkColumnName = $config['fieldName'] . '_id';
            $nullable = $config['nullable'] ?? true;
            $onDelete = $this->normalizeOnDelete($config['onDelete'] ?? 'SET NULL');

            $sqls[] = sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` BIGINT %s UNIQUE',
                $sourceTable,
                $fkColumnName,
                $nullable ? 'NULL' : 'NOT NULL'
            );

            $sqls[] = sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `fk_%s_%s` FOREIGN KEY (`%s`) REFERENCES `%s`(`id`) ON DELETE %s',
                $sourceTable,
                $sourceTable,
                $fkColumnName,
                $fkColumnName,
                $targetTable,
                $onDelete
            );
        }

        return $sqls;
    }

    /**
     * Normalise la valeur ON DELETE pour MySQL.
     */
    private function normalizeOnDelete(string $onDelete): string
    {
        return match (strtoupper($onDelete)) {
            'SET NULL' => 'SET NULL',
            'CASCADE' => 'CASCADE',
            'RESTRICT' => 'RESTRICT',
            'NO ACTION' => 'NO ACTION',
            default => 'RESTRICT',
        };
    }
}
