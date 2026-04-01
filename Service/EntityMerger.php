<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Service;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Fusionne une entité existante avec le code nouvellement généré.
 * Préserve : interfaces, traits, méthodes custom, use statements, propriétés custom.
 */
class EntityMerger
{
    private Parser $parser;
    private Standard $printer;
    private NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->parser    = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer   = new Standard(['shortArraySyntax' => true]);
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * Fusionne le code existant avec le nouveau code généré.
     * Retourne le nouveau code enrichi du code custom préservé.
     */
    public function merge(string $existingCode, string $newCode): string
    {
        $existingAst = $this->parser->parse($existingCode);
        $newAst      = $this->parser->parse($newCode);

        if (!$existingAst || !$newAst) {
            return $newCode;
        }

        $existingClass = $this->findClass($existingAst);
        $newClass      = $this->findClass($newAst);

        if (!$existingClass || !$newClass) {
            return $newCode;
        }

        $newMethodNames    = $this->getMethodNames($newClass);
        $newPropertyNames  = $this->getPropertyNames($newClass);

        // Méthodes présentes dans l'ancien fichier mais pas dans le nouveau = méthodes custom
        $customMethods = $this->extractCustomMethods($existingClass, $newMethodNames);

        // Propriétés non-ORM présentes dans l'ancien fichier mais pas dans le nouveau
        $customProperties = $this->extractCustomProperties($existingClass, $newPropertyNames);

        // Fusionner interfaces, traits, use statements
        $this->mergeInterfaces($existingClass, $newClass);
        $this->mergeTraits($existingClass, $newClass);
        $this->mergeUseStatements($existingAst, $newAst);

        // Injecter les propriétés custom avant les méthodes
        if (!empty($customProperties)) {
            $this->injectPropertiesBeforeMethods($newClass, $customProperties);
        }

        // Injecter les méthodes custom à la fin de la classe
        foreach ($customMethods as $method) {
            $newClass->stmts[] = $method;
        }

        return "<?php\n\n" . $this->printer->prettyPrint($newAst) . "\n";
    }

    private function findClass(array $ast): ?Stmt\Class_
    {
        return $this->nodeFinder->findFirstInstanceOf($ast, Stmt\Class_::class);
    }

    /** @return array<string> */
    private function getMethodNames(Stmt\Class_ $class): array
    {
        return array_map(fn($m) => (string) $m->name, $class->getMethods());
    }

    /** @return array<string> */
    private function getPropertyNames(Stmt\Class_ $class): array
    {
        $names = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $names[] = (string) $prop->name;
                }
            }
        }
        return $names;
    }

    /**
     * Extrait les méthodes présentes dans $existing mais absentes de $newMethodNames.
     *
     * @return array<Stmt\ClassMethod>
     */
    private function extractCustomMethods(Stmt\Class_ $existing, array $newMethodNames): array
    {
        $custom = [];
        foreach ($existing->getMethods() as $method) {
            if (!in_array((string) $method->name, $newMethodNames, true)) {
                $custom[] = $method;
            }
        }
        return $custom;
    }

    /**
     * Extrait les propriétés non-ORM présentes dans $existing mais absentes du nouveau.
     *
     * @return array<Stmt\Property>
     */
    private function extractCustomProperties(Stmt\Class_ $existing, array $newPropertyNames): array
    {
        $custom = [];
        foreach ($existing->stmts as $stmt) {
            if (!($stmt instanceof Stmt\Property)) {
                continue;
            }
            foreach ($stmt->props as $prop) {
                if (!in_array((string) $prop->name, $newPropertyNames, true)) {
                    // Vérifier que ce n'est pas une propriété ORM (pas d'attribut #[ORM\...])
                    if (!$this->hasOrmAttribute($stmt)) {
                        $custom[] = $stmt;
                        break;
                    }
                }
            }
        }
        return $custom;
    }

    /**
     * Vérifie si une propriété possède un attribut ORM.
     */
    private function hasOrmAttribute(Stmt\Property $property): bool
    {
        foreach ($property->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if (str_starts_with((string) $attr->name, 'ORM\\') ||
                    str_contains((string) $attr->name, '\\ORM\\')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Ajoute dans $new les interfaces présentes dans $existing mais absentes de $new.
     */
    private function mergeInterfaces(Stmt\Class_ $existing, Stmt\Class_ $new): void
    {
        $newNames = array_map(fn($n) => (string) $n, $new->implements);
        foreach ($existing->implements as $interface) {
            if (!in_array((string) $interface, $newNames, true)) {
                $new->implements[] = $interface;
            }
        }
    }

    /**
     * Ajoute dans $new les traits présents dans $existing mais absents de $new.
     */
    private function mergeTraits(Stmt\Class_ $existing, Stmt\Class_ $new): void
    {
        $newTraitNames = [];
        foreach ($new->stmts as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $newTraitNames[] = (string) $trait;
                }
            }
        }

        $toAdd = [];
        foreach ($existing->stmts as $stmt) {
            if (!($stmt instanceof Stmt\TraitUse)) {
                continue;
            }
            foreach ($stmt->traits as $trait) {
                if (!in_array((string) $trait, $newTraitNames, true)) {
                    $toAdd[] = $trait;
                }
            }
        }

        if (!empty($toAdd)) {
            array_unshift($new->stmts, new Stmt\TraitUse($toAdd));
        }
    }

    /**
     * Ajoute dans $newAst les use statements présents dans $existingAst mais absents de $newAst.
     */
    private function mergeUseStatements(array $existingAst, array &$newAst): void
    {
        $existingUses = $this->collectUseStatements($existingAst);
        $newUses      = $this->collectUseStatements($newAst);

        $lastUseIndex = -1;
        foreach ($newAst as $index => $node) {
            if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
                $lastUseIndex = $index;
            }
        }

        $added = 0;
        foreach ($existingUses as $fqcn => $useStmt) {
            if (!isset($newUses[$fqcn])) {
                array_splice($newAst, $lastUseIndex + 1 + $added, 0, [$useStmt]);
                $added++;
            }
        }
    }

    /**
     * @return array<string, Stmt\Use_>
     */
    private function collectUseStatements(array $ast): array
    {
        $uses = [];
        foreach ($ast as $node) {
            if (!($node instanceof Stmt\Use_)) {
                continue;
            }
            foreach ($node->uses as $use) {
                $uses[(string) $use->name] = $node;
            }
        }
        return $uses;
    }

    /**
     * Injecte des propriétés dans la classe avant le premier ClassMethod.
     *
     * @param array<Stmt\Property> $properties
     */
    private function injectPropertiesBeforeMethods(Stmt\Class_ $class, array $properties): void
    {
        $insertAt = count($class->stmts);
        foreach ($class->stmts as $index => $stmt) {
            if ($stmt instanceof Stmt\ClassMethod) {
                $insertAt = $index;
                break;
            }
        }

        array_splice($class->stmts, $insertAt, 0, $properties);
    }
}

