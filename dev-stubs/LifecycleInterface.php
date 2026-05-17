<?php

declare(strict_types=1);

namespace Phlex\Plugins\Contract;

use Psr\Container\ContainerInterface;

/**
 * Dev-only stub of the host server's lifecycle contract.
 *
 * Loaded by `tests/bootstrap.php` when the plugin is tested outside a
 * Phlex server checkout. Keep this file byte-compatible with the
 * canonical definition in `detain/phlex` at
 * `src/Plugins/Contract/LifecycleInterface.php`.
 *
 * @internal Tests only — never autoloaded into production.
 */
interface LifecycleInterface
{
    public function onEnable(ContainerInterface $container): void;

    public function onDisable(): void;

    /**
     * @return array<class-string, string|callable>
     */
    public function subscribedEvents(): array;
}
