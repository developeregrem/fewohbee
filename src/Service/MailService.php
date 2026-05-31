<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppSettings;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\HtmlToTextConverter\DefaultHtmlToTextConverter;

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
class MailService
{
    private readonly DefaultHtmlToTextConverter $htmlToTextConverter;

    public function __construct(
        private readonly AppSettingsService $appSettingsService,
        private readonly MailTransportFactory $mailTransportFactory,
        private readonly bool $mailEnabled
    ) {
        $this->htmlToTextConverter = new DefaultHtmlToTextConverter();
    }

    public function isMailEnabled(): bool
    {
        return $this->mailEnabled;
    }

    public function sendTemplatedMail(string $to, string $subject, string $template, array $parameter = []): void
    {
        if (!$this->mailEnabled) {
            return;
        }

        $settings = $this->appSettingsService->getSettings();
        $fromMail = $this->appSettingsService->getEffectiveMailFromEmail($settings);
        if (null === $fromMail || '' === trim($fromMail)) {
            return;
        }

        $email = new TemplatedEmail()
            ->from(new Address($fromMail, $this->appSettingsService->getEffectiveMailFromName($settings)))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($parameter)
        ;

        $this->applyEnvelopeSettings($email, $settings, $fromMail);
        $this->mailTransportFactory->createMailer($settings)->send($email);
    }

    public function sendHTMLMail(string $to, string $subject, string $body, array $attachments = []): void
    {
        if (!$this->mailEnabled) {
            return;
        }

        $settings = $this->appSettingsService->getSettings();
        $fromMail = $this->appSettingsService->getEffectiveMailFromEmail($settings);
        if (null === $fromMail || '' === trim($fromMail)) {
            return;
        }

        $email = new Email()
            ->from(new Address($fromMail, $this->appSettingsService->getEffectiveMailFromName($settings)))
            ->to(new Address($to))
            ->subject($subject)
            ->html($body)
            ->text($this->htmlToTextConverter->convert($body, 'utf-8'))
        ;

        $this->applyEnvelopeSettings($email, $settings, $fromMail);

        /* @var $attachment \App\Entity\MailAttachment */
        foreach ($attachments as $attachment) {
            $email->attach(
                $attachment->getBody(),
                $attachment->getName(),
                $attachment->getContentType()
            );
        }
        $this->mailTransportFactory->createMailer($settings)->send($email);
    }

    private function applyEnvelopeSettings(Email $email, AppSettings $settings, string $fromMail): void
    {
        $returnPath = $this->appSettingsService->getEffectiveMailReturnPath($settings);
        if (null !== $returnPath && '' !== trim($returnPath)) {
            $email->returnPath(new Address($returnPath));
        }

        if ($this->appSettingsService->isEffectiveMailCopyEnabled($settings)) {
            $email->bcc(new Address($fromMail));
        }
    }
}
