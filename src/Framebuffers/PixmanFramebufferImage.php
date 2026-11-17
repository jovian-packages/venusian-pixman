<?php

namespace Jovian\Rendering\Pixman\Framebuffers;

use Jovian\Rendering\Pixman\PixmanFormats;
use pixman_color;
use pixman_gradient_stop;
use pixman_image;
use pixman_point_fixed;
use pixman_transform;
use pixman_triangle;
use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\GradientSpread;
use Surface\Rendering\Framebuffers\FramebufferDelegate;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\Contracts\Framebuffers\GradientFramebuffer;
use VelvetGE\Contracts\Framebuffers\ScrollingFramebuffer;
use VelvetGE\Framebuffers\ImagePlacement;
use VelvetGE\Framebuffers\Layout;
use VelvetGE\Framebuffers\MaskList;
use VelvetGE\Framebuffers\ReadsBlitSources;
use VelvetGE\Framebuffers\SpanList;

/**
 * One pixman image, pixman's way: pixman holds the pixels and does every write, copy, conversion
 * and blend. The image's memory is the format's bytes as the framework defines them (straight
 * colours, PixmanFormats picks the pixman format with that byte layout), so reads hand back the
 * bytes as they are, and a direct drawer can paint memory() in place.
 *
 * Blends are pixman's OVER with a premultiplied source: a solid colour through coverage, a
 * gradient, or another framebuffer's pixels (premultiplied by pixman on the way in). Over a
 * translucent destination pixel pixman's arithmetic is not Lustrous's straight-alpha lerp, so
 * blended bytes land within TOLERANCE of lustrous-php; moves, fills and conversions do not blend.
 *
 * No effects (no filter chains, Kawase levels or glass) and no kinds: pixman has one image.
 */
final class PixmanFramebufferImage extends FramebufferDelegate implements CompositingFramebuffer, GradientFramebuffer, ScrollingFramebuffer, PaintTarget
{
    use ReadsBlitSources;

    /** How far a blended byte may land from lustrous-php's: 5% of 255, as ruled for library framebuffers. */
    public const int TOLERANCE = 13;

    private pixman_image $image;

    private int $code;

    private int $bytes_per_pixel;

    /**
     * @throws FramebufferException When pixman has no format with this byte layout, or cannot allocate the image.
     */
    public function __construct(
        private readonly FormatSpec $format,
        private readonly int $width,
        private readonly int $height,
    ) {
        Layout::size($width, $height);
        $this->code = PixmanFormats::code($format)
            ?? throw FramebufferException::unsupportedFormat($format, 'pixman has no format with this byte layout.');
        $this->bytes_per_pixel = PixmanFormats::bytesPerPixel($this->code);
        $this->image = pixman_image_create_bits($this->code, $width, $height, null, 0)
            ?? throw new FramebufferException("pixman could not allocate a {$width}x{$height} image.");
    }

    public function viewportWidth(): int
    {
        return $this->width;
    }

    public function viewportHeight(): int
    {
        return $this->height;
    }

    public function hostFormat(): FormatSpec
    {
        return $this->format;
    }

    /** pixman's bits: the format's bytes row by row, rows padded to 4 bytes. The pixman_image owns them. */
    public function memory(): ?PixelMemory
    {
        return new PixelMemory($this->pointer(), $this->width, $this->height, pixman_image_get_stride($this->image), $this->format, $this->image);
    }

    public function pointer(): int
    {
        return pixman_image_get_data($this->image);
    }

    public function drewInto(?Region ...$regions): static
    {
        foreach ($regions as $region) {
            $this->wrote($region);
        }

        return $this;
    }

    public function getPixel(int $x, int $y): int
    {
        $this->guard($x, $y);

        return $this->decode($this->read(new Region($x, $y, 1, 1)));
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        $this->guard($x, $y);
        $this->write(new Region($x, $y, 1, 1), $this->encode($value));

        return $this->wrote(new Region($x, $y, 1, 1));
    }

