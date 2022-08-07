<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * DTO for mail attachments.
 *
 * @author Alexander Elchlepp
 */
class MailAttachment
{
    private string $body;
    private string$name;
    private string $contentType;

    public function __construct(string $body, string $name, string $contentType)
    {
        $this->body = $body;
        $this->name = $name;
        $this->contentType = $contentType;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function setContentType(string $contentType): static
    {
        $this->contentType = $contentType;

        return $this;
    }
}
