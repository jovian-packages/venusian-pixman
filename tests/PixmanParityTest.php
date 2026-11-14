<?php

declare(strict_types=1);

use Jovian\Rendering\Pixman\PixmanFormats;
use Jovian\Rendering\Pixman\PixmanFramebufferDriver;
use Jovian\Rendering\Pixman\PixmanPixelStore;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\Endianness;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\VelvetGE\Framebuffers\PHPLand\PHPLandPixelStore;

/** Every FormatSpec PixmanFormats maps, by name. */
function pixman_mapped_formats(): array
{
    $f = fn (BitDepth $d, ChannelOrder $o, ?Endianness $e = null) => new FormatSpec(PixelFormat::ROW_MAJOR, $d, endianness: $e, channel_order: $o);

    return [
        'rgba8' => FormatSpec::rgba8(),
        'bgra8' => FormatSpec::bgra8(),
        'argb32' => $f(BitDepth::B32, ChannelOrder::ARGB),
        'abgr32' => $f(BitDepth::B32, ChannelOrder::ABGR),
        'rgb888' => $f(BitDepth::B24, ChannelOrder::RGB),
        'bgr888' => $f(BitDepth::B24, ChannelOrder::BGR),
        'rgb565 lsb' => $f(BitDepth::B16, ChannelOrder::RGB, Endianness::LSB),
        'bgr565 lsb' => $f(BitDepth::B16, ChannelOrder::BGR, Endianness::LSB),
    ];
}

it('maps each format to a pixman store and holds VelvetGE bytes through every core call', function (string $name): void {
    $spec = pixman_mapped_formats()[$name];
    expect(PixmanFormats::code($spec))->not->toBeNull();

    mt_srand(crc32($name));
    $w = 7;
    $h = 5;
    $px = new PixmanPixelStore($spec, $w, $h);
    $ref = new PHPLandPixelStore($spec, $w, $h);
    $same = function () use ($px, $ref, $w, $h): void {
        expect(bin2hex($px->bytes()))->toBe(bin2hex($ref->bytes()))
            ->and(bin2hex($px->toRgba8()))->toBe(bin2hex($ref->toRgba8()))
            ->and(bin2hex($px->region(new Region(1, 1, 4, 3), FormatSpec::rgba8())))->toBe(bin2hex($ref->region(new Region(1, 1, 4, 3), FormatSpec::rgba8())))
            ->and($px->plane($ref->get(3, 2)))->toBe($ref->plane($ref->get(3, 2)));
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                expect($px->get($x, $y))->toBe($ref->get($x, $y));
            }
        }
    };

    $word = fn (): int => mt_rand(0, 0x7FFFFFFF) | (mt_rand(0, 1) << 31);
    foreach ([$px, $ref] as $s) {
        $s->fill(0x12345678);
    }
    $same();

    $v = $word();
    $px->set(2, 3, $v);
    $ref->set(2, 3, $v);
    $pixels = [[0, 0, $word()], [6, 4, $word()], [3, 1, $word()]];
    expect($px->setPixels($pixels))->toEqual($ref->setPixels($pixels));
    $v = $word();
    expect($px->setCoordinates([[1, 1], [5, 2]], $v))->toEqual($ref->setCoordinates([[1, 1], [5, 2]], $v));
    $v = $word();
    $px->rect(new Region(2, 1, 3, 2), $v);
    $ref->rect(new Region(2, 1, 3, 2), $v);
    $same();

    $rgba = random_bytes(4 * 4 * 3);
    expect($px->blitRgba8($rgba, 4, 3, 4, -1, new Region(0, 0, 7, 4)))->toEqual($ref->blitRgba8($rgba, 4, 3, 4, -1, new Region(0, 0, 7, 4)));
    $same();

    $chunk = $ref->region(new Region(0, 2, 3, 3), $spec);
    $other = new PHPLandPixelStore($spec, $w, $h);
    $other->fill($word());
    $px->load(new Region(0, 2, 3, 3), $other->region(new Region(0, 2, 3, 3), $spec));
    $ref->load(new Region(0, 2, 3, 3), $other->region(new Region(0, 2, 3, 3), $spec));
    $same();

    $twin = new PixmanPixelStore($spec, $w, $h);
    $twin->fill($word());
    $px->copy($twin, new Region(5, 0, 2, 5));
    $ref->load(new Region(5, 0, 2, 5), $twin->region(new Region(5, 0, 2, 5), $spec));
    $same();
    expect($px->pointer())->toBeGreaterThan(0)
        ->and($px->granularity())->toEqual($ref->granularity());
})->with(array_keys(pixman_mapped_formats()));

it('leaves formats pixman cannot hold to VelvetGE, so the driver mints every format', function (): void {
    $big565 = new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B16, channel_order: ChannelOrder::RGB);
    $mono = new FormatSpec(PixelFormat::MONO_HORIZONTAL, BitDepth::B1);
    $driver = new PixmanFramebufferDriver();

    expect(PixmanFormats::code($big565))->toBeNull()
        ->and($driver->mint($big565, 4, 4))->not->toBeInstanceOf(PixmanPixelStore::class)
        ->and($driver->mint($mono, 8, 2))->not->toBeInstanceOf(PixmanPixelStore::class)
        ->and($driver->mint(FormatSpec::rgba8(), 4, 4))->toBeInstanceOf(PixmanPixelStore::class)
        ->and($driver->driver())->toBe('pixman');
});

it('blends spans and masks within TOLERANCE of VelvetGE on 32- and 24-bit layouts, and exactly on 565', function (string $name): void {
    $spec = pixman_mapped_formats()[$name];
    $limit = PixmanFormats::lossless((int) PixmanFormats::code($spec)) ? PixmanPixelStore::TOLERANCE : 0;
    mt_srand(crc32($name));
    $w = 32;
    $h = 16;
    $px = new PixmanPixelStore($spec, $w, $h);
    $ref = new PHPLandPixelStore($spec, $w, $h);
    $base = random_bytes($w * $h * 4);
    $px->blitRgba8($base, $w, $h, 0, 0);
    $ref->blitRgba8($base, $w, $h, 0, 0);
    $worst = 0;
    for ($i = 0; $i < 200; $i++) {
        $color = (mt_rand(0, 0xFFFFFF) << 8) | mt_rand(0, 255);
        if ($i % 2) {
            $spans = Surface\Contracts\Rendering\Framebuffers\Spans::pack(mt_rand(0, $h - 1), mt_rand(0, 10), mt_rand(1, 20), mt_rand(1, 255));
            expect($px->paintSpans($spans, $color))->toEqual($ref->paintSpans($spans, $color));
        } else {
            $mw = mt_rand(1, 12);
            $mh = mt_rand(1, 8);
            $coverage = '';
            for ($k = 0; $k < $mw * $mh; $k++) {
                $coverage .= chr(mt_rand(0, 3) ? mt_rand(0, 255) : 0);
            }
            $masks = [[$coverage, $mw, mt_rand(-4, $w - 2), mt_rand(-4, $h - 2)]];
            expect($px->paintMasks($masks, $color, new Region(0, 0, $w, $h)))->toEqual($ref->paintMasks($masks, $color, new Region(0, 0, $w, $h)));
        }
        $a = $px->bytes();
        $b = $ref->bytes();
        for ($k = 0, $n = strlen($a); $k < $n; $k++) {
            $worst = max($worst, abs(ord($a[$k]) - ord($b[$k])));
        }
        $px->load(Region::wholeSurface($w, $h), $b); // one op's error at a time, not their sum
    }
    expect($worst)->toBeLessThanOrEqual($limit);
})->with(array_keys(pixman_mapped_formats()));
