<?php

declare(strict_types=1);

use Jovian\Rendering\Pixman\PixmanFramebufferDriver;
use Jovian\Rendering\Pixman\PixmanPixelStore;
use Venusian\Topside\Tests\Support\Framebuffers\FixtureRunner;

it('passes every golden fixture on pixman within its tolerance', function (string $file): void {
    FixtureRunner::run(new PixmanFramebufferDriver(), $file, tolerance: PixmanPixelStore::TOLERANCE);
})->with(FixtureRunner::files());
