<?php

declare(strict_types=1);

namespace Tulpar\Plugin;

/**
 * Stand-in for the interface Tulpar hands to plugins at runtime, so the plugin
 * can be tested without a Tulpar checkout. Only the shape matters here; Tulpar
 * provides the real implementation (see docs/plugin-api.md in Tulpar).
 */
interface PluginContext
{
    public function pluginId(): string;

    public function storage(): object;

    public function users(): object;

    public function settings(): object;

    public function clock(): object;
}
