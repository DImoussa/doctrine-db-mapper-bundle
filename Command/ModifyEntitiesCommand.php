<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Command;

use App\Bundle\DbMapperBundle\Service\DoctrineTypeRegistry;
use App\Bundle\DbMapperBundle\Service\SchemaChangePlanner;
use App\Bundle\DbMapperBundle\Service\SchemaExplorer;
use App\Bundle\DbMapperBundle\Service\SchemaModifier;
use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

#[AsCommand(
    name: 'dbmapper:modify-entities',
    description: 'Modifie le schéma (tables/attributs) de manière interactive en s’appuyant sur Doctrine',
)]
class ModifyEntitiesCommand extends Command
{
    private readonly Inflector $inflector;

    public function __construct(
        private readonly SchemaExplorer $schemaExplorer,
        private readonly DoctrineTypeRegistry $typeRegistry,
        private readonly SchemaChangePlanner $changePlanner,
        private readonly SchemaModifier $schemaModifier,
    ) {
        parent::__construct();
        $this->inflector = InflectorFactory::create()->build();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        \assert($helper instanceof QuestionHelper);

        $output->writeln('<info>[DbMapper]</info> Mode interactif de modification du schéma');

        while (true) {
            $choiceQuestion = new ChoiceQuestion(
                '\nQue veux-tu faire ?',
                [
                    'Lister les tables',
                    'Choisir une table à modifier',
                    'Afficher le plan des changements en cours',
                    'Prévisualiser le SQL qui sera exécuté',
                    'Appliquer les changements dans la base de données',
                    'Quitter',
                ],
                0
            );

            $choice = $helper->ask($input, $output, $choiceQuestion);

            if ($choice === 'Lister les tables') {
                $this->listTables($output);
            } elseif ($choice === 'Choisir une table à modifier') {
                $this->handleTableMenu($input, $output, $helper);
            } elseif ($choice === 'Afficher le plan des changements en cours') {
                $this->displayPlannedChanges($output);
            } elseif ($choice === 'Prévisualiser le SQL qui sera exécuté') {
                $this->previewSQL($output);
            } elseif ($choice === 'Appliquer les changements dans la base de données') {
                $this->applyChangesToDatabase($input, $output, $helper);
            } else { // Quitter
                break;
            }
        }

        $this->displayPlannedChanges($output);

        $output->writeln('<info>Fin du mode interactif.</info>');

        return Command::SUCCESS;
    }

    private function listTables(OutputInterface $output): void
    {
        $tables = $this->schemaExplorer->getTables();

        if ($tables === []) {
            $output->writeln('<comment>Aucune table trouvée dans la base.</comment>');

            return;
        }

        $output->writeln('<info>Tables disponibles :</info>');
        foreach ($tables as $table) {
            $output->writeln(" - $table");
        }
    }

    private function handleTableMenu(InputInterface $input, OutputInterface $output, QuestionHelper $helper): void
    {
        $tables = $this->schemaExplorer->getTables();
        if ($tables === []) {
            $output->writeln('<comment>Aucune table disponible.</comment>');

            return;
        }

        $tableQuestion = new ChoiceQuestion('Choisis une table à modifier', $tables, 0);
        $tableName = $helper->ask($input, $output, $tableQuestion);

        while (true) {
            $menuQuestion = new ChoiceQuestion(
                sprintf("\nTable sélectionnée : <info>%s</info>\nQue veux-tu faire ?", $tableName),
                [
                    'Voir les attributs (colonnes)',
                    'Ajouter un nouvel attribut',
                    'Gérer les relations',
                    'Revenir au menu principal',
                ],
                0
            );

            $choice = $helper->ask($input, $output, $menuQuestion);

            if ($choice === 'Voir les attributs (colonnes)') {
                $this->showTableColumns($output, $tableName);
            } elseif ($choice === 'Ajouter un nouvel attribut') {
                $this->addColumnFlow($input, $output, $helper, $tableName);
            } elseif ($choice === 'Gérer les relations') {
                $this->manageRelationsFlow($input, $output, $helper, $tableName);
            } else { // Revenir au menu principal
                break;
            }
        }
    }

