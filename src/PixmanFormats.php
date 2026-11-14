<?php

namespace Jovian\Rendering\Pixman;

use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\Endianness;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;
use Surface\VelvetGE\Framebuffers\Layout;

/**
 * The pixman format whose memory is VelvetGE's bytes for a FormatSpec, byte for byte, on a
 * little-endian host (pixman keeps a pixel as one host-order word).
 *
 * VelvetGE stores 32-bit pixels in channel order and 24-bit pixels R G B (or B G R), whatever
 * the endianness; 565 as a 16-bit word in the spec's endianness (MSB unless it says LSB). A
 * little-endian word read back as named by pixman (high channel first) gives:
 *
 *     bytes R G B A -> a8b8g8r8    bytes B G R A -> a8r8g8b8
 *     bytes A R G B -> b8g8r8a8    bytes A B G R -> r8g8b8a8
 *     bytes R G B   -> b8g8r8      bytes B G R   -> r8g8b8
 *     565 LSB, RGB  -> r5g6b5      565 LSB, BGR  -> b5g6r5
 *
 * Anything else (mono, palettes, planar, 444, 666, big-endian 565, bottom-up rows, a
 * big-endian host) has no pixman layout: null.
 */
final class PixmanFormats
{
    public static function code(FormatSpec $spec): ?int
    {
        if (pack('S', 1) !== "\x01\x00" || $spec->pixel_format !== PixelFormat::ROW_MAJOR || $spec->scan_direction !== ScanDirection::TOP_TO_BOTTOM) {
            return null;
        }
        try {
            $layout = Layout::of($spec);
        } catch (FramebufferException) {
            return null;
        }
        $order = Layout::channelOrder($spec);

        return match ($layout) {
            Layout::RGBA8888 => match ($order) {
                ChannelOrder::RGBA => PIXMAN_a8b8g8r8,
                ChannelOrder::BGRA => PIXMAN_a8r8g8b8,
                ChannelOrder::ARGB => PIXMAN_b8g8r8a8,
                ChannelOrder::ABGR => PIXMAN_r8g8b8a8,
                default => null,
            },
            Layout::RGB888 => $order === ChannelOrder::BGR ? PIXMAN_r8g8b8 : PIXMAN_b8g8r8,
            Layout::RGB565 => ($spec->endianness ?? Endianness::MSB) === Endianness::LSB
                ? ($order === ChannelOrder::BGR ? PIXMAN_b5g6r5 : PIXMAN_r5g6b5)
                : null,
            default => null,
        };
    }

    public static function bytesPerPixel(int $code): int
    {
        return ($code >> 24) >> 3;
    }

    /** Layouts that reach RGBA8 by moving bytes alone: 32 and 24 bits. */
    public static function lossless(int $code): bool
    {
        return self::bytesPerPixel($code) >= 3;
    }
}
