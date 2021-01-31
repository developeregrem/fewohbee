<?php

namespace App\Service;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

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
    
    public function __construct(string $fromMail, string $fromName, MailerInterface $mailer) {
        $this->fromMail = $fromMail;
        $this->fromName = $fromName;
        $this->mailer = $mailer;
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
}
