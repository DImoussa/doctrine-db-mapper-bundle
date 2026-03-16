<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Command;

use App\Bundle\DbMapperBundle\Service\SchemaExtractor;
use App\Bundle\DbMapperBundle\Service\EntityGenerator;
use App\Bundle\DbMapperBundle\Service\RelationshipAnalyzer;
use App\Bundle\DbMapperBundle\Service\SchemaSynchronizer;
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

    public function __construct(
        SchemaExtractor $schemaExtractor,
        EntityGenerator $entityGenerator,
        RelationshipAnalyzer $relationshipAnalyzer,
        SchemaSynchronizer $schemaSynchronizer
    ) {
        $this->schemaExtractor = $schemaExtractor;
        $this->entityGenerator = $entityGenerator;
        $this->relationshipAnalyzer = $relationshipAnalyzer;
        $this->schemaSynchronizer = $schemaSynchronizer;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('output-dir', InputArgument::REQUIRED, 'Répertoire de sortie pour les entités générées')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Écrase les entités et repositories existants')
            ->addOption('schema-preview', null, InputOption::VALUE_NONE, 'Affiche le SQL que Doctrine exécuterait pour synchroniser la base avant la génération')
            ->addOption('schema-sync', null, InputOption::VALUE_NONE, 'Applique automatiquement le diff SQL Doctrine avant la génération');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputDir = $input->getArgument('output-dir');
        $force = (bool) $input->getOption('force');
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

        // === PHASE 1: Collecter toutes les données des tables ===
        $output->writeln('<info>📊 Analyse du schéma de la base de données...</info>');
        $allTablesData = [];

        foreach ($tables as $table) {
            $columns = $this->schemaExtractor->getTableColumns($table);
            $primaryKeys = $this->schemaExtractor->getPrimaryKeys($table);
            $foreignKeys = $this->schemaExtractor->getForeignKeys($table);
            $uniqueConstraints = $this->schemaExtractor->getUniqueConstraints($table);

            if (empty($primaryKeys)) {
                $primaryKeys = $this->entityGenerator->detectPrimaryKey($table, $columns);
            }

            $allTablesData[$table] = [
                'columns' => $columns,
                'primaryKeys' => $primaryKeys,
                'foreignKeys' => $foreignKeys,
                'uniqueConstraints' => $uniqueConstraints
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

            $entityExisted = file_exists($entityPath);
            if ($force || !$entityExisted) {
                $entityCode = $this->entityGenerator->generateEntityCode(
                    $table,
                    $columns,
                    $primaryKeys,
                    $foreignKeys,
                    $inverseRelations,
                    $manyToManyRelations,
                    $uniqueConstraints
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

        $output->writeln('<info>✨ Génération terminée avec succès !</info>');
        return Command::SUCCESS;
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
