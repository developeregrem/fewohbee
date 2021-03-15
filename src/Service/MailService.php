<?php

namespace App\Service;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
class MailService {
    
    private $fromMail;
    private $fromName;
    private $mailer;
    private $mailCopy;
    private $returnPath;
    
    public function __construct(string $fromMail, string $fromName, string $returnPath, string $mailCopy, MailerInterface $mailer) {
        $this->fromMail = $fromMail;
        $this->fromName = $fromName;
        $this->mailer = $mailer;
        $this->returnPath = $returnPath;
        $this->mailCopy = $mailCopy;
    }
    
    public function sendTemplatedMail(string $to, string $subject, string $template, array $parameter = [])
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromMail, $this->fromName))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($parameter)
        ;
        $this->mailer->send($email);
    }
    
    public function sendHTMLMail(string $to, string $subject, string $body, array $attachments = [])
    {
        $email = (new Email())
            ->from(new Address($this->fromMail, $this->fromName))
            ->to(new Address($to))
            ->subject($subject)
            ->html($body)
        ;
        
        if($this->returnPath != $this->fromMail) {
            $email->replyTo(new Address($this->returnPath));
        }
        
        if($this->mailCopy == 'true') {
            $email->bcc(new Address($this->fromMail));
        }
        
        /* @var $attachment \App\Entity\MailAttachment */
        foreach($attachments as $attachment) {
            $email->attach(
                    $attachment->getBody(), 
                    $attachment->getName(), 
                    $attachment->getContentType()
                );
        }
        $this->mailer->send($email);
    }
}
