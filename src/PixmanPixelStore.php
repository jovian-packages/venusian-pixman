<?php

namespace Jovian\Rendering\Pixman;

use pixman_color;
use pixman_image;
use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\PaintingPixelStore;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\PixelStore;
use Surface\NutsAndBolts\GradientSpread;
use Surface\VelvetGE\Framebuffers\MaskList;
use Surface\VelvetGE\Framebuffers\SpanList;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\VelvetGE\Framebuffers\Layout;
use Surface\VelvetGE\Framebuffers\PHPLand\Packings\Packing;
use Surface\VelvetGE\Framebuffers\PHPLand\PHPLandPixelStore;

/**
 * The core PixelStore tier with the pixels in a pixman image. The image's bytes are VelvetGE's
 * bytes for the format word for word (PixmanFormats picks a pixman format with the same memory
 * layout), so storage, copies, fills and blits are pixman's SRC operator: a straight copy, exact.
 *
 * Where a value has to pass through VelvetGE's arithmetic (a word's byte encoding, a conversion
 * to another FormatSpec, the plane of a word), the store lends the bytes to a PHPLandPixelStore
 * and asks it, so the answer is VelvetGE's by construction.
 *
 * Painting: on the 32- and 24-bit layouts, spans and masks blend with pixman's OVER (a
 * premultiplied solid through an a8 coverage mask), which is VelvetGE's straight-alpha lerp
 * with pixman's rounding: at most 2 off per byte, measured (PixmanPixelStore::TOLERANCE),
 * within the tolerance Angel allowed library drivers on 2026-11-13. 565 spans and masks,
 * gradients and image painting run VelvetGE's arithmetic on lent pixels, exact. Effects come from Topside's
 * CompletingPixelStore.
 */
final class PixmanPixelStore implements PaintingPixelStore
{
    /** The most a byte of a pixman-blended pixel may differ from VelvetGE's: measured over random spans and masks at every alpha and coverage. */
    public const int TOLERANCE = 2;

    private pixman_image $image;

    private int $code;

    private int $bytes_per_pixel;

    /** A 1x1 store of the format: VelvetGE's own encoding of one word into bytes and back. */
    private PHPLandPixelStore $codec;

    private ?DamageGranularity $granularity = null;

