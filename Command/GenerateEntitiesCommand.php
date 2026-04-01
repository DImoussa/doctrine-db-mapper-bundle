<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Command;

use App\Bundle\DbMapperBundle\Service\SchemaExtractor;
use App\Bundle\DbMapperBundle\Service\EntityGenerator;
use App\Bundle\DbMapperBundle\Service\RelationshipAnalyzer;
use App\Bundle\DbMapperBundle\Service\SchemaSynchronizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;


#[AsCommand(
    name: 'dbmapper:generate-entities',
    description: 'Génère des entités Doctrine automatiquement depuis la base MySQL avec relations bidirectionnelles'
)]
class GenerateEntitiesCommand extends Command
{
    private SchemaExtractor $schemaExtractor;
    private EntityGenerator $entityGenerator;
    private RelationshipAnalyzer $relationshipAnalyzer;
    private SchemaSynchronizer $schemaSynchronizer;
    private EntityManagerInterface $entityManager;
    private array $ignoredTables;

    public function __construct(
        SchemaExtractor $schemaExtractor,
        EntityGenerator $entityGenerator,
        RelationshipAnalyzer $relationshipAnalyzer,
        SchemaSynchronizer $schemaSynchronizer,
        EntityManagerInterface $entityManager,
        array $ignoredTables = ['messenger_messages']
    ) {
        $this->schemaExtractor = $schemaExtractor;
        $this->entityGenerator = $entityGenerator;
        $this->relationshipAnalyzer = $relationshipAnalyzer;
        $this->schemaSynchronizer = $schemaSynchronizer;
        $this->entityManager = $entityManager;
        $this->ignoredTables = array_map('strtolower', $ignoredTables);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('output-dir', InputArgument::REQUIRED, 'Répertoire de sortie pour les entités générées')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Écrase les entités et repositories existants')
            ->addOption('table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ne régénère que les tables spécifiées (ex: --table=users --table=posts)')
            ->addOption('schema-preview', null, InputOption::VALUE_NONE, 'Affiche le SQL que Doctrine exécuterait pour synchroniser la base avant la génération')
            ->addOption('schema-sync', null, InputOption::VALUE_NONE, 'Applique automatiquement le diff SQL Doctrine avant la génération');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputDir = $input->getArgument('output-dir');
        $force = (bool) $input->getOption('force');
        $targetTables = array_map('strtolower', $input->getOption('table') ?? []);
        $previewSchema = (bool) $input->getOption('schema-preview');
        $syncSchema = (bool) $input->getOption('schema-sync');
        $normalizedOutputDir = rtrim($outputDir, '/\\');
        $repositoryBaseDir = dirname($normalizedOutputDir) . DIRECTORY_SEPARATOR . 'Repository';

        if ($previewSchema || $syncSchema) {
            $this->handleSchemaSynchronization($output, $previewSchema, $syncSchema);
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $tables = $this->schemaExtractor->getTables();

        // Si --table est spécifié, forcer --force sur ces tables uniquement
        if (!empty($targetTables)) {
            $output->writeln('<comment>🎯 Mode ciblé : seules les tables [' . implode(', ', $input->getOption('table')) . '] seront régénérées.</comment>');
            $force = true;
        }

        // === PHASE 1: Collecter toutes les données des tables ===
        $output->writeln('<info>📊 Analyse du schéma de la base de données...</info>');
        $allTablesData = [];

        foreach ($tables as $table) {
            // Skip les tables système/framework configurées à ignorer
            if (in_array(strtolower($table), $this->ignoredTables, true)) {
                continue;
            }

            $columns = $this->schemaExtractor->getTableColumns($table);
            $primaryKeys = $this->schemaExtractor->getPrimaryKeys($table);
            $foreignKeys = $this->schemaExtractor->getForeignKeys($table);
            $uniqueConstraints = $this->schemaExtractor->getUniqueConstraints($table);
            $indexes = $this->schemaExtractor->getIndexes($table);

            if (empty($primaryKeys)) {
                $primaryKeys = $this->entityGenerator->detectPrimaryKey($table, $columns);
            }

            $allTablesData[$table] = [
                'columns' => $columns,
                'primaryKeys' => $primaryKeys,
                'foreignKeys' => $foreignKeys,
                'uniqueConstraints' => $uniqueConstraints,
                'indexes' => $indexes,
            ];
        }

        // === PHASE 2: Analyser les relations ===
        $output->writeln('<info>🔗 Analyse des relations entre tables...</info>');
        $this->relationshipAnalyzer->analyzeRelationships($allTablesData);

        $manyToManyTables = $this->relationshipAnalyzer->getAllManyToManyTables();
        if (!empty($manyToManyTables)) {
            $output->writeln('<comment>  → Tables d\'association ManyToMany détectées: ' . implode(', ', $manyToManyTables) . '</comment>');
        }

        // === PHASE 3: Générer les entités ===
        $output->writeln('<info>⚙️  Génération des entités et repositories...</info>');

        foreach ($tables as $table) {
            // Skip les tables système/framework configurées à ignorer
            if (in_array(strtolower($table), $this->ignoredTables, true)) {
                $output->writeln("<comment>⏭️  Table système ignorée: $table (configurée dans ignored_tables)</comment>");
                continue;
            }

            // Skip les tables non ciblées si --table est utilisé
            if (!empty($targetTables) && !in_array(strtolower($table), $targetTables, true)) {
                continue;
            }

            // Skip les tables d'association ManyToMany pures
            if ($this->relationshipAnalyzer->isManyToManyTable($table)) {
                $output->writeln("<comment>⏭️  Table d'association ignorée: $table (gérée comme ManyToMany)</comment>");
                continue;
            }

            $data = $allTablesData[$table];
            $columns = $data['columns'];
            $primaryKeys = $data['primaryKeys'];
            $foreignKeys = $data['foreignKeys'];

            if (empty($primaryKeys)) {
                $output->writeln("<comment>⚠️  Attention : La table '$table' n'a pas de clé primaire définie.</comment>");
            }

            $className = $this->entityGenerator->snakeToCamel($table, true);
            $entityPath = $normalizedOutputDir . DIRECTORY_SEPARATOR . $className . '.php';
            $repositoryPath = $repositoryBaseDir . DIRECTORY_SEPARATOR . $className . 'Repository.php';

            // Récupérer les relations pour cette table
            $inverseRelations = $this->relationshipAnalyzer->getInverseRelations($table);
            $manyToManyRelations = $this->relationshipAnalyzer->getManyToManyRelations($table);
            $uniqueConstraints = $data['uniqueConstraints'] ?? [];
            $indexes = $data['indexes'] ?? [];

            $entityExisted = file_exists($entityPath);
            if ($force || !$entityExisted) {
                $entityCode = $this->entityGenerator->generateEntityCode(
                    $table,
                    $columns,
                    $primaryKeys,
                    $foreignKeys,
                    $inverseRelations,
                    $manyToManyRelations,
                    $uniqueConstraints,
                    $indexes
                );
                file_put_contents($entityPath, $entityCode);

                $relationInfo = '';
                if (!empty($inverseRelations)) {
                    $relationInfo .= ' [' . count($inverseRelations) . ' OneToMany]';
                }
                if (!empty($manyToManyRelations)) {
                    $relationInfo .= ' [' . count($manyToManyRelations) . ' ManyToMany]';
                }

                $actionLabel = $force && $entityExisted ? '♻️ Entité régénérée' : '✅ Entité générée';
                $output->writeln("{$actionLabel} : $className" . $relationInfo);
            } else {
                $output->writeln("⏭️  Entité déjà existante : $className");
            }

            $repositoryExisted = file_exists($repositoryPath);
            if ($force || !$repositoryExisted) {
                if (!is_dir(dirname($repositoryPath))) {
                    mkdir(dirname($repositoryPath), 0777, true);
                }
                $repositoryCode = $this->entityGenerator->generateRepositoryCode($className);
                file_put_contents($repositoryPath, $repositoryCode);
                $repoAction = $force && $repositoryExisted ? '♻️ Repository régénéré' : '✅ Repository généré';
                $output->writeln("{$repoAction} : {$className}Repository");
            } else {
                $output->writeln("⏭️  Repository déjà existant : {$className}Repository");
            }
        }

        // === PHASE 4: Nettoyer le cache ===
        $output->writeln('<info>🧹 Nettoyage du cache Symfony...</info>');

        // Sur Windows, utiliser --no-warmup pour éviter les problèmes de verrouillage de fichiers
        $cacheProcess = new Process(['php', 'bin/console', 'cache:clear', '--no-warmup', '--env=dev']);
        $cacheProcess->setTimeout(30);
        $cacheProcess->run();

        if (!$cacheProcess->isSuccessful()) {
            // Tentative avec cache:pool:clear en cas d'échec
            $output->writeln('<comment>⚠️  Nettoyage standard échoué, tentative alternative...</comment>');

            $poolClearProcess = new Process(['php', 'bin/console', 'cache:pool:clear', 'cache.global_clearer', '--env=dev']);
            $poolClearProcess->setTimeout(10);
            $poolClearProcess->run();

            if ($poolClearProcess->isSuccessful()) {
                $output->writeln('<info>✅ Cache nettoyé (méthode alternative).</info>');
            } else {
                $output->writeln('<comment>⚠️  Le cache n\'a pas pu être nettoyé automatiquement.</comment>');
                $output->writeln('<comment>   Vous pouvez le faire manuellement avec : php bin/console cache:clear</comment>');
            }
        } else {
            $output->writeln('<info>✅ Cache Symfony nettoyé avec succès.</info>');
        }

        // === PHASE 5: Synchronisation post-génération ===
        // On utilise --dump-sql en sous-processus (pour charger les nouvelles entités),
        // puis on exécute le SQL via la connexion active.
        // Pour les ALTER TABLE DROP PRIMARY KEY (erreur MySQL 1553), on drop les FK
        // dépendantes → modifie la PK → recrée les FK.
        $output->writeln('<info>🔄 Synchronisation de la base de données avec le mapping généré...</info>');

        $dumpProcess = new Process(['php', 'bin/console', 'doctrine:schema:update', '--dump-sql', '--env=dev']);
        $dumpProcess->setTimeout(60);
        $dumpProcess->run();

        $sqlStatements = $this->parseSqlFromDumpOutput($dumpProcess->getOutput());

        if (empty($sqlStatements)) {
            $output->writeln('<info>✅ Base de données déjà synchronisée.</info>');
        } else {
            /** @var Connection $connection */
            $connection = $this->entityManager->getConnection();
            try {
                $this->executeSchemaStatements($connection, $sqlStatements);
                $output->writeln('<info>✅ Base de données synchronisée avec le mapping Doctrine.</info>');
            } catch (\Throwable $e) {
                $output->writeln('<comment>⚠️  La synchronisation automatique a échoué : ' . $e->getMessage() . '</comment>');
                $output->writeln('<comment>   Vous pouvez synchroniser manuellement avec : php bin/console doctrine:schema:update --force</comment>');
            }
        }

        $output->writeln('<info>✨ Génération terminée avec succès !</info>');
        return Command::SUCCESS;
    }

    /**
     * Exécute les instructions SQL avec gestion des ALTER TABLE DROP PRIMARY KEY.
     * MySQL bloque cette opération (erreur 1553) si des FK référencent la PK.
     *
     * @param array<int, string> $sqlStatements
     */
    private function executeSchemaStatements(Connection $connection, array $sqlStatements): void
    {
        foreach ($sqlStatements as $sql) {
            if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?.*DROP\s+PRIMARY\s+KEY/i', $sql, $matches)) {
                $this->executeAlterPkSafely($connection, $matches[1], $sql);
            } else {
                $connection->executeStatement($sql);
            }
        }
    }

