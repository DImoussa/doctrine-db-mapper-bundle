<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Exception;

/**
 * Exception thrown when entity code generation fails.
 *
 * This exception is thrown when the bundle cannot generate valid
 * PHP code for an entity, repository, or embeddable class.
 *
 * @author Diallo Moussa <moussadou128@gmail.com>
 */
class EntityGenerationException extends DbMapperException
{
}

