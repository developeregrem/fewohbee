<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppSettings;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

class MailTransportFactory
{
    public function __construct(
        private readonly SmtpPasswordCrypto $smtpPasswordCrypto,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function buildDsn(AppSettings $settings): string
    {
        $host = trim((string) $settings->getSmtpHost());
        if ('' === $host) {
            return 'null://null';
        }

        $encryption = $settings->getSmtpEncryption() ?: 'starttls';
        $scheme = 'ssl' === $encryption ? 'smtps' : 'smtp';
        $auth = $this->buildAuth($settings);
        $port = null !== $settings->getSmtpPort() ? ':'.$settings->getSmtpPort() : '';
        $query = match ($encryption) {
            'none' => '?auto_tls=false',
            'starttls' => '?require_tls=true',
            default => '',
        };

        return sprintf('%s://%s%s%s%s', $scheme, $auth, $host, $port, $query);
    }

    public function createTransport(AppSettings $settings): TransportInterface
    {
        return Transport::fromDsn($this->buildDsn($settings), $this->dispatcher, null, $this->logger);
    }

    public function createMailer(AppSettings $settings): MailerInterface
    {
        return new Mailer($this->createTransport($settings), null, $this->dispatcher);
    }

    public function testSmtpConnection(AppSettings $settings): void
    {
        $transport = $this->createTransport($settings);
        if (!$transport instanceof SmtpTransport) {
            throw new \RuntimeException('No SMTP server is configured.');
        }

        $transport->start();
        $transport->stop();
    }

    private function buildAuth(AppSettings $settings): string
    {
        $username = $settings->getSmtpUsername();
        if (null === $username || '' === trim($username)) {
            return '';
        }

        $auth = rawurlencode($username);
        $password = $this->smtpPasswordCrypto->decrypt($settings->getSmtpPasswordEncrypted());
        if (null !== $password && '' !== $password) {
            $auth .= ':'.rawurlencode($password);
        }

        return $auth.'@';
    }
}
