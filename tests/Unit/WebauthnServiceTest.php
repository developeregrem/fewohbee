<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\WebauthnCredential;
use App\Repository\WebauthnCredentialRepository;
use App\Service\WebauthnService;
use PHPUnit\Framework\TestCase;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialUserEntity;

final class WebauthnServiceTest extends TestCase
{
    private WebauthnService $service;

    protected function setUp(): void
    {
        $repository = $this->createStub(WebauthnCredentialRepository::class);
        $repository->method('findByUserHandle')->willReturn([]);

        $this->service = new WebauthnService(
            rpId: 'localhost',
            rpName: 'Test App',
            credentialRepository: $repository,
        );
    }

    public function testGenerateRegistrationOptionsReturnsValidOptions(): void
    {
        $userEntity = new PublicKeyCredentialUserEntity(
            name: 'testuser',
            id: '42',
            displayName: 'testuser',
        );

        $options = $this->service->generateRegistrationOptions($userEntity);

        self::assertInstanceOf(PublicKeyCredentialCreationOptions::class, $options);
        self::assertSame('localhost', $options->rp->id);
        self::assertSame('Test App', $options->rp->name);
        self::assertSame('testuser', $options->user->name);
        self::assertSame('42', $options->user->id);
        self::assertNotEmpty($options->challenge);
        self::assertSame(32, strlen($options->challenge));
        self::assertCount(2, $options->pubKeyCredParams);
        self::assertSame(60000, $options->timeout);
        self::assertSame('none', $options->attestation);
    }

    public function testGenerateRegistrationOptionsExcludesExistingCredentials(): void
    {
        $credential = $this->createStub(WebauthnCredential::class);
        $credential->method('getPublicKeyCredentialId')->willReturn(base64_encode('existing-cred-id'));
        $credential->method('getTransports')->willReturn(['internal']);

        $repository = $this->createStub(WebauthnCredentialRepository::class);
        $repository->method('findByUserHandle')->willReturn([$credential]);

        $service = new WebauthnService('localhost', 'Test App', $repository);

        $userEntity = new PublicKeyCredentialUserEntity(
            name: 'testuser',
            id: '42',
            displayName: 'testuser',
        );

        $options = $service->generateRegistrationOptions($userEntity);

        self::assertCount(1, $options->excludeCredentials);
        self::assertSame('existing-cred-id', $options->excludeCredentials[0]->id);
        self::assertSame(['internal'], $options->excludeCredentials[0]->transports);
    }

    public function testGenerateAuthenticationOptionsWithoutUser(): void
    {
        $options = $this->service->generateAuthenticationOptions();

        self::assertInstanceOf(PublicKeyCredentialRequestOptions::class, $options);
        self::assertSame('localhost', $options->rpId);
        self::assertNotEmpty($options->challenge);
        self::assertSame(32, strlen($options->challenge));
        self::assertEmpty($options->allowCredentials);
        self::assertSame(60000, $options->timeout);
    }

    public function testGenerateAuthenticationOptionsWithUserRestrictsCredentials(): void
    {
        $credential = $this->createStub(WebauthnCredential::class);
        $credential->method('getPublicKeyCredentialId')->willReturn(base64_encode('cred-id'));
        $credential->method('getTransports')->willReturn(['usb', 'ble']);

        $repository = $this->createStub(WebauthnCredentialRepository::class);
        $repository->method('findByUserHandle')->willReturn([$credential]);

        $service = new WebauthnService('localhost', 'Test App', $repository);
        $options = $service->generateAuthenticationOptions('42');

        self::assertCount(1, $options->allowCredentials);
        self::assertSame('cred-id', $options->allowCredentials[0]->id);
        self::assertSame(['usb', 'ble'], $options->allowCredentials[0]->transports);
    }

    public function testSerializeAndDeserializeCreationOptionsRoundTrip(): void
    {
        $userEntity = new PublicKeyCredentialUserEntity(
            name: 'testuser',
            id: '42',
            displayName: 'testuser',
        );

        $options = $this->service->generateRegistrationOptions($userEntity);
        $json = $this->service->serializeOptions($options);

        self::assertJson($json);

        $decoded = json_decode($json, true);
        self::assertArrayHasKey('rp', $decoded);
        self::assertArrayHasKey('user', $decoded);
        self::assertArrayHasKey('challenge', $decoded);
        self::assertArrayHasKey('pubKeyCredParams', $decoded);

        $restored = $this->service->deserializeCreationOptions($json);

        self::assertInstanceOf(PublicKeyCredentialCreationOptions::class, $restored);
        self::assertSame($options->rp->id, $restored->rp->id);
        self::assertSame($options->user->id, $restored->user->id);
        self::assertSame($options->challenge, $restored->challenge);
    }

    public function testSerializeAndDeserializeRequestOptionsRoundTrip(): void
    {
        $options = $this->service->generateAuthenticationOptions();
        $json = $this->service->serializeOptions($options);

        self::assertJson($json);

        $restored = $this->service->deserializeRequestOptions($json);

        self::assertInstanceOf(PublicKeyCredentialRequestOptions::class, $restored);
        self::assertSame($options->rpId, $restored->rpId);
        self::assertSame($options->challenge, $restored->challenge);
    }

    public function testEachCallGeneratesUniqueChallenge(): void
    {
        $options1 = $this->service->generateAuthenticationOptions();
        $options2 = $this->service->generateAuthenticationOptions();

        self::assertNotSame($options1->challenge, $options2->challenge);
    }
}
