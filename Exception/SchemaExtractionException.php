<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Exception;

/**
 * 
 * Exception thrown when database schema extraction fails.
 *
 * This exception is thrown when the bundle cannot retrieve information
 * from the database schema (tables, columns, foreign keys, etc.).
 *
 * @author Diallo Moussa <moussadou128@gmail.com>
 */
class SchemaExtractionException extends DbMapperException
{
}

