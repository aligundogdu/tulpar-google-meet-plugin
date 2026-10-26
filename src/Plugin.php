<?php

declare(strict_types=1);

namespace TulparPlugin\GoogleMeet;

use Tulpar\Plugin\PluginContext;

/**
 * The Meet button in the message box: creates a new Meet space on behalf of
 * the person who pressed it and puts the link in the box.
 *
 * Nobody signs in to Google. A Workspace admin grants a service account
 * domain-wide delegation for meetings.space.created, and the plugin acts as
 * the person through their Tulpar email address.
 */
class Plugin
{
    private const SCOPE = 'https://www.googleapis.com/auth/meetings.space.created';
    private const SPACES_URL = 'https://meet.googleapis.com/v2/spaces';
    private const TOKEN_KEY = 'accessToken';
    // Do not use a token that is about to expire
    private const TOKEN_MARGIN_SECONDS = 120;

    /** @var callable(string, string, array<string, string>, string): array{status: int, body: string} */
    private $http;

    private const MESSAGES = [
        'not_configured' => [
            'en' => 'Google Meet is not set up yet: an admin needs to paste the service account key in the plugin settings.',
            'tr' => 'Google Meet henüz kurulmadı: bir yöneticinin eklenti ayarlarına servis hesabı anahtarını girmesi gerekiyor.',
        ],
        'bad_key' => [
            'en' => 'The service account private key could not be read.',
            'tr' => 'Servis hesabının özel anahtarı okunamadı.',
        ],
        'delegation' => [
            'en' => 'Google refused the service account. Check domain-wide delegation and the meetings.space.created scope.',
            'tr' => 'Google servis hesabını reddetti. Alan genelinde yetkiyi ve meetings.space.created kapsamını kontrol edin.',
        ],
        'meet_failed' => [
            'en' => 'Google Meet could not create a meeting.',
            'tr' => 'Google Meet toplantı oluşturamadı.',
        ],
    ];

    private string $locale = 'en';

    /**
     * @param (callable(string, string, array<string, string>, string): array{status: int, body: string})|null $http
     *        For tests; when omitted, real requests go out through PHP streams
     */
    public function __construct(
        private PluginContext $ctx,
        ?callable $http = null,
    ) {
        $this->http = $http ?? [self::class, 'request'];
    }

    public function getInsertContent(string $userId): string
    {
        $user = $this->ctx->users()->get($userId);
        if ($user === null) {
            throw new \RuntimeException('User not found');
        }
        $this->locale = str_starts_with($user['locale'], 'tr') ? 'tr' : 'en';

        $space = $this->call('POST', self::SPACES_URL, $this->accessToken($userId, $user['email']), '{}');
        $uri = $space['meetingUri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            throw $this->failure('meet_failed');
        }

        return '📹 Google Meet: ' . $uri;
    }

    private function accessToken(string $userId, string $email): string
    {
        $store = $this->ctx->storage()->forUser($userId);
        $now = $this->ctx->clock()->now()->getTimestamp();

        $cached = $store->get(self::TOKEN_KEY);
        if (is_array($cached) && is_string($cached['token'] ?? null) && ($cached['expiresAt'] ?? 0) - self::TOKEN_MARGIN_SECONDS > $now) {
            return $cached['token'];
        }

        $account = $this->serviceAccount();
        $assertion = $this->signedAssertion($account, $email, $now);
        $response = ($this->http)('POST', $account['token_uri'], ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]));

        $body = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($body) || !is_string($body['access_token'] ?? null)) {
            $reason = is_array($body) ? (string) ($body['error_description'] ?? $body['error'] ?? '') : '';
            throw $this->failure('delegation', $reason);
        }

        $token = $body['access_token'];
        $store->set(self::TOKEN_KEY, ['token' => $token, 'expiresAt' => $now + (int) ($body['expires_in'] ?? 3600)]);

        return $token;
    }

    /** @return array{client_email: string, private_key: string, token_uri: string} */
    private function serviceAccount(): array
    {
        $raw = $this->ctx->settings()->get('serviceAccount');
        $account = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($account) || !is_string($account['client_email'] ?? null) || !is_string($account['private_key'] ?? null)) {
            throw $this->failure('not_configured');
        }

        return [
            'client_email' => $account['client_email'],
            'private_key' => $account['private_key'],
            'token_uri' => is_string($account['token_uri'] ?? null) ? $account['token_uri'] : 'https://oauth2.googleapis.com/token',
        ];
    }

    /** @param array{client_email: string, private_key: string, token_uri: string} $account */
    private function signedAssertion(array $account, string $email, int $now): string
    {
        $encode = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

        $header = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $encode((string) json_encode([
            'iss' => $account['client_email'],
            'sub' => $email,
            'scope' => self::SCOPE,
            'aud' => $account['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $key = openssl_pkey_get_private($account['private_key']);
        if ($key === false || !openssl_sign("{$header}.{$claims}", $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw $this->failure('bad_key');
        }

        return "{$header}.{$claims}." . $encode($signature);
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $url, string $token, string $body): array
    {
        $response = ($this->http)($method, $url, ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'], $body);
        $data = json_decode($response['body'], true);

        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($data)) {
            $reason = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            throw $this->failure('meet_failed', $reason !== '' ? $reason : "HTTP {$response['status']}");
        }

        return $data;
    }

    /** A message in the person's language; Google's detail stays in brackets. */
    private function failure(string $key, string $detail = ''): \RuntimeException
    {
        $message = self::MESSAGES[$key][$this->locale];

        return new \RuntimeException($detail !== '' ? "{$message} ({$detail})" : $message);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public static function request(string $method, string $url, array $headers, string $body): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);

        $result = @file_get_contents($url, false, $context);
        $status = 0;
        $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : [];
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => $result === false ? '' : $result];
    }
}