    private function showTableColumns(OutputInterface $output, string $tableName): void
    {
        $columns = $this->schemaExplorer->getColumns($tableName);

        if ($columns === []) {
            $output->writeln(sprintf('<comment>Aucune colonne trouvée pour la table "%s".</comment>', $tableName));

            return;
        }

        $output->writeln(sprintf('<info>Colonnes de la table %s :</info>', $tableName));
        foreach ($columns as $column) {
            $nullable = $column['nullable'] ? 'YES' : 'NO';
            $output->writeln(sprintf(' - %s (%s), nullable: %s', $column['name'], $column['doctrineType'], $nullable));
        }
    }

    private function addColumnFlow(InputInterface $input, OutputInterface $output, QuestionHelper $helper, string $tableName): void
    {
        $existingColumns = $this->schemaExplorer->getColumns($tableName);
        $existingNames = array_map(static fn (array $col): string => $col['name'], $existingColumns);

        // Nom de la nouvelle colonne
        $nameQuestion = new Question('Nom du nouvel attribut (colonne) : ');
        $nameQuestion->setValidator(function (?string $answer) use ($existingNames) {
            $answer = $answer !== null ? trim($answer) : '';
            if ($answer == '') {
                throw new \RuntimeException('Le nom de la colonne ne peut pas être vide.');
            }
            if (in_array($answer, $existingNames, true)) {
                throw new \RuntimeException('Une colonne avec ce nom existe déjà dans cette table.');
            }

            return $answer;
        });

        $columnName = $helper->ask($input, $output, $nameQuestion);

        // Type Doctrine
        $supportedTypes = $this->typeRegistry->getSupportedTypes();
        $typeQuestion = new Question(
            sprintf(
                "Type Doctrine du nouvel attribut (ex: %s) : ",
                implode(', ', $supportedTypes)
            )
        );

        $typeQuestion->setValidator(function (?string $answer) use ($supportedTypes) {
            $answer = $answer !== null ? strtolower(trim($answer)) : '';
            if ($answer == '') {
                throw new \RuntimeException('Le type ne peut pas être vide.');
            }
            if (!in_array($answer, $supportedTypes, true)) {
                throw new \RuntimeException(
                    sprintf(
                        'Type Doctrine invalide "%s". Types valides : %s',
                        $answer,
                        implode(', ', $supportedTypes)
                    )
                );
            }

            return $answer;
        });

        $doctrineType = $helper->ask($input, $output, $typeQuestion);

        // Nullable ?
        $nullableQuestion = new ChoiceQuestion(
            'Ce nouvel attribut peut-il être NULL ?',
            ['non', 'oui'],
            0
        );
        $nullable = $helper->ask($input, $output, $nullableQuestion) === 'oui';

        $output->writeln('');
        $output->writeln('<info>Récapitulatif de la nouvelle colonne :</info>');
        $output->writeln(sprintf(' - Table : %s', $tableName));
        $output->writeln(sprintf(' - Nom   : %s', $columnName));
        $output->writeln(sprintf(' - Type  : %s', $doctrineType));
        $output->writeln(sprintf(' - NULL  : %s', $nullable ? 'oui' : 'non'));

        $confirmQuestion = new ChoiceQuestion(
            'Confirmer l’ajout de cette colonne au plan de changements ?',
            ['non', 'oui'],
            1
        );

        $confirm = $helper->ask($input, $output, $confirmQuestion);

        if ($confirm === 'oui') {
            $this->changePlanner->addAddColumnChange($tableName, $columnName, $doctrineType, $nullable);
            $output->writeln('<info>Colonne ajoutée au plan de changements (aucune modification réelle effectuée pour l’instant).</info>');
        } else {
            $output->writeln('<comment>Ajout de colonne annulé.</comment>');
        }
    }

