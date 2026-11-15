<?php

declare(strict_types=1);

namespace App\Service\TemplateSandbox;

use Doctrine\Common\Collections\Collection;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Markup;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedTestError;
use Twig\Sandbox\SecurityPolicyInterface;
use Twig\Template;

/**
 * Decides what user-authored templates may execute.
 *
 * Tags, filters, functions and tests are fixed allow lists: exposing a new one to templates is a
 * deliberate decision and also needs its extension in {@see TemplateSandbox}. Methods follow one
 * rule instead of per-class lists, so new entity getters work without touching this class:
 * application data objects expose only read accessors (get/is/has), never mutators, and
 * user accounts expose nothing.
 */
final class TemplateSecurityPolicy implements SecurityPolicyInterface
{
    /** Nothing that loads, defines or extends other templates. */
    public const ALLOWED_TAGS = ['apply', 'for', 'if', 'set'];

    public const ALLOWED_FILTERS = [
        // Twig core
        'abs', 'batch', 'capitalize', 'column', 'date', 'date_modify', 'default', 'e', 'escape',
        'filter', 'find', 'first', 'format', 'join', 'json_encode', 'keys', 'last', 'length', 'lower',
        'map', 'merge', 'nl2br', 'number_format', 'raw', 'reduce', 'replace', 'reverse', 'round',
        'slice', 'sort', 'spaceless', 'split', 'striptags', 'title', 'trim', 'upper', 'url_encode',
        // Application
        'calendar_entry_time', 'display_name', 'localized', 'localized_amount', 'salutation_label',
        'toBase64', 'toHex', 'trans',
    ];

    public const ALLOWED_FUNCTIONS = [
        // Twig core
        'cycle', 'date', 'max', 'min', 'range',
        // Application
        'check_in_times', 'date_difference', 'get_date_diff_amount', 'getLocalizedDate',
        'getLocalizedMonth', 'guest_checkin_qr', 'guest_checkin_url', 'is_decimal_place_0',
        'opening_hours', 'payment_qr',
    ];

    /** Twig core tests without "constant", which reads arbitrary PHP constants. */
    public const ALLOWED_TESTS = [
        'defined', 'divisible by', 'empty', 'even', 'iterable', 'mapping', 'none', 'null', 'odd',
        'same as', 'sequence', 'true',
    ];

    /** Namespaces whose objects are template data rather than services. */
    private const DATA_NAMESPACES = ['App\\Entity\\', 'App\\Dto\\'];

    private const COLLECTION_METHODS = [
        'contains', 'containskey', 'count', 'first', 'get', 'getkeys', 'getvalues', 'isempty', 'last',
        'slice', 'toarray',
    ];

    private const DATE_INTERVAL_PROPERTIES = ['d', 'days', 'f', 'h', 'i', 'invert', 'm', 's', 'y'];

    /**
     * @param string[] $tags
     * @param string[] $filters
     * @param string[] $functions
     * @param string[] $tests
     */
    public function checkSecurity($tags, $filters, $functions, array $tests = []): void
    {
        foreach ($tags as $tag) {
            if (!\in_array($tag, self::ALLOWED_TAGS, true)) {
                throw new SecurityNotAllowedTagError(\sprintf('Tag "%s" is not allowed.', $tag), $tag);
            }
        }

        foreach ($filters as $filter) {
            if (!\in_array($filter, self::ALLOWED_FILTERS, true)) {
                throw new SecurityNotAllowedFilterError(\sprintf('Filter "%s" is not allowed.', $filter), $filter);
            }
        }

        foreach ($functions as $function) {
            if (!\in_array($function, self::ALLOWED_FUNCTIONS, true)) {
                throw new SecurityNotAllowedFunctionError(\sprintf('Function "%s" is not allowed.', $function), $function);
            }
        }

        foreach ($tests as $test) {
            if (!\in_array($test, self::ALLOWED_TESTS, true)) {
                throw new SecurityNotAllowedTestError(\sprintf('Test "%s" is not allowed.', $test), $test);
            }
        }
    }

    public function checkMethodAllowed($obj, $method): void
    {
        // Twig's own objects, as in Twig\Sandbox\SecurityPolicy: printing |raw output calls Markup::__toString().
        if ($obj instanceof Template || $obj instanceof Markup) {
            return;
        }

        if (!$this->isMethodAllowed($obj, (string) $method)) {
            throw new SecurityNotAllowedMethodError(\sprintf('Calling "%s" method on a "%s" object is not allowed.', $method, $obj::class), $obj::class, (string) $method);
        }
    }

    public function checkPropertyAllowed($obj, $property): void
    {
        if (!$this->isPropertyAllowed($obj, (string) $property)) {
            throw new SecurityNotAllowedPropertyError(\sprintf('Calling "%s" property on a "%s" object is not allowed.', $property, $obj::class), $obj::class, (string) $property);
        }
    }

    private function isMethodAllowed(object $obj, string $method): bool
    {
        // Reachable through associations (e.g. created-by fields), but holds the password hash.
        if ($obj instanceof UserInterface) {
            return false;
        }

        $lowerMethod = strtolower($method);
        if ($obj instanceof \DateTimeInterface) {
            return \in_array($lowerMethod, ['format', 'gettimestamp'], true);
        }
        if ($obj instanceof \DateInterval) {
            return 'format' === $lowerMethod;
        }
        if ($obj instanceof Collection) {
            return \in_array($lowerMethod, self::COLLECTION_METHODS, true);
        }
        if (!$this->isTemplateData($obj)) {
            return false;
        }

        // Twig passes the declared method name, so the case-sensitive prefix keeps out
        // mutators such as "issueInvoice()" that merely start with "is".
        return '__tostring' === $lowerMethod || 1 === preg_match('/^(get|is|has)[A-Z0-9_]/', $method);
    }

    private function isPropertyAllowed(object $obj, string $property): bool
    {
        if ($obj instanceof UserInterface) {
            return false;
        }
        if ($obj instanceof \UnitEnum) {
            return \in_array($property, ['name', 'value'], true);
        }
        if ($obj instanceof \DateInterval) {
            return \in_array($property, self::DATE_INTERVAL_PROPERTIES, true);
        }

        return $this->isTemplateData($obj);
    }

    /** Doctrine proxies live in their own namespace and extend the entity class. */
    private function isTemplateData(object $obj): bool
    {
        foreach ([$obj::class, ...array_values(class_parents($obj) ?: [])] as $class) {
            foreach (self::DATA_NAMESPACES as $namespace) {
                if (str_starts_with($class, $namespace)) {
                    return true;
                }
            }
        }

        return false;
    }
}
