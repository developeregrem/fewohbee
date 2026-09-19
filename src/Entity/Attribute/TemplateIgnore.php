<?php

declare(strict_types=1);

namespace App\Entity\Attribute;

/**
 * Excludes an entity getter from the user-facing template autocomplete schema.
 *
 * Runtime Twig access remains unchanged; the attribute only hides values that are
 * internal, unsafe or not meaningfully printable from the editor's suggestions.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class TemplateIgnore
{
}

