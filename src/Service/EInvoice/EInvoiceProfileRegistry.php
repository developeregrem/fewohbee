<?php

declare(strict_types=1);

namespace App\Service\EInvoice;

// Registry for available e-invoice profile generators.
class EInvoiceProfileRegistry
{
    /**
     * @var array<string, EInvoiceProfileGeneratorInterface>
     */
    private array $profilesByKey = [];

    /**
     * @param iterable<EInvoiceProfileGeneratorInterface> $profiles
     */
    public function __construct(iterable $profiles)
    {
        foreach ($profiles as $profile) {
            $key = $profile->getProfileKey();
            if (!isset($this->profilesByKey[$key])) {
                $this->profilesByKey[$key] = $profile;
            }
        }
    }

    // Resolves a profile generator by its key.
    public function getProfile(string $key): EInvoiceProfileGeneratorInterface
    {
        if (!isset($this->profilesByKey[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown e-invoice profile "%s".', $key));
        }

        return $this->profilesByKey[$key];
    }

    /**
     * @return array<string, string>
     */
    // Returns label-key to profile-key mapping for form choices.
    public function getProfileChoices(): array
    {
        $choices = [];
        foreach ($this->profilesByKey as $profile) {
            $choices[$profile->getLabelKey()] = $profile->getProfileKey();
        }

        return $choices;
    }
}