    /**
     * @throws FramebufferException When the format has no pixman layout (PixmanFormats::code() is null).
     */
    public function __construct(
        private readonly FormatSpec $format,
        private readonly int $width,
        private readonly int $height,
    ) {
        Layout::size($width, $height);
        $this->codec = new PHPLandPixelStore($format, 1, 1);
        $code = PixmanFormats::code($format);
        if ($code === null) {
            throw FramebufferException::unsupportedFormat($format, 'pixman has no format with this byte layout.');
        }
        $this->code = $code;
        $this->bytes_per_pixel = PixmanFormats::bytesPerPixel($code);
        $this->image = pixman_image_create_bits($code, $width, $height, null, 0)
            ?? throw new FramebufferException("pixman could not allocate a {$width}x{$height} image.");
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function format(): FormatSpec
    {
        return $this->format;
    }

    public function get(int $x, int $y): int
    {
        $this->guard($x, $y);
        $this->codec->load(new Region(0, 0, 1, 1), $this->read(new Region($x, $y, 1, 1)));

        return $this->codec->get(0, 0);
    }

    public function set(int $x, int $y, int $value): void
    {
        $this->guard($x, $y);
        $this->write(new Region($x, $y, 1, 1), $this->encode($value));
    }

    public function setPixels(array $pixels): ?Region
    {
        $bounds = $this->bounds($pixels, 3, '[x, y, value]');
        foreach ($pixels as [$x, $y, $value]) {
            $this->write(new Region($x, $y, 1, 1), $this->encode($value));
        }

        return $bounds;
    }

    public function setCoordinates(array $coordinates, int $value): ?Region
    {
        $bounds = $this->bounds($coordinates, 2, '[x, y]');
        $cell = $this->encode($value);
        foreach ($coordinates as [$x, $y]) {
            $this->write(new Region($x, $y, 1, 1), $cell);
        }

        return $bounds;
    }

    public function rect(Region $region, int $value): void
    {
        $this->inside($region);
        $this->paintCell($region, $this->encode($value));
    }

    public function fill(int $value): void
    {
        $this->paintCell(Region::wholeSurface($this->width, $this->height), $this->encode($value));
    }

    public function bytes(?int $layer = null): string
    {
        if ($layer !== null) {
            // Row-major formats have one layer: VelvetGE answers (and refuses) layers its own way.
            return $this->lent()->bytes($layer);
        }

        return $this->read(Region::wholeSurface($this->width, $this->height));
    }

    public function region(Region $region, FormatSpec $spec): string
    {
        $this->inside($region);
        $own = $this->read($region);
        if ($spec->equals($this->format)) {
            return $own;
        }
        $lent = new PHPLandPixelStore($this->format, $region->width, $region->height);
        $lent->load(Region::wholeSurface($region->width, $region->height), $own);

        return $lent->region(Region::wholeSurface($region->width, $region->height), $spec);
    }

    public function load(Region $region, string $bytes): void
    {
        $this->inside($region);
        $need = $region->width * $region->height * $this->bytes_per_pixel;
        if (strlen($bytes) !== $need) {
            throw new FramebufferException("load() takes the {$need} bytes of a {$region->width}x{$region->height} rect in this format, got ".strlen($bytes).'.');
        }
        $this->write($region, $bytes);
    }

    public function toRgba8(): string
    {
        if ($this->code === PIXMAN_a8b8g8r8) {
            return $this->bytes();
        }
        if (PixmanFormats::lossless($this->code)) {
            // 32- and 24-bit layouts reach RGBA8 by moving bytes (24-bit gains alpha 255, as VelvetGE's does).
            $out = pixman_image_create_bits(PIXMAN_a8b8g8r8, $this->width, $this->height, null, 0);
            pixman_image_composite32(PIXMAN_OP_SRC, $this->image, null, $out, 0, 0, 0, 0, 0, 0, $this->width, $this->height);

            return pixman_image_get_data($out);
        }

        // 565 widens by round(v / 31 × 255) in VelvetGE; pixman replicates bits. VelvetGE's arithmetic answers.
        return $this->lent()->toRgba8();
    }

    public function blitRgba8(string $rgba8, int $width, int $height, int $offset_x, int $offset_y, ?Region $clip = null): ?Region
    {
        if ($width < 1 || $height < 1 || strlen($rgba8) !== $width * $height * 4) {
            throw new FramebufferException("blitRgba8() takes {$width}x{$height} RGBA8 pixels (".($width * $height * 4).' bytes), got '.strlen($rgba8).'.');
        }
        $target = (new Region($offset_x, $offset_y, $width, $height))->intersect(Region::wholeSurface($this->width, $this->height));
        if ($target !== null && $clip !== null) {
            $target = $target->intersect($clip);
        }
        if ($target === null) {
            return null;
        }

        // RGBA8 to every pixman layout here narrows by truncation, as VelvetGE's fromRgba8() does (565 by >> 3 / >> 2).
        $source = pixman_image_create_bits(PIXMAN_a8b8g8r8, $width, $height, $rgba8, $width * 4);
        pixman_image_composite32(PIXMAN_OP_SRC, $source, null, $this->image,
            $target->x - $offset_x, $target->y - $offset_y, 0, 0, $target->x, $target->y, $target->width, $target->height);

        return $target;
    }

    public function paintSpans(string $spans, int $rgba8): ?Region
    {
        if (! PixmanFormats::lossless($this->code)) {
            return $this->onLent(fn (PHPLandPixelStore $lent): ?Region => $lent->paintSpans($spans, $rgba8));
        }
        [$list, $bounds] = SpanList::read($spans, $rgba8, $this->width, $this->height);
        if ($bounds === null) {
            return null;
        }
        // Spans become one coverage mask per pass. VelvetGE paints spans in list order, so a span
        // that overlaps one already in the mask blends a second time: the mask is painted first.
        $stride = (($bounds->width + 3) >> 2) << 2;
        $blank = str_repeat("\0", $stride * $bounds->height);
        $mask = $blank;
        $taken = [];
        foreach ($list as [$y, $x, $length, $coverage]) {
            foreach ($taken[$y] ?? [] as [$x0, $x1]) {
                if ($x < $x1 && $x + $length > $x0) {
                    $this->over($rgba8, $mask, $stride, $bounds->x, $bounds->y, $bounds->width, $bounds->height);
                    $mask = $blank;
                    $taken = [];
                    break;
                }
            }
            $taken[$y][] = [$x, $x + $length];
            $at = ($y - $bounds->y) * $stride + $x - $bounds->x;
            $mask = substr_replace($mask, str_repeat(chr($coverage), $length), $at, $length);
        }
        $this->over($rgba8, $mask, $stride, $bounds->x, $bounds->y, $bounds->width, $bounds->height);

        return $bounds;
    }

    public function paintMasks(array $masks, int $rgba8, Region $clip): ?Region
    {
        if (! PixmanFormats::lossless($this->code)) {
            return $this->onLent(fn (PHPLandPixelStore $lent): ?Region => $lent->paintMasks($masks, $rgba8, $clip));
        }
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
            $stride = (($w + 3) >> 2) << 2;
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
            $this->over($rgba8, implode('', $rows), $stride, $x0, $y0, $w, $y1 - $y0);
        }

        return $right === PHP_INT_MIN ? null : new Region($left, $top, $right - $left, $bottom - $top);
    }

