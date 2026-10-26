<?php

declare(strict_types=1);

namespace Tests;

use Tulpar\Plugin\PluginContext;

/** In-memory context: one user, settings, per-user storage and a fixed clock. */
final class FakeContext implements PluginContext
{
    /** @var array<string, array<string, mixed>> */
    public array $data = [];

    /**
     * @param array<string, array{id: string, displayName: string, email: string, timezone: ?string, locale: string, isDeactivated: bool}> $users
     * @param array<string, string> $settings
     */
    public function __construct(
        private array $users,
        private array $settings,
        private \DateTimeImmutable $now = new \DateTimeImmutable('2026-10-26 10:00:00'),
    ) {}

    public function pluginId(): string
    {
        return 'com.tulpar.google-meet';
    }

    public function storage(): object
    {
        $data = &$this->data;

        return new class ($data) {
            /** @param array<string, array<string, mixed>> $data */
            public function __construct(private array &$data) {}

            public function forUser(string $userId): object
            {
                $data = &$this->data;

                return new class ($data, $userId) {
                    /** @param array<string, array<string, mixed>> $data */
                    public function __construct(private array &$data, private string $userId) {}

                    public function get(string $key, mixed $default = null): mixed
                    {
                        return $this->data[$this->userId][$key] ?? $default;
                    }

                    public function set(string $key, mixed $value): void
                    {
                        $this->data[$this->userId][$key] = $value;
                    }
                };
            }
        };
    }

    public function users(): object
    {
        $users = $this->users;

        return new class ($users) {
            /** @param array<string, array<string, mixed>> $users */
            public function __construct(private array $users) {}

            /** @return array<string, mixed>|null */
            public function get(string $userId): ?array
            {
                return $this->users[$userId] ?? null;
            }
        };
    }

    public function settings(): object
    {
        $settings = $this->settings;

        return new class ($settings) {
            /** @param array<string, string> $settings */
            public function __construct(private array $settings) {}

            public function get(string $key, ?string $default = null): ?string
            {
                return $this->settings[$key] ?? $default;
            }
        };
    }

    public function clock(): object
    {
        $now = $this->now;

        return new class ($now) {
            public function __construct(private \DateTimeImmutable $now) {}

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}
