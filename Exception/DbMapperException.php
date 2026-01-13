<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Exception;

/**
 * Base exception for DbMapperBundle.
 *
 * All exceptions thrown by the bundle extend this base exception,
 * allowing for easy exception handling by catching a single type.
 *
 * @author Diallo Moussa <moussadou128@gmail.com>
 */
class DbMapperException extends \RuntimeException
{
}

