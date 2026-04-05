<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Service;

use App\Bundle\DbMapperBundle\Exception\SchemaSynchronizationException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

class SchemaSynchronizer
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /** @return array<int, string> */
    public function getPendingSql(): array
    {
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        if (empty($metadata)) {
            return [];
        }

        return (new SchemaTool($this->entityManager))->getUpdateSchemaSql($metadata, false);
    }

    /**
     * Applique les instructions SQL en attente et retourne celles exÃ©cutÃ©es.
     *
     * @return array<int, string>
     * @throws SchemaSynchronizationException
     */
    public function synchronize(): array
    {
        $sqlStatements = $this->getPendingSql();

        if (empty($sqlStatements)) {
            return [];
        }

        /** @var Connection $connection */
        $connection = $this->entityManager->getConnection();
        $isMysql    = $connection->getDatabasePlatform() instanceof MySQLPlatform;

        try {
            if ($isMysql) {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
            }

            foreach ($sqlStatements as $sql) {
                // InnoDB rejette DROP PRIMARY KEY (errno 44) lorsque la table contient des
                // contraintes FK non référencées par Doctrine (noms issus du dump SQL initial).
                // Toutes les FK sont supprimées avant l'opération, puis Doctrine les recrée.
                if ($isMysql && preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+DROP\s+PRIMARY\s+KEY/i', $sql, $m)) {
                    $this->dropAllForeignKeys($connection, $m[1]);
                }
                $connection->executeStatement($sql);
            }
        } catch (\Throwable $exception) {
            throw new SchemaSynchronizationException(
                sprintf('Failed to synchronize schema: %s', $exception->getMessage()),
                0,
                $exception
            );
        } finally {
            if ($isMysql) {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        return $sqlStatements;
    }

    /**
     * Supprime toutes les contraintes de clé étrangère d'une table, y compris celles
     * dont le nom diffère des contraintes générées par Doctrine.
     *
     * Les erreurs sont silencieusement ignorées (table inexistante, aucune FK, etc.)
     * afin de ne pas interrompre le flux de synchronisation.
     */
    private function dropAllForeignKeys(Connection $connection, string $tableName): void
    {
        try {
            $fks = $connection->createSchemaManager()->listTableForeignKeys($tableName);
            foreach ($fks as $fk) {
                $connection->executeStatement(
                    sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $tableName, $fk->getName())
                );
            }
        } catch (\Throwable) {
            // Ignoré : table absente ou dépourvue de contraintes FK.
        }
    }
}
