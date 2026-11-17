<?php

namespace Jovian\Rendering\Pixman\Framebuffers;

use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Rendering\Framebuffers\FramebufferDriver;

/** pixman as a Velvet framebuffer module: every make() is one PixmanFramebufferImage. */
class VelvetPixmanFramebufferDriver extends FramebufferDriver
{
    /** @throws FramebufferException When ext-pixman is not loaded, or is older than pixman_image_get_data() returning the address by default (its 0.11.0). */
    public function __construct()
    {
        if (! extension_loaded('pixman')) {
            throw FramebufferException::extensionMissing('pixman', '0.11.0');
        }
        if ((new \ReflectionFunction('pixman_image_get_data'))->getNumberOfParameters() < 2) {
            throw FramebufferException::extensionStale('pixman', '0.11.0', ['pixman_image_get_data(copy:)']);
        }
    }

    public function driver(): string
    {
        return 'pixman';
    }

    /** @throws FramebufferException When pixman has no format with $format's byte layout. */
    public function make(FormatSpec $format, int $width, int $height): PixmanFramebufferImage
    {
        return new PixmanFramebufferImage($format, $width, $height);
    }
}