    /**
     * Modifie une PRIMARY KEY en gérant les FK dépendantes (erreur MySQL 1553).
     * Stratégie : drop FK → modifier PK → recréer FK.
     */
    private function executeAlterPkSafely(Connection $connection, string $tableName, string $alterSql): void
    {
        $database = $connection->getDatabase();

        // --- Cas 1 : FK définies SUR la table (elles utilisent la PK comme index) ---
        $ownRows = $connection->fetchAllAssociative(
            "SELECT
                kcu.CONSTRAINT_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                kcu.ORDINAL_POSITION,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
             INNER JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                 ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                 AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             WHERE kcu.TABLE_SCHEMA = :db
                 AND kcu.TABLE_NAME = :table
             ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION",
            ['db' => $database, 'table' => $tableName]
        );

        $ownFks = [];
        foreach ($ownRows as $row) {
            $key = $row['CONSTRAINT_NAME'];
            if (!isset($ownFks[$key])) {
                $ownFks[$key] = [
                    'constraint' => $row['CONSTRAINT_NAME'],
                    'columns'    => [],
                    'refTable'   => $row['REFERENCED_TABLE_NAME'],
                    'refColumns' => [],
                    'updateRule' => $row['UPDATE_RULE'],
                    'deleteRule' => $row['DELETE_RULE'],
                ];
            }
            $ownFks[$key]['columns'][]    = $row['COLUMN_NAME'];
            $ownFks[$key]['refColumns'][] = $row['REFERENCED_COLUMN_NAME'];
        }

        // --- Cas 2 : FK d'autres tables qui référencent cette table ---
        $refRows = $connection->fetchAllAssociative(
            "SELECT
                kcu.TABLE_NAME AS CHILD_TABLE,
                kcu.CONSTRAINT_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                kcu.ORDINAL_POSITION,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
             INNER JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                 ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                 AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             WHERE kcu.REFERENCED_TABLE_SCHEMA = :db
                 AND kcu.REFERENCED_TABLE_NAME = :table
             ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION",
            ['db' => $database, 'table' => $tableName]
        );

        $refFks = [];
        foreach ($refRows as $row) {
            $key = $row['CHILD_TABLE'] . '.' . $row['CONSTRAINT_NAME'];
            if (!isset($refFks[$key])) {
                $refFks[$key] = [
                    'childTable' => $row['CHILD_TABLE'],
                    'constraint' => $row['CONSTRAINT_NAME'],
                    'columns'    => [],
                    'refColumns' => [],
                    'updateRule' => $row['UPDATE_RULE'],
                    'deleteRule' => $row['DELETE_RULE'],
                ];
            }
            $refFks[$key]['columns'][]    = $row['COLUMN_NAME'];
            $refFks[$key]['refColumns'][] = $row['REFERENCED_COLUMN_NAME'];
        }

        // 1. Supprimer les FK définies SUR cette table
        foreach ($ownFks as $fk) {
            $connection->executeStatement(
                sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $tableName, $fk['constraint'])
            );
        }

