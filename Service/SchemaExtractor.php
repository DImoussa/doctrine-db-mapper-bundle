<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Service;

use App\Bundle\DbMapperBundle\Exception\SchemaExtractionException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;

/**
 * Service for extracting database schema information.
 *
 * This service provides methods to retrieve tables, columns, primary keys,
 * and foreign keys from a MySQL database using Doctrine DBAL.
 *
 * @author Diallo Moussa <moussadou128@gmail.com>
 */
class SchemaExtractor
{
    private Connection $connection;

    /**
     * @param Connection $connection Doctrine DBAL connection to the database
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Retrieves all table names from the current database.
     *
     * @return array<int, string> Array of table names
     *
     * @throws SchemaExtractionException If database query fails
     */
    public function getTables(): array
    {
        try {
            $sql = "SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE()";
            $result = $this->connection->fetchAllAssociative($sql);

            return array_column($result, 'TABLE_NAME');
        } catch (DBALException $e) {
            throw new SchemaExtractionException(
                sprintf('Failed to retrieve tables from database: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Retrieves all columns for a given table.
     *
     * @param string $table The table name
     *
     * @return array<int, array<string, mixed>> Array of column definitions with keys:
     *                                           - COLUMN_NAME: string
     *                                           - DATA_TYPE: string
     *                                           - COLUMN_KEY: string
     *                                           - IS_NULLABLE: string ('YES' or 'NO')
     *                                           - COLUMN_TYPE: string
     *
     * @throws SchemaExtractionException If table doesn't exist or query fails
     */
    public function getTableColumns(string $table): array
    {
        if (empty($table)) {
            throw new SchemaExtractionException('Table name cannot be empty');
        }

        try {
            $sql = "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_KEY, IS_NULLABLE, COLUMN_TYPE
                    FROM information_schema.columns
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
            $result = $this->connection->fetchAllAssociative($sql, [$table]);

            if (empty($result)) {
                throw new SchemaExtractionException(
                    sprintf('Table "%s" not found or has no columns', $table)
                );
            }

            return $result;
        } catch (DBALException $e) {
            throw new SchemaExtractionException(
                sprintf('Failed to retrieve columns for table "%s": %s', $table, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Retrieves primary key column names for a given table.
     *
     * @param string $table The table name
     *
     * @return array<int, string> Array of primary key column names
     *
     * @throws SchemaExtractionException If query fails
     */
    public function getPrimaryKeys(string $table): array
    {
        if (empty($table)) {
            throw new SchemaExtractionException('Table name cannot be empty');
        }

        try {
            $sql = "SELECT COLUMN_NAME FROM information_schema.key_column_usage
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = ?
                      AND CONSTRAINT_NAME = 'PRIMARY'";
            $result = $this->connection->fetchAllAssociative($sql, [$table]);

            return array_column($result, 'COLUMN_NAME');
        } catch (DBALException $e) {
            throw new SchemaExtractionException(
                sprintf('Failed to retrieve primary keys for table "%s": %s', $table, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Retrieves foreign key definitions for a given table.
     *
     * This method deduplicates foreign keys to avoid processing the same
     * foreign key multiple times (which can happen in some database setups).
     *
     * @param string $table The table name
     *
     * @return array<int, array<string, string>> Array of foreign key definitions with keys:
     *                                            - COLUMN_NAME: string
     *                                            - REFERENCED_TABLE_NAME: string
     *                                            - REFERENCED_COLUMN_NAME: string
     *
     * @throws SchemaExtractionException If query fails
     */
    public function getForeignKeys(string $table): array
    {
        if (empty($table)) {
            throw new SchemaExtractionException('Table name cannot be empty');
        }

        try {
            $sql = "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                    FROM information_schema.key_column_usage
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = ?
                      AND REFERENCED_TABLE_NAME IS NOT NULL";
            $fks = $this->connection->fetchAllAssociative($sql, [$table]);

            // Deduplicate foreign keys (avoid duplicates from information_schema)
            $uniqueFks = [];
            $seen = [];
            foreach ($fks as $fk) {
                $key = $fk['COLUMN_NAME'] . '::' . $fk['REFERENCED_TABLE_NAME'] . '::' . $fk['REFERENCED_COLUMN_NAME'];
                if (!isset($seen[$key])) {
                    $uniqueFks[] = $fk;
                    $seen[$key] = true;
                }
            }

            return $uniqueFks;
        } catch (DBALException $e) {
            throw new SchemaExtractionException(
                sprintf('Failed to retrieve foreign keys for table "%s": %s', $table, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Retrieves unique constraints for a given table.
     *
     * @param string $table The table name
     *
     * @return array<int, array<string, mixed>> Array of unique constraints with keys:
     *                                           - COLUMN_NAME: string
     *                                           - INDEX_NAME: string
     *
     * @throws SchemaExtractionException If query fails
     */
    public function getUniqueConstraints(string $table): array
    {
        if (empty($table)) {
            throw new SchemaExtractionException('Table name cannot be empty');
        }

        try {
            $sql = "SELECT COLUMN_NAME, INDEX_NAME
                    FROM information_schema.statistics
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = ?
                      AND NON_UNIQUE = 0
                      AND INDEX_NAME != 'PRIMARY'";
            return $this->connection->fetchAllAssociative($sql, [$table]);
        } catch (DBALException $e) {
            throw new SchemaExtractionException(
                sprintf('Failed to retrieve unique constraints for table "%s": %s', $table, $e->getMessage()),
                0,
                $e
            );
        }
    }
}
