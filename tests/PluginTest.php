<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use TulparPlugin\GoogleMeet\Plugin;

/** Google is faked; the JWT is checked against the generated public key. */
final class PluginTest extends TestCase
{
    private const USER = 'user-1';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];

    private string $publicKey = '';

    public function testCreatesALinkAsThePersonAndReusesTheToken(): void
    {
        $meet = new Plugin($this->context(), $this->google());

        self::assertSame('📹 Google Meet: https://meet.google.com/abc-defg-hij', $meet->getInsertContent(self::USER));
        self::assertSame('📹 Google Meet: https://meet.google.com/abc-defg-hij', $meet->getInsertContent(self::USER));

        $tokenCalls = array_values(array_filter($this->requests, fn ($r) => str_contains($r['url'], '/token')));
        self::assertCount(1, $tokenCalls, 'the access token is cached between clicks');

        parse_str($tokenCalls[0]['body'], $form);
        [$header, $claims, $signature] = explode('.', (string) $form['assertion']);
        $decoded = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);
        self::assertSame('ayse@example.com', $decoded['sub']);
        self::assertSame('meet@project.iam.gserviceaccount.com', $decoded['iss']);
        self::assertSame('https://www.googleapis.com/auth/meetings.space.created', $decoded['scope']);
        self::assertSame(1, openssl_verify("{$header}.{$claims}", base64_decode(strtr($signature, '-_', '+/')), $this->publicKey, OPENSSL_ALGO_SHA256));

        $spaceCalls = array_values(array_filter($this->requests, fn ($r) => str_contains($r['url'], '/v2/spaces')));
        self::assertCount(2, $spaceCalls);
        self::assertSame('Bearer fake-token', $spaceCalls[0]['headers']['Authorization']);
    }

    public function testAsksForSetupWhenTheKeyIsMissing(): void
    {
        $meet = new Plugin($this->context(withKey: false), $this->google());

        $this->expectExceptionMessage('an admin needs to paste the service account key');
        $meet->getInsertContent(self::USER);
    }

    public function testSpeaksThePersonsLanguage(): void
    {
        $meet = new Plugin($this->context(withKey: false, locale: 'tr'), $this->google());

        $this->expectExceptionMessage('Google Meet henüz kurulmadı');
        $meet->getInsertContent(self::USER);
    }

    public function testExplainsARefusedDelegation(): void
    {
        $meet = new Plugin($this->context(), fn () => [
            'status' => 401,
            'body' => '{"error":"unauthorized_client","error_description":"Client is unauthorized to retrieve access tokens using this method"}',
        ]);

        $this->expectExceptionMessage('Check domain-wide delegation and the meetings.space.created scope');
        $meet->getInsertContent(self::USER);
    }

    private function context(bool $withKey = true, string $locale = 'en'): FakeContext
    {
        $settings = [];
        if ($withKey) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertNotFalse($key);
            openssl_pkey_export($key, $privatePem);
            $this->publicKey = (string) openssl_pkey_get_details($key)['key'];
            $settings['serviceAccount'] = (string) json_encode([
                'type' => 'service_account',
                'client_email' => 'meet@project.iam.gserviceaccount.com',
                'private_key' => $privatePem,
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ]);
        }

        return new FakeContext([
            self::USER => [
                'id' => self::USER,
                'displayName' => 'Ayşe',
                'email' => 'ayse@example.com',
                'timezone' => 'Europe/Istanbul',
                'locale' => $locale,
                'isDeactivated' => false,
            ],
        ], $settings);
    }

    private function google(): callable
    {
        return function (string $method, string $url, array $headers, string $body): array {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            if (str_contains($url, '/token')) {
                return ['status' => 200, 'body' => '{"access_token":"fake-token","expires_in":3599,"token_type":"Bearer"}'];
            }

            return ['status' => 200, 'body' => '{"name":"spaces/x","meetingUri":"https://meet.google.com/abc-defg-hij","meetingCode":"abc-defg-hij"}'];
        };
    }
}
