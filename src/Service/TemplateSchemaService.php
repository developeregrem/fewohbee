<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Doctrine\ORM\Mapping as ORM;

/**
 * Builds a JSON-serialisable schema tree for template variables by introspecting
 * entity classes via PHP Reflection and Doctrine ORM attributes.
 *
 * The schema is consumed by the frontend autocomplete in the template code editor.
 */
class TemplateSchemaService
{
    private const MAX_DEPTH = 4;
    private const ENTITY_NAMESPACE = 'App\\Entity';

    /**
     * Build the full schema tree from a top-level variable definition map.
     *
     * Each entry in $variableMap is either:
     *   - ['class' => 'App\Entity\Foo']                    → single entity
     *   - ['class' => 'App\Entity\Foo', 'collection' => true] → collection of entities
     *   - ['type' => 'scalar']                              → plain value (string, number, …)
     *
     * @param array<string, array{class?: class-string, collection?: bool, type?: string}> $variableMap
     *
     * @return array<string, mixed> JSON-ready schema tree
     */
    public function buildSchema(array $variableMap): array
    {
        $schema = [];

        foreach ($variableMap as $name => $definition) {
            if (isset($definition['type']) && 'scalar' === $definition['type']) {
                $schema[$name] = ['type' => 'scalar'];
                continue;
            }

            if (isset($definition['type']) && 'array' === $definition['type']) {
                $schema[$name] = [
                    'type' => 'array',
                    'singularName' => $this->singularize($name),
                ];
                continue;
            }

            $class = $definition['class'] ?? null;
            if (null === $class || !class_exists($class)) {
                $schema[$name] = ['type' => 'scalar'];
                continue;
            }

            $isCollection = !empty($definition['collection']);
            $visited = [];
            $properties = $this->resolveClassProperties($class, 1, $visited);

            if ($isCollection) {
                $schema[$name] = [
                    'type' => 'collection',
                    'class' => $this->shortClassName($class),
                    'singularName' => $this->singularize($name),
                    'properties' => $properties,
                ];
            } else {
                $schema[$name] = [
                    'type' => 'entity',
                    'class' => $this->shortClassName($class),
                    'properties' => $properties,
                ];
            }
        }

        return $schema;
    }

    /**
     * Resolve all public getter properties of a class recursively.
     *
     * @param class-string         $className FQCN of the entity
     * @param int                  $depth     current recursion depth
     * @param array<string, bool>  $visited   already-visited class names (cycle detection)
     *
     * @return array<string, mixed>
     */
    private function resolveClassProperties(string $className, int $depth, array &$visited): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        // Mark class as visited to prevent infinite recursion on circular relations
        $visited[$className] = true;

        $refClass = new \ReflectionClass($className);
        $properties = [];

        foreach ($refClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $propertyName = $this->extractPropertyName($method);
            if (null === $propertyName) {
                continue;
            }

            $targetClass = $this->resolveTargetClass($refClass, $method, $propertyName);
            $isCollection = $this->isCollectionReturn($refClass, $method, $propertyName);

            if (null !== $targetClass && $this->isEntityClass($targetClass)) {
                if ($isCollection) {
                    $childProps = isset($visited[$targetClass])
                        ? []
                        : $this->resolveClassProperties($targetClass, $depth + 1, $visited);

                    $properties[$propertyName] = [
                        'type' => 'collection',
                        'class' => $this->shortClassName($targetClass),
                        'singularName' => $this->singularize($propertyName),
                        'properties' => $childProps,
                    ];
                } else {
                    $childProps = isset($visited[$targetClass])
                        ? []
                        : $this->resolveClassProperties($targetClass, $depth + 1, $visited);

                    $properties[$propertyName] = [
                        'type' => 'entity',
                        'class' => $this->shortClassName($targetClass),
                        'properties' => $childProps,
                    ];
                }
            } else {
                $isDate = $this->isDateReturn($method) || $this->isDateProperty($refClass, $propertyName);
                $properties[$propertyName] = ['type' => $isDate ? 'date' : 'scalar'];
            }
        }

        // Remove visited mark so the same class can appear in other branches
        unset($visited[$className]);

