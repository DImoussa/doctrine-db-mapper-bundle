<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Service;

/**
 *Fournit la liste des types Doctrine supportés et la validation associée.
 */
class DoctrineTypeRegistry
{
    /**
     * Retourne la liste des types Doctrine autorisés pour la création/modification de colonnes.
     *
     * @return string[]
     */
    public function getSupportedTypes(): array
    {
        return [
            // Types de base Doctrine les plus courants
            'string',
            'text',
            'integer',
            'smallint',
            'bigint',
            'boolean',
            'datetime',
            'datetimetz',
            'datetime_immutable',
            'date',
            'time',
            'float',
            'decimal',
            'json',
        ];
    }

    public function isValidType(string $type): bool
    {
        return in_array(strtolower($type), $this->getSupportedTypes(), true);
    }
}
