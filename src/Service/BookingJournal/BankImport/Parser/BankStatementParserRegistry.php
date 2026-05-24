<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport\Parser;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Resolves a parser by its format key. Parsers register themselves
 * automatically through the "app.bank_import.parser" service tag.
 */
final class BankStatementParserRegistry
{
    /**
     * @var array<string, ParserInterface>
     */
    private array $parsers = [];

    /**
     * @param iterable<ParserInterface> $parsers
     */
    public function __construct(
        #[AutowireIterator('app.bank_import.parser')]
        iterable $parsers,
        private readonly ?TranslatorInterface $translator = null,
    ) {
        foreach ($parsers as $parser) {
            $this->parsers[$parser->getFormatKey()] = $parser;
        }
    }

    public function get(string $formatKey): ParserInterface
    {
        if (!isset($this->parsers[$formatKey])) {
            throw new \InvalidArgumentException($this->trans('accounting.bank_import.parser.error.format_not_registered', [
                '%format%' => $formatKey,
                '%available%' => implode(', ', array_keys($this->parsers)) ?: $this->trans('accounting.bank_import.parser.error.none_available'),
            ]));
        }

        return $this->parsers[$formatKey];
    }

    /**
     * @return list<string>
     */
    public function getFormatKeys(): array
    {
        return array_keys($this->parsers);
    }

    public function has(string $formatKey): bool
    {
        return isset($this->parsers[$formatKey]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator?->trans($key, $parameters) ?? $key;
    }
}
