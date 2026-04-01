<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Service;

use App\Bundle\DbMapperBundle\Exception\SchemaExtractionException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;

class SchemaExtractor
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<int, string>
     * @throws SchemaExtractionException
     */
    public function getTables(): array
    {
        try {
            $result = $this->connection->fetchAllAssociative(
                "SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE()"
            );

            return array_column($result, 'TABLE_NAME');
        } catch (DBALException $e) {
            throw new SchemaExtractionException('Failed to retrieve tables: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws SchemaExtractionException
     */
    public function getTableColumns(string $table): array
    {
        if (empty($table)) {
            throw new SchemaExtractionException('Table name cannot be empty');
        }

        try {
            $result = $this->connection->fetchAllAssociative(
                "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_KEY, IS_NULLABLE, COLUMN_TYPE,
                        CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, EXTRA
                 FROM information_schema.columns
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );

            if (empty($result)) {
                throw new SchemaExtractionException(sprintf('Table "%s" not found or has no columns', $table));
            }

            return $result;
        } catch (DBALException $e) {
            throw new SchemaExtractionException(sprintf('Failed to retrieve columns for "%s": %s', $table, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return array<int, string>
     * @throws SchemaExtractionException
     */
    public function getPrimaryKeys(string $table): array
    {
        if (empty($table)) {
            throw new SchemaExtractionException('Table name cannot be empty');
        }

        try {
            $result = $this->connection->fetchAllAssociative(
                "SELECT COLUMN_NAME FROM information_schema.key_column_usage
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'
                 ORDER BY ORDINAL_POSITION",
                [$table]
            );
            return array_column($result, 'COLUMN_NAME');
        } catch (DBALException $e) {
            throw new SchemaExtractionException(sprintf('Failed to retrieve primary keys for "%s": %s', $table, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return array<int, array<string, string>>
     * @throws SchemaExtractionException
     */
    public function getForeignKeys(string $table): array
    {
        if (empty($table)) {
            throw new SchemaExtractionException('Table name cannot be empty');
        }

        try {
            $fks = $this->connection->fetchAllAssociative(
                "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                 FROM information_schema.key_column_usage
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL",
                [$table]
            );

            // Dédoublonnage (information_schema peut retourner des doublons)
            $uniqueFks = [];
            $seen      = [];
            foreach ($fks as $fk) {
                $key = $fk['COLUMN_NAME'] . '::' . $fk['REFERENCED_TABLE_NAME'] . '::' . $fk['REFERENCED_COLUMN_NAME'];
                if (!isset($seen[$key])) {
                    $uniqueFks[] = $fk;
                    $seen[$key]  = true;
                }
            }

            return $uniqueFks;
        } catch (DBALException $e) {
            throw new SchemaExtractionException(sprintf('Failed to retrieve foreign keys for "%s": %s', $table, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws SchemaExtractionException
     */
    public function getUniqueConstraints(string $table): array
    {
        if (empty($table)) {
            throw new SchemaExtractionException('Table name cannot be empty');
        }

        try {
            return $this->connection->fetchAllAssociative(
                "SELECT COLUMN_NAME, INDEX_NAME
                 FROM information_schema.statistics
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND INDEX_NAME != 'PRIMARY'",
                [$table]
            );
        } catch (DBALException $e) {
            throw new SchemaExtractionException(sprintf('Failed to retrieve unique constraints for "%s": %s', $table, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return array<string, array<string, mixed>> Indexé par nom de colonne.
     * @throws SchemaExtractionException
     */
    public function getIndexes(string $table): array
    {
        if (empty($table)) {
            throw new SchemaExtractionException('Table name cannot be empty');
        }

        try {
            $rows    = $this->connection->fetchAllAssociative(
                "SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
                 FROM information_schema.statistics
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME != 'PRIMARY'
                 ORDER BY SEQ_IN_INDEX",
                [$table]
            );
            $indexes = [];
            foreach ($rows as $row) {
                $indexes[$row['COLUMN_NAME']] = [
                    'INDEX_NAME' => $row['INDEX_NAME'],
                    'NON_UNIQUE' => $row['NON_UNIQUE'],
                ];
            }
            return $indexes;
        } catch (DBALException $e) {
            throw new SchemaExtractionException(sprintf('Failed to retrieve indexes for "%s": %s', $table, $e->getMessage()), 0, $e);
        }
    }
}
