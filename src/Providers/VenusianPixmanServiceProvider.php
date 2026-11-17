<?php

namespace Jovian\Rendering\Pixman\Providers;

use Jovian\Rendering\Pixman\Framebuffers\VelvetPixmanFramebufferDriver;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianPixmanServiceProvider extends ServiceProvider
{
    public function register(): void
    {

    }

    public function boot(): void
    {
        $this->registerVelvetDrivers();
    }

    protected function registerVelvetDrivers(): void
    {
        $this->registerVelvetFramebufferDriver();
    }

    /** 'pixman' on Velvet's framebuffers. */
    protected function registerVelvetFramebufferDriver(): void
    {
        app('velvet.framebuffers')->extend('pixman', fn () => new VelvetPixmanFramebufferDriver());
    }
}