    public function setPixels(array $pixels): static
    {
        $bounds = $this->bounds($pixels, 3, '[x, y, value]');
        foreach ($pixels as [$x, $y, $value]) {
            $this->write(new Region($x, $y, 1, 1), $this->encode($value));
        }

        return $this->wrote($bounds);
    }

    public function setRegion(array $coordinates, int $value): static
    {
        $bounds = $this->bounds($coordinates, 2, '[x, y]');
        $cell = $this->encode($value);
        foreach ($coordinates as [$x, $y]) {
            $this->write(new Region($x, $y, 1, 1), $cell);
        }

        return $this->wrote($bounds);
    }

    /** A rect of one value, clipped to the surface: a rect straddling the edge is ordinary. */
    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        $region = (new Region($x, $y, max(0, $width), max(0, $height)))->intersect($this->whole());
        if (is_null($region)) {
            return $this;
        }
        $this->paintCell($region, $this->encode($color));

        return $this->wrote($region);
    }

    public function clear(): static
    {
        return $this->fill(0);
    }

    public function fill(int $color): static
    {
        $this->paintCell($this->whole(), $this->encode($color));

        return $this->wrote($this->whole());
    }

    /** RGBA8 in, through pixman's SRC: bytes move as they are, 24-bit drops alpha, 565 truncates. */
    public function writeRgba8(string $rgba8, int $width, int $height, int $x = 0, int $y = 0): static
    {
        if ($width < 1 || $height < 1 || strlen($rgba8) !== $width * $height * 4) {
            throw new FramebufferException("writeRgba8() takes {$width}x{$height} RGBA8 pixels (".(max(0, $width) * max(0, $height) * 4).' bytes), got '.strlen($rgba8).'.');
        }
        $target = (new Region($x, $y, $width, $height))->intersect($this->whole());
        if (is_null($target)) {
            return $this;
        }
        $source = pixman_image_create_bits(PIXMAN_a8b8g8r8, $width, $height, $rgba8, $width * 4);
        pixman_image_composite32(PIXMAN_OP_SRC, $source, null, $this->image, $target->x - $x, $target->y - $y, 0, 0, $target->x, $target->y, $target->width, $target->height);

        return $this->wrote($target);
    }

    public function blitTo(InstanceThatManagesAFramebuffer $target, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer
    {
        return $target->blitFrom($this, $offset_x, $offset_y);
    }

    public function blitFrom(InstanceThatManagesAFramebuffer $source, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer
    {
        if ($source instanceof self) {
            // Image to image: pixman's SRC, converting between the two formats if they differ.
            $target = (new Region($offset_x, $offset_y, $source->width, $source->height))->intersect($this->whole());
            if (is_null($target)) {
                return $this;
            }
            pixman_image_composite32(PIXMAN_OP_SRC, $source->image, null, $this->image, $target->x - $offset_x, $target->y - $offset_y, 0, 0, $target->x, $target->y, $target->width, $target->height);

            return $this->wrote($target);
        }
        $view = $this->view($source);
        if (! is_null($view)) {
            // Another library's memory, wrapped where it is: pixman's SRC converts in C.
            $target = (new Region($offset_x, $offset_y, pixman_image_get_width($view), pixman_image_get_height($view)))->intersect($this->whole());
            if (is_null($target)) {
                return $this;
            }
            pixman_image_composite32(PIXMAN_OP_SRC, $view, null, $this->image, $target->x - $offset_x, $target->y - $offset_y, 0, 0, $target->x, $target->y, $target->width, $target->height);

            return $this->wrote($target);
        }
        [$rgba8, $width, $height, $top] = $this->blitSource($source);

        return $this->writeRgba8($rgba8, $width, $height, $offset_x, $offset_y + $top);
    }

    /**
     * $source's pixels as a pixman image over its own memory, when it lends memory in a format
     * pixman holds: nothing is copied. Null sends the caller to the RGBA8 read-back.
     */
    private function view(InstanceThatManagesAFramebuffer $source): ?pixman_image
    {
        if ($source instanceof self) {
            return $source->image;
        }
        $memory = $this->sourceMemory($source);
        $code = is_null($memory) ? null : PixmanFormats::code($memory->format);
        // pixman's bits take rows on 4-byte boundaries; tighter rows go through the read-back.
        if (is_null($code) || $memory->stride % 4 !== 0) {
            return null;
        }

        return pixman_image_create_bits($code, $memory->width, $memory->height, $memory->address, $memory->stride);
    }

    /** pixman's SRC into RGBA8: 32-bit bytes move, 24-bit gains alpha 255, 565 widens by bit replication. */
    public function toRgba8(): string
    {
        return $this->convert($this->whole(), PIXMAN_a8b8g8r8);
    }

    public function dump(?int $layer = null): string
    {
        if (! is_null($layer) && $layer !== 0) {
            throw new FramebufferException("A pixman image has one layer, 0; got {$layer}.");
        }

        return $this->read($this->whole());
    }

    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        return self::answer($this->in($this->whole(), $spec), $as_array);
    }

    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array
    {
        $this->inside($region);

        return self::answer($this->in($region, $spec), $as_array);
    }

    public function scratch(int $width, int $height): InstanceThatManagesAFramebuffer
    {
        return new self(FormatSpec::rgba8(), $width, $height);
    }

    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::pixel($this->width, $this->height);
    }

    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    public function scroll(Region $region, int $dx, int $dy): void
    {
        $clip = $region->intersect($this->whole());
        $source = is_null($clip) ? null : self::scrollSource($clip, $dx, $dy);
        if (is_null($source)) {
            return;
        }
        // Through a scratch image, since the two rects may overlap: both copies are pixman's SRC.
        $held = pixman_image_create_bits($this->code, $source->width, $source->height, null, 0);
        pixman_image_composite32(PIXMAN_OP_SRC, $this->image, null, $held, $source->x, $source->y, 0, 0, 0, 0, $source->width, $source->height);
        pixman_image_composite32(PIXMAN_OP_SRC, $held, null, $this->image, 0, 0, 0, 0, $source->x + $dx, $source->y + $dy, $source->width, $source->height);
        $this->touched($clip);
    }

    public function paintSpans(string $spans, int $rgba8): static
    {
        [$list, $bounds] = SpanList::read($spans, $rgba8, $this->width, $this->height);
        if (is_null($bounds)) {
            return $this;
        }
        // One coverage mask per pass. Spans paint in list order, so a span overlapping one already
        // in the mask blends a second time: the mask so far is painted first.
        $stride = self::stride($bounds->width);
        $blank = str_repeat("\0", $stride * $bounds->height);
        $mask = $blank;
        $taken = [];
        foreach ($list as [$y, $x, $length, $coverage]) {
            foreach ($taken[$y] ?? [] as [$x0, $x1]) {
                if ($x < $x1 && $x + $length > $x0) {
                    $this->over($rgba8, $mask, $stride, $bounds);
                    $mask = $blank;
                    $taken = [];
                    break;
                }
            }
            $taken[$y][] = [$x, $x + $length];
            $mask = substr_replace($mask, str_repeat(chr($coverage), $length), ($y - $bounds->y) * $stride + $x - $bounds->x, $length);
        }
        $this->over($rgba8, $mask, $stride, $bounds);

        return $this->wrote($bounds);
    }

    public function paintMasks(array $masks, int $rgba8, Region $clip): static
    {
        MaskList::check($masks, $rgba8, $clip, $this->width, $this->height);
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        foreach ($masks as [$coverage, $width, $mx, $my]) {
            $y0 = max($my, $clip->y);
            $y1 = min($my + intdiv(strlen($coverage), $width), $clip->bottom());
            $x0 = max($mx, $clip->x);
            $x1 = min($mx + $width, $clip->right());
            if ($y0 >= $y1 || $x0 >= $x1) {
                continue;
            }
            $w = $x1 - $x0;
            $stride = self::stride($w);
            $rows = [];
            for ($y = $y0; $y < $y1; $y++) {
                $row = substr($coverage, ($y - $my) * $width + $x0 - $mx, $w);
                $lead = strspn($row, "\0");
                if ($lead < $w) {
                    $left = min($left, $x0 + $lead);
                    $right = max($right, $x0 + strlen(rtrim($row, "\0")));
                    $top = min($top, $y);
                    $bottom = max($bottom, $y + 1);
                }
                $rows[] = str_pad($row, $stride, "\0");
            }
            $this->over($rgba8, implode('', $rows), $stride, new Region($x0, $y0, $w, $y1 - $y0));
        }

        return $right === PHP_INT_MIN ? $this : $this->wrote(new Region($left, $top, $right - $left, $bottom - $top));
    }

    /**
     * Another framebuffer's pixels through $placement, pixman's way: the source premultiplied by
     * pixman, tinted through a component-alpha solid, transformed and sampled by pixman's filter
     * with its edges held (PAD), and composited OVER through one mask that is the footprint (the
     * pixels whose centre maps inside the source or $window), scaled by $opacity and $coverage.
     */
    public function paintImage(InstanceThatManagesAFramebuffer $source, Affine $placement, int $opacity = 255, Filter $filter = Filter::NEAREST, ?Region $clip = null, int $tint = 0xFFFFFFFF, ?array $window = null, ?string $coverage = null): static
    {
        $view = $this->view($source);
        if (is_null($view)) {
            [$rgba8, $sw, $sh, $top] = $this->blitSource($source);
        } else {
            [$sw, $sh, $top] = [pixman_image_get_width($view), pixman_image_get_height($view), 0];
        }
        $plan = ImagePlacement::plan($placement, $sw, $sh, $top, $this->whole(), $clip, $opacity, $window);
        if (is_null($plan) || $opacity === 0) {
            return $this;
        }
        [$target, $inverse, $held] = $plan;

        $picture = is_null($view) ? self::premultiplied($rgba8, $sw, $sh) : self::premultipliedView($view, $sw, $sh);
        if ($tint !== 0xFFFFFFFF) {
            $picture = self::tinted($picture, $sw, $sh, $tint);
        }
        // pixman holds the transform in 16.16 fixed point: where a pixel centre maps exactly onto a
        // source pixel's edge, its rounding can sample the pixel beside the one exact arithmetic picks.
        pixman_image_set_transform($picture, self::transform($inverse));
        pixman_image_set_filter($picture, $filter === Filter::LINEAR ? PIXMAN_FILTER_BILINEAR : PIXMAN_FILTER_NEAREST, null, 0);
        pixman_image_set_repeat($picture, PIXMAN_REPEAT_PAD);

        $mask = $this->footprint($target, $placement, $held ?? [0.0, 0.0, (float) $sw, (float) $sh], $top);
        if ($opacity < 255) {
            pixman_image_composite32(PIXMAN_OP_IN, self::solid(0, 0, 0, $opacity), null, $mask, 0, 0, 0, 0, 0, 0, $target->width, $target->height);
        }
        if (! is_null($coverage)) {
            pixman_image_composite32(PIXMAN_OP_IN, $this->spanMask($coverage, $target), null, $mask, 0, 0, 0, 0, 0, 0, $target->width, $target->height);
        }
        pixman_image_composite32(PIXMAN_OP_OVER, $picture, $mask, $this->image, $target->x, $target->y, 0, 0, $target->x, $target->y, $target->width, $target->height);

        return $this->wrote($target);
    }

    /**
     * A gradient through spans, pixman's way: a pixman linear (t = u) or radial (t = hypot(u, v))
     * gradient over the ramp's 256 colours, its transform the inverse, its repeat the spread,
     * composited OVER through the spans' coverage.
     */
    public function paintGradient(string $spans, string $ramp, string $kind, GradientSpread $spread, array $inverse): static
    {
        if (strlen($ramp) !== 1024) {
            throw new FramebufferException('paintGradient() takes a ramp of 256 RGBA colours (1024 bytes), got '.strlen($ramp).'.');
        }
        if (count($inverse) !== 6 || array_filter($inverse, fn (mixed $n): bool => (is_int($n) || is_float($n)) && is_finite($n)) !== $inverse) {
            throw new FramebufferException('paintGradient() takes an inverse of six finite numbers.');
        }
        [, $bounds] = SpanList::read($spans, 0xFFFFFFFF, $this->width, $this->height);
        if (is_null($bounds)) {
            return $this;
        }

        $stops = [];
        for ($i = 0; $i < 256; $i++) {
            [, $r, $g, $b, $a] = unpack('C4', $ramp, $i * 4);
            $stop = new pixman_gradient_stop();
            $stop->x = self::fixed($i / 255);
            $stop->color = self::color($r, $g, $b, $a);
            $stops[] = $stop;
        }
        $origin = self::point(0.0, 0.0);
        $gradient = match ($kind) {
            'linear' => pixman_image_create_linear_gradient($origin, self::point(1.0, 0.0), $stops, 256),
            'radial' => pixman_image_create_radial_gradient($origin, $origin, 0, self::fixed(1.0), $stops, 256),
            default => throw new FramebufferException("paintGradient() takes 'linear' or 'radial', got '{$kind}'."),
        };
        pixman_image_set_transform($gradient, self::transform(array_values($inverse)));
        pixman_image_set_repeat($gradient, match ($spread) {
            GradientSpread::PAD => PIXMAN_REPEAT_PAD,
            GradientSpread::REPEAT => PIXMAN_REPEAT_NORMAL,
            GradientSpread::REFLECT => PIXMAN_REPEAT_REFLECT,
        });

        pixman_image_composite32(PIXMAN_OP_OVER, $gradient, $this->spanMask($spans, $bounds), $this->image, $bounds->x, $bounds->y, 0, 0, $bounds->x, $bounds->y, $bounds->width, $bounds->height);

        return $this->wrote($bounds);
    }

    /** A pixel value of the format (0xRRGGBBAA, 0xRRGGBB, or a 565 word) as the format's bytes. */
    private function encode(int $value): string
    {
        [$r, $g, $b, $a] = [($value >> 24) & 0xFF, ($value >> 16) & 0xFF, ($value >> 8) & 0xFF, $value & 0xFF];

        return match ($this->code) {
            PIXMAN_a8b8g8r8 => pack('C4', $r, $g, $b, $a),
            PIXMAN_a8r8g8b8 => pack('C4', $b, $g, $r, $a),
            PIXMAN_b8g8r8a8 => pack('C4', $a, $r, $g, $b),
            PIXMAN_r8g8b8a8 => pack('C4', $a, $b, $g, $r),
            PIXMAN_b8g8r8 => pack('C3', ($value >> 16) & 0xFF, ($value >> 8) & 0xFF, $value & 0xFF),
            PIXMAN_r8g8b8 => pack('C3', $value & 0xFF, ($value >> 8) & 0xFF, ($value >> 16) & 0xFF),
            PIXMAN_r5g6b5 => pack('v', $value & 0xFFFF),
            PIXMAN_b5g6r5 => pack('v', (($value & 0x1F) << 11) | ($value & 0x7E0) | (($value >> 11) & 0x1F)),
        };
    }

    /** The format's bytes of one pixel as its value: encode() undone. */
    private function decode(string $bytes): int
    {
        $c = array_values(unpack('C*', $bytes));

        return match ($this->code) {
            PIXMAN_a8b8g8r8 => ($c[0] << 24) | ($c[1] << 16) | ($c[2] << 8) | $c[3],
            PIXMAN_a8r8g8b8 => ($c[2] << 24) | ($c[1] << 16) | ($c[0] << 8) | $c[3],
            PIXMAN_b8g8r8a8 => ($c[1] << 24) | ($c[2] << 16) | ($c[3] << 8) | $c[0],
            PIXMAN_r8g8b8a8 => ($c[3] << 24) | ($c[2] << 16) | ($c[1] << 8) | $c[0],
            PIXMAN_b8g8r8 => ($c[0] << 16) | ($c[1] << 8) | $c[2],
            PIXMAN_r8g8b8 => ($c[2] << 16) | ($c[1] << 8) | $c[0],
            PIXMAN_r5g6b5 => $c[0] | ($c[1] << 8),
            PIXMAN_b5g6r5 => (static fn (int $w): int => (($w & 0x1F) << 11) | ($w & 0x7E0) | (($w >> 11) & 0x1F))($c[0] | ($c[1] << 8)),
        };
    }

    /** A rect's bytes in $spec: as they are when it is this format, else pixman's SRC into $spec's pixman format. */
    private function in(Region $region, FormatSpec $spec): string
    {
        if ($spec->equals($this->format)) {
            return $this->read($region);
        }
        $code = PixmanFormats::code($spec)
            ?? throw FramebufferException::unsupportedFormat($spec, 'pixman has no format with this byte layout to convert into.');

        return $this->convert($region, $code);
    }

    /** A rect through pixman's SRC into a pixman format, rows packed. */
    private function convert(Region $region, int $code): string
    {
        $out = pixman_image_create_bits($code, $region->width, $region->height, null, 0);
        pixman_image_composite32(PIXMAN_OP_SRC, $this->image, null, $out, $region->x, $region->y, 0, 0, 0, 0, $region->width, $region->height);

        return self::unpad(pixman_image_get_data($out, copy: true), $region->width * PixmanFormats::bytesPerPixel($code), $region->height, pixman_image_get_stride($out));
    }

    /** A rect's bytes in this format, rows packed, top row first. */
    private function read(Region $region): string
    {
        return $this->convert($region, $this->code);
    }

    /** Packed rows of this format's bytes into a rect. */
    private function write(Region $region, string $bytes): void
    {
        $row = $region->width * $this->bytes_per_pixel;
        $stride = (($row + 3) >> 2) << 2;
        $in = pixman_image_create_bits($this->code, $region->width, $region->height, self::pad($bytes, $row, $region->height, $stride), $stride);
        pixman_image_composite32(PIXMAN_OP_SRC, $in, null, $this->image, 0, 0, 0, 0, $region->x, $region->y, $region->width, $region->height);
    }

    /** One cell repeated over a rect: a 1x1 image with NORMAL repeat, SRC. */
    private function paintCell(Region $region, string $cell): void
    {
        $tile = pixman_image_create_bits($this->code, 1, 1, str_pad($cell, 4, "\0"), 4);
        pixman_image_set_repeat($tile, PIXMAN_REPEAT_NORMAL);
        pixman_image_composite32(PIXMAN_OP_SRC, $tile, null, $this->image, 0, 0, 0, 0, $region->x, $region->y, $region->width, $region->height);
    }

    /** 0xRRGGBBAA, straight, through an a8 coverage mask over $at, OVER: pixman takes the solid premultiplied. */
    private function over(int $rgba8, string $mask, int $stride, Region $at): void
    {
        $alpha = $rgba8 & 0xFF;
        if ($alpha === 0) {
            return;
        }
        $coverage = pixman_image_create_bits(PIXMAN_a8, $at->width, $at->height, $mask, $stride);
        pixman_image_composite32(PIXMAN_OP_OVER, self::solid(($rgba8 >> 24) & 0xFF, ($rgba8 >> 16) & 0xFF, ($rgba8 >> 8) & 0xFF, $alpha), $coverage, $this->image, 0, 0, 0, 0, $at->x, $at->y, $at->width, $at->height);
    }

    /** Spans as an a8 coverage image over $at (spans outside it are dropped; a pixel no span holds is 0). */
    private function spanMask(string $spans, Region $at): pixman_image
    {
        [$list] = SpanList::read($spans, 0xFFFFFFFF, $this->width, $this->height);
        $stride = self::stride($at->width);
        $mask = str_repeat("\0", $stride * $at->height);
        foreach ($list as [$y, $x, $length, $coverage]) {
            $x0 = max($x, $at->x);
            $x1 = min($x + $length, $at->right());
            if ($y < $at->y || $y >= $at->bottom() || $x0 >= $x1) {
                continue;
            }
            $mask = substr_replace($mask, str_repeat(chr($coverage), $x1 - $x0), ($y - $at->y) * $stride + $x0 - $at->x, $x1 - $x0);
        }

        return pixman_image_create_bits(PIXMAN_a8, $at->width, $at->height, $mask, $stride);
    }

    /**
     * The pixels of $target whose centre maps inside $held (the source rect painted, in the
     * source's byte coordinates), as an a8 mask of 0 and 255: the parallelogram $held makes on
     * this surface, rasterized by pixman at one sample a pixel (a1), widened to a8.
     *
     * @param  array{float, float, float, float}  $held
     */
    private function footprint(Region $target, Affine $placement, array $held, int $top): pixman_image
    {
        [$u0, $v0, $u1, $v1] = $held;
        $corner = fn (float $u, float $v): pixman_point_fixed => self::point(...$placement->apply($u, $v + $top));
        $quad = [$corner($u0, $v0), $corner($u1, $v0), $corner($u1, $v1), $corner($u0, $v1)];
        $triangles = [];
        foreach ([[0, 1, 2], [0, 2, 3]] as [$i, $j, $k]) {
            $t = new pixman_triangle();
            [$t->p1, $t->p2, $t->p3] = [$quad[$i], $quad[$j], $quad[$k]];
            $triangles[] = $t;
        }
        $bits = pixman_image_create_bits(PIXMAN_a1, $target->width, $target->height, null, 0);
        pixman_add_triangles($bits, -$target->x, -$target->y, 2, $triangles);
        $mask = pixman_image_create_bits(PIXMAN_a8, $target->width, $target->height, null, 0);
        pixman_image_composite32(PIXMAN_OP_SRC, $bits, null, $mask, 0, 0, 0, 0, 0, 0, $target->width, $target->height);

        return $mask;
    }

    /**
     * Straight RGBA8 as a premultiplied pixman image, by pixman: the colours (the bytes seen as
     * x8b8g8r8) through their own alpha (the same bytes seen as a8b8g8r8) as the mask, SRC.
     */
    private static function premultiplied(string $rgba8, int $width, int $height): pixman_image
    {
        $out = pixman_image_create_bits(PIXMAN_a8b8g8r8, $width, $height, null, 0);
        pixman_image_composite32(PIXMAN_OP_SRC,
            pixman_image_create_bits(PIXMAN_x8b8g8r8, $width, $height, $rgba8, $width * 4),
            pixman_image_create_bits(PIXMAN_a8b8g8r8, $width, $height, $rgba8, $width * 4),
            $out, 0, 0, 0, 0, 0, 0, $width, $height);

        return $out;
    }

    /**
     * A straight image in any format, premultiplied by pixman with no PHP bytes: SRC into a8b8g8r8
     * (pixman moves the straight bytes as they are), then that image's colours through its own
     * alpha, as premultiplied() does, both views over the one buffer.
     */
    private static function premultipliedView(pixman_image $view, int $width, int $height): pixman_image
    {
        $straight = pixman_image_create_bits(PIXMAN_a8b8g8r8, $width, $height, null, 0);
        pixman_image_composite32(PIXMAN_OP_SRC, $view, null, $straight, 0, 0, 0, 0, 0, 0, $width, $height);
        $address = pixman_image_get_data($straight);
        $stride = pixman_image_get_stride($straight);
        $out = pixman_image_create_bits(PIXMAN_a8b8g8r8, $width, $height, null, 0);
        pixman_image_composite32(PIXMAN_OP_SRC,
            pixman_image_create_bits(PIXMAN_x8b8g8r8, $width, $height, $address, $stride),
            pixman_image_create_bits(PIXMAN_a8b8g8r8, $width, $height, $address, $stride),
            $out, 0, 0, 0, 0, 0, 0, $width, $height);

        return $out;
    }

    /** A premultiplied image times a 0xRRGGBBAA tint per channel, alpha included: a component-alpha solid mask. */
    private static function tinted(pixman_image $picture, int $width, int $height, int $tint): pixman_image
    {
        $ta = $tint & 0xFF;
        $scale = fn (int $c): int => intdiv($c * $ta + 127, 255);
        $mask = self::solid($scale(($tint >> 24) & 0xFF), $scale(($tint >> 16) & 0xFF), $scale(($tint >> 8) & 0xFF), $ta, premultiply: false);
        pixman_image_set_component_alpha($mask, true);
        $out = pixman_image_create_bits(PIXMAN_a8b8g8r8, $width, $height, null, 0);
        pixman_image_composite32(PIXMAN_OP_SRC, $picture, $mask, $out, 0, 0, 0, 0, 0, 0, $width, $height);

        return $out;
    }

    /** A solid fill of 8-bit channels; straight in, premultiplied for pixman unless told the channels already are. */
    private static function solid(int $r, int $g, int $b, int $a, bool $premultiply = true): pixman_image
    {
        $p = $premultiply ? fn (int $c): int => intdiv($c * $a + 127, 255) : fn (int $c): int => $c;

        return pixman_image_create_solid_fill(new pixman_color($p($r) * 0x101, $p($g) * 0x101, $p($b) * 0x101, $a * 0x101));
    }

    /** A straight 8-bit colour as pixman's 16-bit one (gradient stops are straight; pixman premultiplies them). */
    private static function color(int $r, int $g, int $b, int $a): pixman_color
    {
        return new pixman_color($r * 0x101, $g * 0x101, $b * 0x101, $a * 0x101);
    }

    /** a, b, c, d, e, f (u = a·x + c·y + e, v = b·x + d·y + f) as pixman's fixed-point matrix. */
    private static function transform(array $m): pixman_transform
    {
        [$a, $b, $c, $d, $e, $f] = $m;
        $t = new pixman_transform();
        $t->matrix = [
            [self::fixed($a), self::fixed($c), self::fixed($e)],
            [self::fixed($b), self::fixed($d), self::fixed($f)],
            [0, 0, self::fixed(1.0)],
        ];

        return $t;
    }

    private static function point(float $x, float $y): pixman_point_fixed
    {
        $p = new pixman_point_fixed();
        $p->x = self::fixed($x);
        $p->y = self::fixed($y);

        return $p;
    }

    /** pixman's 16.16 fixed point, held inside its range. */
    private static function fixed(float $v): int
    {
        return (int) round(max(-32767.0, min(32767.0, $v)) * 65536);
    }

    private static function stride(int $width): int
    {
        return (($width + 3) >> 2) << 2;
    }

    private static function unpad(string $data, int $row, int $height, int $stride): string
    {
        if ($row === $stride) {
            return $data;
        }
        $rows = [];
        for ($y = 0; $y < $height; $y++) {
            $rows[] = substr($data, $y * $stride, $row);
        }

        return implode('', $rows);
    }

    private static function pad(string $bytes, int $row, int $height, int $stride): string
    {
        if ($row === $stride) {
            return $bytes;
        }
        $gap = str_repeat("\0", $stride - $row);
        $rows = [];
        for ($y = 0; $y < $height; $y++) {
            $rows[] = substr($bytes, $y * $row, $row).$gap;
        }

        return implode('', $rows);
    }

    private function whole(): Region
    {
        return Region::wholeSurface($this->width, $this->height);
    }

    /** @throws FramebufferException When $region is empty or reaches outside the surface. */
    private function inside(Region $region): void
    {
        if ($region->isEmpty() || $region->x < 0 || $region->y < 0 || $region->right() > $this->width || $region->bottom() > $this->height) {
            throw FramebufferException::outsideSurface($region, $this->width, $this->height);
        }
    }

    /**
     * The whole list checked before anything is written, and its bounding box.
     *
     * @param  array<int, array<int, int>>  $pixels
     */
    private function bounds(array $pixels, int $arity, string $shape): ?Region
    {
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        foreach (array_values($pixels) as $position => $pixel) {
            if (! is_array($pixel) || count($pixel) !== $arity || ! is_int($pixel[0] ?? null) || ! is_int($pixel[1] ?? null) || ($arity === 3 && ! is_int($pixel[2] ?? null))) {
                throw new FramebufferException("Pixel {$position} is not {$shape}.");
            }
            $this->guard($pixel[0], $pixel[1]);
            $left = min($left, $pixel[0]);
            $top = min($top, $pixel[1]);
            $right = max($right, $pixel[0]);
            $bottom = max($bottom, $pixel[1]);
        }

        return $pixels === [] ? null : new Region($left, $top, $right - $left + 1, $bottom - $top + 1);
    }
}