    /** VelvetGE's ramp lookup on lent pixels: exact. */
    public function paintGradient(string $spans, string $ramp, string $kind, GradientSpread $spread, array $inverse, int $row = 0): ?Region
    {
        return $this->onLent(fn (PHPLandPixelStore $lent): ?Region => $lent->paintGradient($spans, $ramp, $kind, $spread, $inverse, $row));
    }

    /**
     * A paint call run by VelvetGE on lent pixels, and the rect it reports written back.
     * 565 paints this way: pixman widens 565 by bit replication before it blends, which puts a
     * channel up to one 565 step (9 in RGBA8) off VelvetGE, past the tolerance.
     *
     * @param  \Closure(PHPLandPixelStore): ?Region  $paint
     */
    private function onLent(\Closure $paint): ?Region
    {
        $lent = $this->lent();
        $bounds = $paint($lent);
        if ($bounds !== null) {
            $this->write($bounds, $lent->region($bounds, $this->format));
        }

        return $bounds;
    }

    /** VelvetGE's sampling, tint, window and coverage on lent pixels: exact. */
    public function paintRgba8(string $rgba8, int $width, int $height, array $inverse, Region $target, int $opacity, Filter $filter, int $row = 0, int $tint = 0xFFFFFFFF, ?array $window = null, ?string $coverage = null): void
    {
        $lent = $this->lent();
        $lent->paintRgba8($rgba8, $width, $height, $inverse, $target, $opacity, $filter, $row, $tint, $window, $coverage);
        $this->write($target, $lent->region($target, $this->format));
    }

    /** 0xRRGGBBAA, straight, through an a8 coverage mask, OVER: pixman takes the solid premultiplied. */
    private function over(int $rgba8, string $mask, int $stride, int $x, int $y, int $width, int $height): void
    {
        $alpha = $rgba8 & 0xFF;
        if ($alpha === 0) {
            return;
        }
        $premultiplied = fn (int $c): int => intdiv($c * $alpha + 127, 255) * 0x101;
        $solid = pixman_image_create_solid_fill(new pixman_color(
            $premultiplied(($rgba8 >> 24) & 0xFF), $premultiplied(($rgba8 >> 16) & 0xFF), $premultiplied(($rgba8 >> 8) & 0xFF), $alpha * 0x101,
        ));
        $coverage = pixman_image_create_bits(PIXMAN_a8, $width, $height, $mask, $stride);
        pixman_image_composite32(PIXMAN_OP_OVER, $solid, $coverage, $this->image, 0, 0, 0, 0, $x, $y, $width, $height);
    }

    public function copy(PixelStore $source, Region $region): void
    {
        if ($source->width() !== $this->width || $source->height() !== $this->height || ! $source->format()->equals($this->format)) {
            throw new FramebufferException('copy() takes a store of the same size and format.');
        }
        $this->inside($region);
        if ($source instanceof self) {
            pixman_image_composite32(PIXMAN_OP_SRC, $source->image, null, $this->image,
                $region->x, $region->y, 0, 0, $region->x, $region->y, $region->width, $region->height);

            return;
        }
        $this->write($region, $source->region($region, $this->format));
    }

    public function plane(int $value): string
    {
        return $this->lent()->plane($value);
    }

    /** The address of pixman's bits, which hold the format's bytes row by row (rows padded to 4 bytes). */
    public function pointer(): int
    {
        return pixman_image_get_data_address($this->image);
    }

    /**
     * pixman's bits as PixelMemory: they hold exactly what bytes() answers (straight colours, rows
     * padded to 4 bytes), so a direct scanner can paint them in place. The pixman_image owns them.
     */
    public function memory(): ?PixelMemory
    {
        return new PixelMemory($this->pointer(), $this->width, $this->height, pixman_image_get_stride($this->image), $this->format, $this->image);
    }