    private function displayPlannedChanges(OutputInterface $output): void
    {
        if (!$this->changePlanner->hasChanges()) {
            $output->writeln('<comment>Aucun changement planifié pour l’instant.</comment>');

            return;
        }

        $output->writeln("\n<info>Plan de changements :</info>");
        foreach ($this->changePlanner->getChanges() as $change) {
            if ($change['type'] === 'add_column') {
                $output->writeln(sprintf(
                    ' - [ADD COLUMN] Table %s : %s (%s), nullable: %s',
                    $change['table'],
                    $change['name'],
                    $change['doctrineType'],
                    $change['nullable'] ? 'oui' : 'non'
                ));
            } elseif ($change['type'] === 'add_relation') {
                $config = $change['config'];
                $relationType = $config['doctrineRelationType'] ?? strtoupper(str_replace('-', '', $config['relationType']));
                $details = sprintf(
                    'Table %s → %s : %s (champ: %s)',
                    $change['sourceTable'],
                    $config['targetTable'],
                    $relationType,
                    $config['fieldName'] ?? 'N/A'
                );

                if (isset($config['inversedBy'])) {
                    $details .= sprintf(', inversedBy: %s', $config['inversedBy']);
                }
                if (isset($config['joinTable'])) {
                    $details .= sprintf(', joinTable: %s', $config['joinTable']);
                }

                $output->writeln(' - [ADD RELATION] ' . $details);
            }
        }
    }

    private function previewSQL(OutputInterface $output): void
    {
        if (!$this->changePlanner->hasChanges()) {
            $output->writeln('<comment>Aucun changement planifié pour l\'instant.</comment>');

            return;
        }

        $sqlStatements = $this->schemaModifier->previewSQL($this->changePlanner->getChanges());

        $output->writeln("\n<info>Requêtes SQL qui seront exécutées :</info>");
        foreach ($sqlStatements as $sql) {
            $output->writeln(sprintf(' <comment>%s;</comment>', $sql));
        }
    }

    private function applyChangesToDatabase(InputInterface $input, OutputInterface $output, QuestionHelper $helper): void
    {
        if (!$this->changePlanner->hasChanges()) {
            $output->writeln('<comment>Aucun changement à appliquer.</comment>');

            return;
        }

        // Afficher d'abord le plan
        $this->displayPlannedChanges($output);
        $this->previewSQL($output);

        // Demander confirmation
        $confirmQuestion = new ChoiceQuestion(
            "\n<question>Êtes-vous sûr de vouloir appliquer ces changements dans la base de données ?</question>",
            ['non', 'oui'],
            0
        );

        $confirm = $helper->ask($input, $output, $confirmQuestion);

        if ($confirm !== 'oui') {
            $output->writeln('<comment>Application des changements annulée.</comment>');

            return;
        }

        $output->writeln("\n<info>Application des changements...</info>");

        $result = $this->schemaModifier->applyChanges($this->changePlanner->getChanges());

        if ($result['success']) {
            $output->writeln('<info>✓ Tous les changements ont été appliqués avec succès !</info>');
            foreach ($result['executed'] as $sql) {
                $output->writeln(sprintf(' <info>✓</info> %s', $sql));
            }

            // Vider le plan de changements après application réussie
            $this->changePlanner->clear();
            $output->writeln("\n<info>Le plan de changements a été vidé.</info>");
        } else {
            $output->writeln('<error>✗ Certains changements n\'ont pas pu être appliqués :</error>');
            foreach ($result['errors'] as $error) {
                $output->writeln(sprintf(' <error>✗</error> %s', $error));
            }

            if (!empty($result['executed'])) {
                $output->writeln("\n<info>Changements appliqués avec succès :</info>");
                foreach ($result['executed'] as $sql) {
                    $output->writeln(sprintf(' <info>✓</info> %s', $sql));
                }
            }
        }
    }

