<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class MailCorrespondence extends Correspondence
{
    #[ORM\Column(type: 'string', length: 100)]
    protected $recipient;
    #[ORM\Column(type: 'string', length: 200)]
    protected $subject;

    /**
     * Set recipient.
     *
     * @param string $recipient
     *
     * @return MailCorrespondence
     */
    public function setRecipient($recipient)
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * Get recipient.
     *
     * @return string
     */
    public function getRecipient()
    {
        return $this->recipient;
    }

    /**
     * Set subject.
     *
     * @param string $subject
     *
     * @return MailCorrespondence
     */
    public function setSubject($subject)
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Get subject.
     *
     * @return string
     */
    public function getSubject()
    {
        return $this->subject;
    }
}