        return $properties;
    }

    /**
     * Extract a camelCase property name from a getter method.
     * Returns null for methods that are not getters (set*, add*, remove*, is*, has*, __*).
     */
    private function extractPropertyName(\ReflectionMethod $method): ?string
    {
        $name = $method->getName();

        // Skip non-getter methods and methods with required parameters
        if ($method->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        // Skip magic methods, setters, adders, removers
        if (str_starts_with($name, '__')
            || str_starts_with($name, 'set')
            || str_starts_with($name, 'add')
            || str_starts_with($name, 'remove')
        ) {
            return null;
        }

        // get* → strip "get" prefix and lcfirst
        if (str_starts_with($name, 'get') && strlen($name) > 3) {
            return lcfirst(substr($name, 3));
        }

        // is* → keep as-is (e.g. isConflict)
        if (str_starts_with($name, 'is') && strlen($name) > 2) {
            return $name;
        }

        return null;
    }

    /**
     * Determine the target entity class for a relationship property.
     *
     * Uses Doctrine ORM attributes on the corresponding class property first,
     * then falls back to method return type analysis.
     */
    private function resolveTargetClass(\ReflectionClass $refClass, \ReflectionMethod $method, string $propertyName): ?string
    {
        // Try Doctrine ORM attribute on the property
        if ($refClass->hasProperty($propertyName)) {
            $prop = $refClass->getProperty($propertyName);
            $targetFromAttr = $this->getTargetEntityFromAttributes($prop);
            if (null !== $targetFromAttr) {
                return $targetFromAttr;
            }
        }

        // Fall back to return type
        $returnType = $method->getReturnType();
        if ($returnType instanceof \ReflectionNamedType && !$returnType->isBuiltin()) {
            $typeName = $returnType->getName();
            if ($this->isEntityClass($typeName)) {
                return $typeName;
            }
        }

        // Union types (e.g. ArrayCollection|Collection)
        if ($returnType instanceof \ReflectionUnionType) {
            foreach ($returnType->getTypes() as $type) {
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    if ($this->isEntityClass($typeName)) {
                        return $typeName;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Read the targetEntity from Doctrine ORM relationship attributes.
     */
    private function getTargetEntityFromAttributes(\ReflectionProperty $property): ?string
    {
        $attrClasses = [
            ORM\ManyToOne::class,
            ORM\OneToMany::class,
            ORM\ManyToMany::class,
            ORM\OneToOne::class,
        ];

        foreach ($attrClasses as $attrClass) {
            $attrs = $property->getAttributes($attrClass);
            if (!empty($attrs)) {
                $args = $attrs[0]->getArguments();
                $target = $args['targetEntity'] ?? $args[0] ?? null;
                if (is_string($target)) {
                    return $this->resolveEntityFQCN($target, $property->getDeclaringClass());
                }
            }
        }

        return null;
    }

    /**
     * Check if a method returns a Doctrine Collection (indicating a to-many relation).
     */
    private function isCollectionReturn(\ReflectionClass $refClass, \ReflectionMethod $method, string $propertyName): bool
    {
        // Check Doctrine attribute type first
        if ($refClass->hasProperty($propertyName)) {
            $prop = $refClass->getProperty($propertyName);
            $attrClasses = [ORM\OneToMany::class, ORM\ManyToMany::class];
            foreach ($attrClasses as $attrClass) {
                if (!empty($prop->getAttributes($attrClass))) {
                    return true;
                }
            }
        }

        // Fall back to return type
        $returnType = $method->getReturnType();
        if ($returnType instanceof \ReflectionNamedType) {
            return $this->isCollectionType($returnType->getName());
        }

        if ($returnType instanceof \ReflectionUnionType) {
            foreach ($returnType->getTypes() as $type) {
                if ($type instanceof \ReflectionNamedType && $this->isCollectionType($type->getName())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a method returns a date/datetime type.
     */
    private function isDateReturn(\ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();

        if ($returnType instanceof \ReflectionNamedType) {
            return $this->isDateType($returnType->getName());
        }

        if ($returnType instanceof \ReflectionUnionType) {
            foreach ($returnType->getTypes() as $type) {
                if ($type instanceof \ReflectionNamedType && $this->isDateType($type->getName())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a class property has a Doctrine Column type of date/datetime.
     * Fallback for entities without PHP return type declarations.
     */
    private function isDateProperty(\ReflectionClass $refClass, string $propertyName): bool
    {
        if (!$refClass->hasProperty($propertyName)) {
            return false;
        }

        $prop = $refClass->getProperty($propertyName);
        $attrs = $prop->getAttributes(ORM\Column::class);
        if (empty($attrs)) {
            return false;
        }

        $args = $attrs[0]->getArguments();
        $columnType = $args['type'] ?? $args[0] ?? null;

        return is_string($columnType) && in_array($columnType, [
            'date', 'datetime', 'date_immutable', 'datetime_immutable',
            'datetimetz', 'datetimetz_immutable',
        ], true);
    }

    private function isDateType(string $typeName): bool
    {
        return in_array($typeName, [
            \DateTime::class,
            \DateTimeImmutable::class,
            \DateTimeInterface::class,
        ], true);
    }

    private function isCollectionType(string $typeName): bool
    {
        return in_array($typeName, [
            'Doctrine\\Common\\Collections\\Collection',
            'Doctrine\\Common\\Collections\\ArrayCollection',
            'array',
        ], true);
    }

    /**
     * Check if a FQCN belongs to the application entity namespace.
     */
    private function isEntityClass(string $className): bool
    {
        return str_starts_with($className, self::ENTITY_NAMESPACE . '\\');
    }

    /**
     * Resolve a possibly short entity class name to its FQCN.
     */
    private function resolveEntityFQCN(string $name, \ReflectionClass $context): string
    {
        // Already fully qualified
        if (class_exists($name)) {
            return $name;
        }

        // Try prepending the entity namespace
        $fqcn = self::ENTITY_NAMESPACE . '\\' . ltrim($name, '\\');
        if (class_exists($fqcn)) {
            return $fqcn;
        }

        // Try relative to the declaring class namespace
        $ns = $context->getNamespaceName();
        $relative = $ns . '\\' . ltrim($name, '\\');
        if (class_exists($relative)) {
            return $relative;
        }

        return $name;
    }

    /**
     * Return the short (unqualified) class name.
     */
    private function shortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }

    /**
     * Derive a singular variable name from a plural collection name.
     *
     * Simple English rules: "invoices" → "invoice", "entries" → "entry", etc.
     */
    private function singularize(string $plural): string
    {
        if (str_ends_with($plural, 'ies')) {
            return substr($plural, 0, -3) . 'y';
        }
        if (str_ends_with($plural, 'ses') || str_ends_with($plural, 'xes')) {
            return substr($plural, 0, -2);
        }
        if (str_ends_with($plural, 's') && !str_ends_with($plural, 'ss')) {
            return substr($plural, 0, -1);
        }

        return $plural;
    }
}
