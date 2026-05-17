<?php

declare(strict_types=1);

/**
 * Test bootstrap for the plugin's own PHPUnit suite.
 *
 * The plugin's runtime dependency is the Phlex server, which provides
 * `Phlex\Plugins\Contract\LifecycleInterface`. In an installed plugin
 * (`var/plugins/phlex-plugin-example/`) that interface is resolved by
 * the host application's autoloader. When the plugin is tested in
 * isolation — `composer install && vendor/bin/phpunit` from this
 * repo — the host isn't on the classpath, so we declare a minimal stub
 * here that matches the published shape.
 *
 * The stub is only registered when the real interface is absent, so
 * downstream integration tests (e.g. the server-side
 * `SamplePluginSmokeTest` in `detain/phlex`) still resolve the real
 * contract.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!interface_exists(\Phlex\Plugins\Contract\LifecycleInterface::class)) {
    require __DIR__ . '/../dev-stubs/LifecycleInterface.php';
}
