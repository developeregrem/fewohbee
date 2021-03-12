<?php

namespace App\Entity;

/**
 * DTO for mail attachments
 *
 * @author Alexander Elchlepp
 */
class MailAttachment {
    private string $body;
    private string$name;
    private string $contentType;
    
    function __construct(string $body, string $name, string $contentType) {
        $this->body = $body;
        $this->name = $name;
        $this->contentType = $contentType;
    }

    function getBody(): string {
        return $this->body;
    }

    function getName(): string {
        return $this->name;
    }

    function getContentType(): string {
        return $this->contentType;
    }

    function setBody(string $body): self {
        $this->body = $body;
    }

    function setName(string $name): self {
        $this->name = $name;
    }

    function setContentType(string $contentType): self {
        $this->contentType = $contentType;
    }
}
