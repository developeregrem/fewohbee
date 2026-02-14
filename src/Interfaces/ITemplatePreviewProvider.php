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

namespace App\Interfaces;

use App\Entity\Template;

/**
 * Defines preview context, data building, and snippet lists for template previews.
 */
interface ITemplatePreviewProvider
{
    /**
     * Returns true when the provider can preview the given template.
     */
    public function supportsPreview(Template $template): bool;

    /**
     * Describe preview input fields for the UI.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPreviewContextDefinition(): array;

    /**
     * Build render parameters in the same shape as the live renderer.
     *
     * @param array<string, mixed> $ctx
     *
     * @return array<string, mixed>
     */
    public function buildPreviewRenderParams(Template $template, array $ctx): array;

    /**
     * Provide default sample context values when no user selection is made.
     *
     * @return array<string, mixed>
     */
    public function buildSampleContext(): array;

    /**
     * List available tokens/snippets for the template editor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAvailableSnippets(): array;

    /**
     * Describe the top-level render parameter types for the schema/autocomplete API.
     *
     * Each entry maps a variable name to its type definition:
     *   - ['class' => FQCN]                        → single entity
     *   - ['class' => FQCN, 'collection' => true]  → collection of entities
     *   - ['type' => 'scalar']                      → plain value (string, number, …)
     *
     * @return array<string, array{class?: class-string, collection?: bool, type?: string}>
     */
    public function getRenderParamsSchema(): array;
}
