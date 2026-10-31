<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class MailCorrespondence extends Correspondence
{
    #[ORM\Column(type: 'string', length: 100)]
    protected ?string $recipient = null;
    #[ORM\Column(type: 'string', length: 200)]
    protected ?string $subject = null;

    /** @return $this */
    public function setRecipient(string $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function getRecipient(): ?string
    {
        return $this->recipient;
    }

    /** @return $this */
    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }
}