        // 2. Supprimer les FK des tables enfants qui référencent cette table
        foreach ($refFks as $fk) {
            $connection->executeStatement(
                sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $fk['childTable'], $fk['constraint'])
            );
        }

        // 3. Modifier la PRIMARY KEY
        $connection->executeStatement($alterSql);

        // 4. Recréer les FK définies SUR cette table
        foreach ($ownFks as $fk) {
            $cols    = '`' . implode('`, `', $fk['columns']) . '`';
            $refCols = '`' . implode('`, `', $fk['refColumns']) . '`';
            $connection->executeStatement(
                sprintf(
                    'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (%s) REFERENCES `%s` (%s) ON DELETE %s ON UPDATE %s',
                    $tableName,
                    $fk['constraint'],
                    $cols,
                    $fk['refTable'],
                    $refCols,
                    $fk['deleteRule'],
                    $fk['updateRule']
                )
            );
        }

        // 5. Recréer les FK des tables enfants
        foreach ($refFks as $fk) {
            $cols    = '`' . implode('`, `', $fk['columns']) . '`';
            $refCols = '`' . implode('`, `', $fk['refColumns']) . '`';
            $connection->executeStatement(
                sprintf(
                    'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (%s) REFERENCES `%s` (%s) ON DELETE %s ON UPDATE %s',
                    $fk['childTable'],
                    $fk['constraint'],
                    $cols,
                    $tableName,
                    $refCols,
                    $fk['deleteRule'],
                    $fk['updateRule']
                )
            );
        }
    }

    /**
     * Parse les instructions SQL depuis la sortie de "doctrine:schema:update --dump-sql".
     *
     * @return array<int, string>
     */
    private function parseSqlFromDumpOutput(string $output): array
    {
        $sqlStatements = [];
        $buffer        = '';
        $inStatement   = false;

        foreach (explode("\n", $output) as $line) {
            $trimmed = trim($line);

            if (empty($trimmed)) {
                continue;
            }

            if (!$inStatement && preg_match('/^(ALTER|CREATE|DROP|UPDATE|INSERT|DELETE|RENAME|TRUNCATE)\b/i', $trimmed)) {
                $inStatement = true;
                $buffer      = $trimmed;
            } elseif ($inStatement) {
                $buffer .= ' ' . $trimmed;
            }

            if ($inStatement && str_ends_with(rtrim($trimmed), ';')) {
                $sql = rtrim(trim($buffer), ';');
                if (!empty($sql)) {
                    $sqlStatements[] = $sql;
                }
                $buffer      = '';
                $inStatement = false;
            }
        }

        if ($inStatement && !empty(trim($buffer))) {
            $sqlStatements[] = rtrim(trim($buffer), ';');
        }

        return array_values(array_filter($sqlStatements));
    }

    private function handleSchemaSynchronization(OutputInterface $output, bool $preview, bool $synchronize): void
    {
        $pendingSql = $this->schemaSynchronizer->getPendingSql();

        if (empty($pendingSql)) {
            $output->writeln('<info>✅ Aucun diff Doctrine/BDD détecté avant la génération.</info>');

            return;
        }

        $output->writeln(sprintf('<comment>⚠️  %d requêtes SQL sont nécessaires pour aligner la base avec le mapping Doctrine.</comment>', count($pendingSql)));

        if ($preview) {
            foreach ($pendingSql as $sql) {
                $output->writeln('  • ' . $sql);
            }
        }

        if ($synchronize) {
            $output->writeln('<info>🔧 Application des corrections SQL avant la génération...</info>');
            $this->schemaSynchronizer->synchronize();
            $output->writeln('<info>✅ Synchronisation Doctrine/BDD effectuée.</info>');

            return;
        }

        $output->writeln('<comment>   Astuce: ajoutez --schema-sync pour appliquer automatiquement ces modifications.</comment>');
    }
}