    /** VelvetGE's granularity for this format at this size (it depends on the size, so not the 1x1 codec's). */
    public function granularity(): DamageGranularity
    {
        return $this->granularity ??= Packing::for($this->format, $this->width, $this->height)->granularity();
    }

    /** VelvetGE's bytes for one word of the format. */
    private function encode(int $value): string
    {
        $this->codec->set(0, 0, $value);

        return $this->codec->bytes();
    }

    /** A whole-surface PHPLandPixelStore holding these bytes, for the answers that are VelvetGE's arithmetic. */
    private function lent(): PHPLandPixelStore
    {
        $lent = new PHPLandPixelStore($this->format, $this->width, $this->height);
        $lent->load(Region::wholeSurface($this->width, $this->height), $this->bytes());

        return $lent;
    }

    /** One cell repeated over a rect: a 1x1 image with NORMAL repeat, SRC. */
    private function paintCell(Region $region, string $cell): void
    {
        $tile = pixman_image_create_bits($this->code, 1, 1, str_pad($cell, 4, "\0"), 4);
        pixman_image_set_repeat($tile, PIXMAN_REPEAT_NORMAL);
        pixman_image_composite32(PIXMAN_OP_SRC, $tile, null, $this->image, 0, 0, 0, 0, $region->x, $region->y, $region->width, $region->height);
    }

    /** A rect's bytes, rows packed with no padding, top row first. */
    private function read(Region $region): string
    {
        $out = pixman_image_create_bits($this->code, $region->width, $region->height, null, 0);
        pixman_image_composite32(PIXMAN_OP_SRC, $this->image, null, $out, $region->x, $region->y, 0, 0, 0, 0, $region->width, $region->height);

        return $this->unpad(pixman_image_get_data($out), $region->width, $region->height, pixman_image_get_stride($out));
    }

    /** Packed rows of the format's bytes into a rect. */
    private function write(Region $region, string $bytes): void
    {
        $stride = ((($region->width * $this->bytes_per_pixel) + 3) >> 2) << 2;
        $in = pixman_image_create_bits($this->code, $region->width, $region->height, $this->pad($bytes, $region->width, $region->height, $stride), $stride);
        pixman_image_composite32(PIXMAN_OP_SRC, $in, null, $this->image, 0, 0, 0, 0, $region->x, $region->y, $region->width, $region->height);
    }

    private function unpad(string $data, int $width, int $height, int $stride): string
    {
        $row = $width * $this->bytes_per_pixel;
        if ($row === $stride) {
            return $data;
        }
        $rows = [];
        for ($y = 0; $y < $height; $y++) {
            $rows[] = substr($data, $y * $stride, $row);
        }

        return implode('', $rows);
    }

    private function pad(string $bytes, int $width, int $height, int $stride): string
    {
        $row = $width * $this->bytes_per_pixel;
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

    private function guard(int $x, int $y): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            throw FramebufferException::outOfRange($x, $y, $this->width, $this->height);
        }
    }

    private function inside(Region $region): void
    {
        if ($region->isEmpty() || $region->x < 0 || $region->y < 0 || $region->right() > $this->width || $region->bottom() > $this->height) {
            throw FramebufferException::outsideSurface($region, $this->width, $this->height);
        }
    }

    /**
     * The whole list checked before anything is written, and its bounding box: VelvetGE's rule and messages.
     *
     * @param  array<int, array<int, int>>  $pixels
     */
    private function bounds(array $pixels, int $arity, string $shape): ?Region
    {
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        $position = 0;
        foreach ($pixels as $pixel) {
            if (! is_array($pixel) || count($pixel) !== $arity || ! is_int($pixel[0] ?? null) || ! is_int($pixel[1] ?? null) || ($arity === 3 && ! is_int($pixel[2] ?? null))) {
                throw new FramebufferException("Pixel {$position} is not {$shape}.");
            }
            $position++;
            $this->guard($pixel[0], $pixel[1]);
            $left = min($left, $pixel[0]);
            $top = min($top, $pixel[1]);
            $right = max($right, $pixel[0]);
            $bottom = max($bottom, $pixel[1]);
        }

        return $pixels === [] ? null : new Region($left, $top, $right - $left + 1, $bottom - $top + 1);
    }
}
