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

        $customMethods    = $this->extractCustomMethods($existingClass, $newMethodNames);
        $customProperties = $this->extractCustomProperties($existingClass, $newPropertyNames, $newClass);

        $this->mergeInterfaces($existingClass, $newClass);
        $this->mergeTraits($existingClass, $newClass);
        $this->mergeUseStatements($existingAst, $newAst);

        if (!empty($customProperties)) {
            $preservedPropNames = array_map(
                fn($p) => (string) $p->props[0]->name,
                $customProperties
            );
            $this->mergeConstructorInits($existingClass, $newClass, $preservedPropNames);
            $this->injectPropertiesBeforeMethods($newClass, $customProperties);
        }

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
     * Extrait les propriétés non-ORM présentes dans $existing mais absentes du nouveau code.
     * Les propriétés portant un attribut ORM sont exclues : leur génération est entièrement
     * pilotée par le schéma de la base de données.
     *
     * @return array<Stmt\Property>
     */
    private function extractCustomProperties(Stmt\Class_ $existing, array $newPropertyNames, Stmt\Class_ $newClass): array
    {
        $custom = [];

        foreach ($existing->stmts as $stmt) {
            if (!($stmt instanceof Stmt\Property)) {
                continue;
            }
            foreach ($stmt->props as $prop) {
                if (!in_array((string) $prop->name, $newPropertyNames, true)) {
                    if ($this->hasOrmAttribute($stmt)) {
                        break;
                    }
                    $custom[] = $stmt;
                    break;
                }
            }
        }

        return $custom;
    }

    /**
     * Propage dans le constructeur du nouveau code les initialisations de collections
     * (ArrayCollection) correspondant aux propriétés préservées depuis l'ancien code.
     *
     * @param array<string> $preservedPropertyNames
     */
    private function mergeConstructorInits(Stmt\Class_ $existing, Stmt\Class_ $new, array $preservedPropertyNames): void
    {
        $oldInits = [];
        foreach ($existing->getMethods() as $method) {
            if ((string) $method->name !== '__construct') {
                continue;
            }
            foreach ($method->stmts ?? [] as $stmt) {
                if ($stmt instanceof Node\Stmt\Expression
                    && $stmt->expr instanceof Node\Expr\Assign
                    && $stmt->expr->var instanceof Node\Expr\PropertyFetch
                    && $stmt->expr->var->var instanceof Node\Expr\Variable
                    && (string) $stmt->expr->var->var->name === 'this'
                ) {
                    $propName = (string) $stmt->expr->var->name;
                    if (in_array($propName, $preservedPropertyNames, true)) {
                        $oldInits[$propName] = $stmt;
                    }
                }
            }
            break;
        }

        if (empty($oldInits)) {
            return;
        }

        foreach ($new->getMethods() as $method) {
            if ((string) $method->name !== '__construct') {
                continue;
            }
            $existingInitProps = [];
            foreach ($method->stmts ?? [] as $stmt) {
                if ($stmt instanceof Node\Stmt\Expression
                    && $stmt->expr instanceof Node\Expr\Assign
                    && $stmt->expr->var instanceof Node\Expr\PropertyFetch
                ) {
                    $existingInitProps[] = (string) $stmt->expr->var->name;
                }
            }
            foreach ($oldInits as $propName => $init) {
                if (!in_array($propName, $existingInitProps, true)) {
                    $method->stmts[] = $init;
                }
            }
            return;
        }
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

    /**
     * Supprime les propriétés ORM de type relation (OneToMany, ManyToOne, ManyToMany, OneToOne)
     * et leurs méthodes associées d'une entité orpheline dont la table est absente de la base.
     * Les propriétés scalaires (#[ORM\Column]) et le code custom sont conservés.
     */
    public function cleanOrphanedRelations(string $existingCode): string
    {
        $ast = $this->parser->parse($existingCode);
        if (!$ast) {
            return $existingCode;
        }

        $class = $this->findClass($ast);
        if (!$class) {
            return $existingCode;
        }

        $propNamesToRemove = [];
        $retainedStmts     = [];

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property && $this->hasOrmRelationAttribute($stmt)) {
                foreach ($stmt->props as $prop) {
                    $propNamesToRemove[] = (string) $prop->name;
                }
            } else {
                $retainedStmts[] = $stmt;
            }
        }

        if (empty($propNamesToRemove)) {
            return $existingCode;
        }

        $finalStmts = [];
        foreach ($retainedStmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod) {
                if ((string) $stmt->name === '__construct') {
                    $stmt->stmts = array_values(array_filter(
                        $stmt->stmts ?? [],
                        function ($s) use ($propNamesToRemove) {
                            return !($s instanceof Node\Stmt\Expression
                                && $s->expr instanceof Node\Expr\Assign
                                && $s->expr->var instanceof Node\Expr\PropertyFetch
                                && $s->expr->var->var instanceof Node\Expr\Variable
                                && (string) $s->expr->var->var->name === 'this'
                                && in_array((string) $s->expr->var->name, $propNamesToRemove, true));
                        }
                    ));
                    $finalStmts[] = $stmt;
                    continue;
                }

                if ($this->methodReferencesProperties($stmt, $propNamesToRemove)) {
                    continue;
                }
            }
            $finalStmts[] = $stmt;
        }

        $class->stmts = $finalStmts;

        return "<?php\n\n" . $this->printer->prettyPrint($ast) . "\n";
    }

    /**
     * Vérifie si une propriété porte un attribut ORM de type relation.
     */
    private function hasOrmRelationAttribute(Stmt\Property $property): bool
    {
        foreach ($property->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $attrName = (string) $attr->name;
                foreach (['ManyToOne', 'OneToMany', 'ManyToMany', 'OneToOne'] as $relationType) {
                    if (str_ends_with($attrName, $relationType)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Vérifie si une méthode contient une référence à l'une des propriétés données via $this->propName.
     *
     * @param array<string> $propertyNames
     */
    private function methodReferencesProperties(Stmt\ClassMethod $method, array $propertyNames): bool
    {
        return !empty($this->nodeFinder->find($method, function (Node $node) use ($propertyNames) {
            return $node instanceof Node\Expr\PropertyFetch
                && $node->var instanceof Node\Expr\Variable
                && (string) $node->var->name === 'this'
                && in_array((string) $node->name, $propertyNames, true);
        }));
    }
}

