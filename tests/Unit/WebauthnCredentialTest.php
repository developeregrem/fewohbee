<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\WebauthnCredential;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

final class WebauthnCredentialTest extends TestCase
{
    private function createCredentialRecord(): CredentialRecord
    {
        return new CredentialRecord(
            publicKeyCredentialId: 'binary-credential-id',
            type: 'public-key',
            transports: ['internal', 'hybrid'],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: 'binary-public-key-data',
            userHandle: '42',
            counter: 5,
            otherUI: null,
            backupEligible: true,
            backupStatus: false,
            uvInitialized: true,
        );
    }

    public function testFromCredentialRecordCreatesEntityWithCorrectValues(): void
    {
        $record = $this->createCredentialRecord();
        $entity = WebauthnCredential::fromCredentialRecord($record);

        // Binary fields are base64-encoded for storage
        self::assertSame(base64_encode('binary-credential-id'), $entity->getPublicKeyCredentialId());
        self::assertSame(base64_encode('binary-public-key-data'), $entity->getCredentialPublicKey());

        self::assertSame('public-key', $entity->getType());
        self::assertSame(['internal', 'hybrid'], $entity->getTransports());
        self::assertSame('42', $entity->getUserHandle());
    }

    public function testFromCredentialRecordGeneratesUlidAndTimestamp(): void
    {
        $record = $this->createCredentialRecord();
        $entity = WebauthnCredential::fromCredentialRecord($record);

        self::assertNotEmpty($entity->getId());
        self::assertSame(26, strlen($entity->getId()), 'ULID should be 26 characters');
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
    }

    public function testToCredentialRecordDecodesBase64Fields(): void
    {
        $record = $this->createCredentialRecord();
        $entity = WebauthnCredential::fromCredentialRecord($record);
        $restored = $entity->toCredentialRecord();

        // Binary fields should be decoded back
        self::assertSame('binary-credential-id', $restored->publicKeyCredentialId);
        self::assertSame('binary-public-key-data', $restored->credentialPublicKey);

        self::assertSame('public-key', $restored->type);
        self::assertSame(['internal', 'hybrid'], $restored->transports);
        self::assertSame('none', $restored->attestationType);
        self::assertSame('42', $restored->userHandle);
        self::assertSame(5, $restored->counter);
        self::assertTrue($restored->backupEligible);
        self::assertFalse($restored->backupStatus);
        self::assertTrue($restored->uvInitialized);
        self::assertNull($restored->otherUI);
    }

    public function testToCredentialRecordUsesEmptyTrustPath(): void
    {
        $record = $this->createCredentialRecord();
        $entity = WebauthnCredential::fromCredentialRecord($record);
        $restored = $entity->toCredentialRecord();

        self::assertInstanceOf(EmptyTrustPath::class, $restored->trustPath);
    }

    public function testRoundTripPreservesAllData(): void
    {
        $original = $this->createCredentialRecord();
        $entity = WebauthnCredential::fromCredentialRecord($original);
        $restored = $entity->toCredentialRecord();

        self::assertSame($original->publicKeyCredentialId, $restored->publicKeyCredentialId);
        self::assertSame($original->type, $restored->type);
        self::assertSame($original->transports, $restored->transports);
        self::assertSame($original->attestationType, $restored->attestationType);
        self::assertSame($original->credentialPublicKey, $restored->credentialPublicKey);
        self::assertSame($original->userHandle, $restored->userHandle);
        self::assertSame($original->counter, $restored->counter);
        self::assertSame($original->backupEligible, $restored->backupEligible);
        self::assertSame($original->backupStatus, $restored->backupStatus);
        self::assertSame($original->uvInitialized, $restored->uvInitialized);
    }

    public function testUpdateFromCredentialRecordAppliesMutableFields(): void
    {
        $record = $this->createCredentialRecord();
        $entity = WebauthnCredential::fromCredentialRecord($record);

        $updated = new CredentialRecord(
            publicKeyCredentialId: 'binary-credential-id',
            type: 'public-key',
            transports: ['internal'],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: 'binary-public-key-data',
            userHandle: '42',
            counter: 10,
            otherUI: null,
            backupEligible: true,
            backupStatus: true,
            uvInitialized: true,
        );

        $entity->updateFromCredentialRecord($updated);
        $restored = $entity->toCredentialRecord();

        self::assertSame(10, $restored->counter);
        self::assertTrue($restored->backupStatus);
    }

    public function testClientLabelAndUserAgentSetters(): void
    {
        $record = $this->createCredentialRecord();
        $entity = WebauthnCredential::fromCredentialRecord($record);

        self::assertNull($entity->getClientLabel());
        self::assertNull($entity->getUserAgent());

        $entity->setClientLabel('macOS (Safari)');
        $entity->setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)');

        self::assertSame('macOS (Safari)', $entity->getClientLabel());
        self::assertSame('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', $entity->getUserAgent());
    }

    public function testTwoEntitiesGetDifferentIds(): void
    {
        $record = $this->createCredentialRecord();
        $entity1 = WebauthnCredential::fromCredentialRecord($record);
        $entity2 = WebauthnCredential::fromCredentialRecord($record);

        self::assertNotSame($entity1->getId(), $entity2->getId());
    }
}
