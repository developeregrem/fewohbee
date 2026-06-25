<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppSettings;
use App\Repository\AppSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\Transport\Dsn;

class AppSettingsService
{
    private ?AppSettings $cachedSettings = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppSettingsRepository $repository,
        private readonly SmtpPasswordCrypto $smtpPasswordCrypto,
        private readonly string $fromMail,
        private readonly string $fromName,
        private readonly string $returnPath,
        private readonly string $mailCopy,
        private readonly string $mailerDsn
    ) {
    }

    /** Return the singleton settings row and create a default row on first access. */
    public function getSettings(): AppSettings
    {
        if ($this->cachedSettings instanceof AppSettings) {
            return $this->cachedSettings;
        }

        $settings = $this->repository->findSingleton();
        if ($settings instanceof AppSettings) {
            if ($this->applyLegacyMailDefaultsIfEmpty($settings)) {
                $this->em->flush();
            }

            $this->cachedSettings = $settings;

            return $this->cachedSettings;
        }

        $settings = new AppSettings();
        $this->applyLegacyMailDefaultsIfEmpty($settings);
        $this->em->persist($settings);
        $this->em->flush();

        $this->cachedSettings = $settings;

        return $this->cachedSettings;
    }

    public function saveSettings(AppSettings $settings): void
    {
        $this->normalizeMailSettings($settings);
        $this->em->persist($settings);
        $this->em->flush();
        $this->cachedSettings = $settings;
    }

    /** Resolve the effective notification email address (explicit setting or configured sender fallback). */
    public function getNotificationEmail(?AppSettings $settings = null): ?string
    {
        $settings ??= $this->getSettings();
        $email = $settings->getNotificationEmail();

        return (null !== $email && '' !== $email) ? $email : $this->getEffectiveMailFromEmail($settings);
    }

    public function getEffectiveMailFromEmail(?AppSettings $settings = null): ?string
    {
        $settings ??= $this->getSettings();

        return $settings->getMailFromEmail() ?: $this->fromMail;
    }

    public function getEffectiveMailFromName(?AppSettings $settings = null): string
    {
        $settings ??= $this->getSettings();

        return $settings->getMailFromName() ?: $this->fromName;
    }

    public function getEffectiveMailReturnPath(?AppSettings $settings = null): ?string
    {
        $settings ??= $this->getSettings();

        return $settings->getMailReturnPath() ?: $this->returnPath;
    }

    public function isEffectiveMailCopyEnabled(?AppSettings $settings = null): bool
    {
        $settings ??= $this->getSettings();

        return $settings->getMailCopy() ?? filter_var($this->mailCopy, \FILTER_VALIDATE_BOOLEAN);
    }

    private function applyLegacyMailDefaultsIfEmpty(AppSettings $settings): bool
    {
        if (null !== $settings->getMailFromEmail()) {
            return false;
        }

        if (!$this->hasLegacyMailDefaults()) {
            return false;
        }

        $settings
            ->setMailFromEmail($this->fromMail)
            ->setMailFromName($this->fromName)
            ->setMailReturnPath($this->returnPath)
            ->setMailCopy(filter_var($this->mailCopy, \FILTER_VALIDATE_BOOLEAN));

        $this->applyLegacyMailerDsn($settings);
        $this->normalizeMailSettings($settings);

        return true;
    }

    private function hasLegacyMailDefaults(): bool
    {
        if ('' !== trim($this->fromMail) || '' !== trim($this->fromName) || '' !== trim($this->returnPath)) {
            return true;
        }

        try {
            $dsn = Dsn::fromString($this->mailerDsn);
        } catch (\Throwable) {
            return false;
        }

        return \in_array($dsn->getScheme(), ['smtp', 'smtps'], true);
    }

    private function applyLegacyMailerDsn(AppSettings $settings): void
    {
        if ('' === trim($this->mailerDsn)) {
            return;
        }

        try {
            $dsn = Dsn::fromString($this->mailerDsn);
        } catch (\Throwable) {
            return;
        }

        $scheme = $dsn->getScheme();
        if ('null' === $scheme) {
            return;
        }

        if (!\in_array($scheme, ['smtp', 'smtps'], true)) {
            return;
        }

        $settings
            ->setSmtpHost($dsn->getHost())
            ->setSmtpPort($dsn->getPort())
            ->setSmtpEncryption($this->resolveLegacySmtpEncryption($dsn))
            ->setSmtpUsername($dsn->getUser());

        $password = $dsn->getPassword();
        if (null !== $password && '' !== $password) {
            $settings->setSmtpPasswordEncrypted($this->smtpPasswordCrypto->encrypt($password));
        }
    }

    private function resolveLegacySmtpEncryption(Dsn $dsn): string
    {
        if ('smtps' === $dsn->getScheme()) {
            return 'ssl';
        }

        if (false === filter_var($dsn->getOption('auto_tls', true), \FILTER_VALIDATE_BOOLEAN)) {
            return 'none';
        }

        return 'starttls';
    }

    private function normalizeMailSettings(AppSettings $settings): void
    {
        if (null !== $settings->getSmtpHost() && null === $settings->getSmtpEncryption()) {
            $settings->setSmtpEncryption('starttls');
        }
    }
}
