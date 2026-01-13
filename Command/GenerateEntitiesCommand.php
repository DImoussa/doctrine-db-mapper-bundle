<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Command;

use App\Bundle\DbMapperBundle\Service\SchemaExtractor;
use App\Bundle\DbMapperBundle\Service\EntityGenerator;
use App\Bundle\DbMapperBundle\Service\RelationshipAnalyzer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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

    public function __construct(
        SchemaExtractor $schemaExtractor,
        EntityGenerator $entityGenerator,
        RelationshipAnalyzer $relationshipAnalyzer
    ) {
        $this->schemaExtractor = $schemaExtractor;
        $this->entityGenerator = $entityGenerator;
        $this->relationshipAnalyzer = $relationshipAnalyzer;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('output-dir', InputArgument::REQUIRED, 'Répertoire de sortie pour les entités générées');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputDir = $input->getArgument('output-dir');
        $normalizedOutputDir = rtrim($outputDir, '/\\');
        $repositoryBaseDir = dirname($normalizedOutputDir) . DIRECTORY_SEPARATOR . 'Repository';

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

            if (!file_exists($entityPath)) {
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

                $output->writeln("✅ Entité générée : $className" . $relationInfo);
            } else {
                $output->writeln("⏭️  Entité déjà existante : $className");
            }

            if (!file_exists($repositoryPath)) {
                if (!is_dir(dirname($repositoryPath))) {
                    mkdir(dirname($repositoryPath), 0777, true);
                }
                $repositoryCode = $this->entityGenerator->generateRepositoryCode($className);
                file_put_contents($repositoryPath, $repositoryCode);
                $output->writeln("✅ Repository généré : {$className}Repository");
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

        }
