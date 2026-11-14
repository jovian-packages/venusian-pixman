<?php

namespace Jovian\Rendering\Pixman\Providers;

use Jovian\Rendering\Pixman\PixmanFramebufferDriver;
use ReflectionException;
use Surface\Rendering\Framebuffers;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianPixmanServiceProvider extends ServiceProvider
{
    /**
     * Registers 'pixman' on Topside's framebuffer manager.
     *
     * @throws ReflectionException
     */
    public function boot(): void
    {
        Framebuffers::manager()->extend('pixman', fn () => new PixmanFramebufferDriver());
    }
}
