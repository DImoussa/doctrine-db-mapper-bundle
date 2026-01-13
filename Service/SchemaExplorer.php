<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;

/**
 * Service utilitaire pour explorer le schéma de la base de données (tables, colonnes...).
 */
class SchemaExplorer
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Retourne la liste des noms de table présents dans la base.
     *
     * @return string[]
     */
    public function getTables(): array
    {
        /** @var AbstractSchemaManager $schemaManager */
        $schemaManager = method_exists($this->connection, 'createSchemaManager')
            ? $this->connection->createSchemaManager()
            : $this->connection->getSchemaManager();

        $tables = [];
        foreach ($schemaManager->listTables() as $table) {
            $tables[] = $table->getName();
        }

        sort($tables);

        return $tables;
    }

    /**
     * Retourne la liste des colonnes pour une table donnée, sous forme simplifiée.
     *
     * Chaque entrée contient au minimum : name, doctrineType, nullable.
     *
     * @return array<int, array{name: string, doctrineType: string, nullable: bool}>
     */
    public function getColumns(string $tableName): array
    {
        /** @var AbstractSchemaManager $schemaManager */
        $schemaManager = method_exists($this->connection, 'createSchemaManager')
            ? $this->connection->createSchemaManager()
            : $this->connection->getSchemaManager();

        $columns = [];
        foreach ($schemaManager->listTableColumns($tableName) as $column) {
            $type = $column->getType();

            // Récupérer le nom du type de manière compatible avec différentes versions de Doctrine
            // Extraire le nom à partir du nom de la classe (ex: BigIntType -> bigint)
            $className = get_class($type);
            $shortName = substr($className, strrpos($className, '\\') + 1);
            $typeName = strtolower(str_replace('Type', '', $shortName));

            $columns[] = [
                'name' => $column->getName(),
                'doctrineType' => $typeName,
                'nullable' => !$column->getNotnull(),
            ];
        }

        return $columns;
    }
}

