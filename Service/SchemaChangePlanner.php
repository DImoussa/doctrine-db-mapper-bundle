<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Service;

/**
 * Stocke en mémoire les modifications de schéma demandées par l'utilisateur
 * (dry-run / plan de changements).
 */
class SchemaChangePlanner
{
    /** @var array<int, array<string, mixed>> */
    private array $changes = [];

    public function addAddColumnChange(string $table, string $name, string $type, bool $nullable): void
    {
        $this->changes[] = [
            'type' => 'add_column',
            'table' => $table,
            'name' => $name,
            'doctrineType' => $type,
            'nullable' => $nullable,
        ];
    }

    /**
     * Ajoute une relation au plan de changements.
     *
     * @param array<string, mixed> $relationConfig
     */
    public function addRelationChange(string $sourceTable, array $relationConfig): void
    {
        $this->changes[] = [
            'type' => 'add_relation',
            'sourceTable' => $sourceTable,
            'config' => $relationConfig,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }

    public function clear(): void
    {
        $this->changes = [];
    }
}