    private function manageRelationsFlow(InputInterface $input, OutputInterface $output, QuestionHelper $helper, string $tableName): void
    {
        $output->writeln("\n<info>=== Gestion des relations ===</info>");

        // Choix du type de relation
        $relationTypeQuestion = new ChoiceQuestion(
            'Quel type de relation veux-tu créer ?',
            [
                'ManyToOne',
                'OneToMany',
                'ManyToMany',
                'OneToOne',
                'Annuler',
            ],
            0
        );

        $relationChoice = $helper->ask($input, $output, $relationTypeQuestion);

        if ($relationChoice === 'Annuler') {
            $output->writeln('<comment>Gestion des relations annulée.</comment>');
            return;
        }

        // Conserver la casse correcte pour Doctrine (ManyToOne, OneToMany, etc.)
        $relationType = $relationChoice;
        // Convertir en format kebab-case pour le SQL : ManyToOne → many-to-one
        $relationTypeSQL = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $relationType));

        // Sélectionner la table cible
        $tables = $this->schemaExplorer->getTables();
        $tableChoiceQuestion = new ChoiceQuestion(
            'Vers quelle table veux-tu créer cette relation ?',
            $tables,
            0
        );
        $targetTable = $helper->ask($input, $output, $tableChoiceQuestion);

        // Configuration spécifique selon le type de relation
        $relationConfig = [
            'relationType' => $relationTypeSQL, // Pour le SQL : many-to-one, etc.
            'doctrineRelationType' => $relationType, // Pour Doctrine : ManyToOne, etc.
            'targetTable' => $targetTable,
        ];

        if ($relationType === 'ManyToOne' || $relationType === 'OneToOne') {
            // Demander le nom du champ dans l'entité source
            $defaultFieldName = $this->inflector->singularize(strtolower($targetTable));

            $fieldNameQuestion = new Question(
                sprintf('Nom du champ dans l\'entité %s (ex: author, category) [%s] : ', $tableName, $defaultFieldName),
                $defaultFieldName
            );
            $relationConfig['fieldName'] = $helper->ask($input, $output, $fieldNameQuestion);

            // Demander si nullable
            $nullableQuestion = new ChoiceQuestion(
                'Cette relation peut-elle être NULL ?',
                ['non', 'oui'],
                0
            );
            $relationConfig['nullable'] = $helper->ask($input, $output, $nullableQuestion) === 'oui';

            // Demander le comportement ON DELETE
            $onDeleteQuestion = new ChoiceQuestion(
                'Comportement lors de la suppression de l\'entité cible (ON DELETE) :',
                ['SET NULL', 'CASCADE', 'RESTRICT', 'NO ACTION'],
                $relationConfig['nullable'] ? 0 : 1
            );
            $relationConfig['onDelete'] = $helper->ask($input, $output, $onDeleteQuestion);
        } elseif ($relationType === 'OneToMany') {
            // Pour OneToMany, demander le nom du champ dans l'entité source (collection - pluriel)
            $defaultFieldName = $this->inflector->pluralize(strtolower($targetTable));

            $fieldNameQuestion = new Question(
                sprintf('Nom du champ collection dans l\'entité %s (ex: posts, comments) [%s] : ', $tableName, $defaultFieldName),
                $defaultFieldName
            );
            $relationConfig['fieldName'] = $helper->ask($input, $output, $fieldNameQuestion);

            // Demander le nom du champ inverse dans l'entité cible (singulier)
            $defaultInverseField = $this->inflector->singularize(strtolower($tableName));

            $inverseFieldQuestion = new Question(
                sprintf('Nom du champ inverse dans l\'entité %s [%s] : ', $targetTable, $defaultInverseField),
                $defaultInverseField
            );
            $relationConfig['inversedBy'] = $helper->ask($input, $output, $inverseFieldQuestion);

            // Note : nullable concerne la FK dans la table cible
            $nullableQuestion = new ChoiceQuestion(
                sprintf('⚠️  La clé étrangère dans la table %s peut-elle être NULL ? (Note: ceci concerne la colonne FK, pas la collection)', $targetTable),
                ['non', 'oui'],
                1
            );
            $relationConfig['nullable'] = $helper->ask($input, $output, $nullableQuestion) === 'oui';

            // Demander le comportement ON DELETE
            $onDeleteQuestion = new ChoiceQuestion(
                'Comportement lors de la suppression (ON DELETE) :',
                ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'],
                0
            );
            $relationConfig['onDelete'] = $helper->ask($input, $output, $onDeleteQuestion);
        } elseif ($relationType === 'ManyToMany') {
            // Pour ManyToMany, demander le nom de la table de jointure
            $defaultJoinTable = $tableName . '_' . $targetTable;
            $joinTableQuestion = new Question(
                sprintf('Nom de la table de jointure [%s] : ', $defaultJoinTable),
                $defaultJoinTable
            );
            $relationConfig['joinTable'] = $helper->ask($input, $output, $joinTableQuestion);

            // Nom du champ collection côté source (pluriel)
            $defaultFieldName = $this->inflector->pluralize(strtolower($targetTable));

            $fieldNameQuestion = new Question(
                sprintf('Nom du champ collection dans l\'entité %s [%s] : ', $tableName, $defaultFieldName),
                $defaultFieldName
            );
            $relationConfig['fieldName'] = $helper->ask($input, $output, $fieldNameQuestion);

            // Colonnes de jointure (optionnel avancé)
            $advancedQuestion = new ChoiceQuestion(
                'Configurer manuellement les colonnes de jointure ?',
                ['non', 'oui'],
                0
            );
            $configureJoinColumns = $helper->ask($input, $output, $advancedQuestion) === 'oui';

            if ($configureJoinColumns) {
                $sourceColumnQuestion = new Question(
                    sprintf('Nom de la colonne FK vers %s dans la table de jointure [%s_id] : ', $tableName, $tableName),
                    $tableName . '_id'
                );
                $relationConfig['joinColumn'] = $helper->ask($input, $output, $sourceColumnQuestion);

                $targetColumnQuestion = new Question(
                    sprintf('Nom de la colonne FK vers %s dans la table de jointure [%s_id] : ', $targetTable, $targetTable),
                    $targetTable . '_id'
                );
                $relationConfig['inverseJoinColumn'] = $helper->ask($input, $output, $targetColumnQuestion);
            } else {
                $relationConfig['joinColumn'] = $tableName . '_id';
                $relationConfig['inverseJoinColumn'] = $targetTable . '_id';
            }
        }

        // Récapitulatif
        $output->writeln('');
        $output->writeln('<info>Récapitulatif de la relation :</info>');
        $output->writeln(sprintf(' - Type Doctrine  : %s', $relationType));
        $output->writeln(sprintf(' - Table source   : %s', $tableName));
        $output->writeln(sprintf(' - Table cible    : %s', $targetTable));
        if (isset($relationConfig['fieldName'])) {
            $output->writeln(sprintf(' - Nom du champ   : %s', $relationConfig['fieldName']));
        }
        if (isset($relationConfig['inversedBy'])) {
            $output->writeln(sprintf(' - Champ inverse  : %s', $relationConfig['inversedBy']));
        }
        if (isset($relationConfig['nullable'])) {
            $note = $relationType === 'OneToMany' ? ' (clé étrangère dans la table cible)' : '';
            $output->writeln(sprintf(' - Nullable       : %s%s', $relationConfig['nullable'] ? 'oui' : 'non', $note));
        }
        if (isset($relationConfig['onDelete'])) {
            $output->writeln(sprintf(' - ON DELETE      : %s', $relationConfig['onDelete']));
        }
        if (isset($relationConfig['joinTable'])) {
            $output->writeln(sprintf(' - Table jointure : %s', $relationConfig['joinTable']));
        }
        if (isset($relationConfig['joinColumn']) && isset($relationConfig['inverseJoinColumn'])) {
            $output->writeln(sprintf(' - Colonnes join  : %s, %s', $relationConfig['joinColumn'], $relationConfig['inverseJoinColumn']));
        }

        // Confirmation
        $confirmQuestion = new ChoiceQuestion(
            'Confirmer l\'ajout de cette relation au plan de changements ?',
            ['non', 'oui'],
            1
        );

        $confirm = $helper->ask($input, $output, $confirmQuestion);

        if ($confirm === 'oui') {
            $this->changePlanner->addRelationChange($tableName, $relationConfig);
            $output->writeln('<info>Relation ajoutée au plan de changements (aucune modification réelle effectuée pour l\'instant).</info>');
        } else {
            $output->writeln('<comment>Ajout de relation annulé.</comment>');
        }
    }
}
