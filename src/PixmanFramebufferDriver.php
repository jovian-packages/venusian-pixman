<?php

namespace Jovian\Rendering\Pixman;

use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\PixelStore;
use Surface\VelvetGE\Drivers\Framebuffers\CLandFramebufferDriver;
use Surface\VelvetGE\Drivers\Framebuffers\StoreFramebufferDriver;
use Surface\VelvetGE\Framebuffers\PHPLand\PHPLandPixelStore;

/**
 * The 'pixman' framebuffer driver: pixels in pixman images through ext-pixman.
 *
 * A format pixman can hold byte for byte (PixmanFormats) gets a PixmanPixelStore; any other
 * (mono, palettes, planar, 444, 666, big-endian 565, bottom-up rows) gets the store VelvetGE's
 * 'auto' would give it, ext-fb's when that is loaded and PHP's otherwise, so this driver holds
 * every format VelvetGE does and is safe first in 'auto'.
 */
class PixmanFramebufferDriver extends StoreFramebufferDriver
{
    /** The ext-pixman release this driver is written against: pixman_image_get_data_address() arrived in it. */
    public const string MINIMUM = '0.11.0';

    public static function available(): bool
    {
        return extension_loaded('pixman')
            && version_compare((string) phpversion('pixman'), self::MINIMUM, '>=')
            && function_exists('pixman_image_get_data_address');
    }

    /** @throws FramebufferException When this PHP has no ext-pixman of at least MINIMUM. */
    public function __construct()
    {
        if (! extension_loaded('pixman') || version_compare((string) phpversion('pixman'), self::MINIMUM, '<')) {
            throw FramebufferException::extensionMissing('pixman', self::MINIMUM);
        }
        if (! function_exists('pixman_image_get_data_address')) {
            throw FramebufferException::extensionStale('pixman', self::MINIMUM, ['pixman_image_get_data_address']);
        }
    }

    public function driver(): string
    {
        return 'pixman';
    }

    public function mint(FormatSpec $format, int $width, int $height): PixelStore
    {
        if (PixmanFormats::code($format) !== null) {
            return new PixmanPixelStore($format, $width, $height);
        }

        return CLandFramebufferDriver::available()
            ? (new CLandFramebufferDriver())->mint($format, $width, $height)
            : new PHPLandPixelStore($format, $width, $height);
    }
}
