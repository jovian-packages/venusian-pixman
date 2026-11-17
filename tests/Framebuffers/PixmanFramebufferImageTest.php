<?php

use Jovian\Engines\Velvet\Lustrous\Framebuffers\FullFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Rendering\Pixman\Framebuffers\PixmanFramebufferImage;
use Jovian\Rendering\Pixman\Framebuffers\VelvetPixmanFramebufferDriver;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\Endianness;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\Spans;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\GradientSpread;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\Contracts\Framebuffers\EffectsFramebuffer;
use VelvetGE\Contracts\Framebuffers\ScrollingFramebuffer;

/*
 * A pixman image held to lustrous-php, the reference: the same calls on both, in every format
 * pixman holds. Moves, fills and conversions move bytes, so they match exactly (565 read-outs
 * widen by pixman's bit replication, within 1); blends are pixman's arithmetic, within the
 * image's TOLERANCE (5% of 255) of lustrous-php's.
 */

const PW = 37;
const PH = 23;

dataset('pixman formats', [
    'rgba8' => [FormatSpec::rgba8()],
    'bgra8' => [FormatSpec::bgra8()],
    'argb' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B32, channel_order: ChannelOrder::ARGB)],
    'abgr' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B32, channel_order: ChannelOrder::ABGR)],
    'rgb888' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B24)],
    'bgr888' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B24, channel_order: ChannelOrder::BGR)],
    '565' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B16, endianness: Endianness::LSB)],
    '565 bgr' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B16, endianness: Endianness::LSB, channel_order: ChannelOrder::BGR)],
]);

/** @return array{PixmanFramebufferImage, FullFramebuffer} */
function both(FormatSpec $spec, int $w = PW, int $h = PH, ?string $rgba8 = null): array
{
    $pair = [new PixmanFramebufferImage($spec, $w, $h), new FullFramebuffer(new PHPLandFramebufferDriver(), $spec, $w, $h)];
    if (! is_null($rgba8)) {
        foreach ($pair as $frame) {
            $frame->writeRgba8($rgba8, $w, $h);
        }
    }

    return $pair;
}

/** The largest difference between two byte strings of one length. */
function furthest(string $a, string $b): int
{
    expect(strlen($a))->toBe(strlen($b));
    $most = 0;
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $most = max($most, abs(ord($a[$i]) - ord($b[$i])));
    }

    return $most;
}

function pixmanValue(FormatSpec $spec): int
{
    return match ($spec->bit_depth) {
        BitDepth::B16 => mt_rand(0, 0xFFFF),
        BitDepth::B24 => mt_rand(0, 0xFFFFFF),
        default => mt_rand(0, 0xFFFFFFFF),
    };
}

/** How far a read-out may differ: 0, but 565 widens to RGBA8 by pixman's bit replication. */
function readOutSlack(FormatSpec $spec): int
{
    return $spec->bit_depth === BitDepth::B16 ? 1 : 0;
}

beforeEach(fn () => mt_srand(7));

it('is the core plus compositing and scrolling, and no effects', function () {
    $image = new PixmanFramebufferImage(FormatSpec::rgba8(), 4, 4);

    expect($image)->toBeInstanceOf(InstanceThatManagesAFramebuffer::class)
        ->and($image)->toBeInstanceOf(CompositingFramebuffer::class)
        ->and($image)->toBeInstanceOf(ScrollingFramebuffer::class)
        ->and($image)->not->toBeInstanceOf(EffectsFramebuffer::class);
});

it('refuses a format pixman has no layout for', function () {
    new PixmanFramebufferImage(new FormatSpec(PixelFormat::MONO_HORIZONTAL, BitDepth::B1), 8, 2);
})->throws(FramebufferException::class);

it('is made by the pixman driver', function () {
    $driver = new VelvetPixmanFramebufferDriver();

    expect($driver->driver())->toBe('pixman')
        ->and($driver->make(FormatSpec::bgra8(), 5, 3))->toBeInstanceOf(PixmanFramebufferImage::class);
});

it('holds pixel values as lustrous-php does', function (FormatSpec $spec) {
    [$p, $l] = both($spec);
    for ($i = 0; $i < 50; $i++) {
        [$x, $y, $v] = [mt_rand(0, PW - 1), mt_rand(0, PH - 1), pixmanValue($spec)];
        $p->setPixel($x, $y, $v);
        $l->setPixel($x, $y, $v);
        expect($p->getPixel($x, $y))->toBe($l->getPixel($x, $y));
    }

    expect($p->dump())->toBe($l->dump());
})->with('pixman formats');

it('fills, writes segments and clears to the same bytes', function (FormatSpec $spec) {
    [$p, $l] = both($spec);
    foreach ([$p, $l] as $frame) {
        mt_srand(1);
        $frame->fill(pixmanValue($spec))->setSegment(30, 18, 20, 20, pixmanValue($spec))->setPixels([[0, 0, pixmanValue($spec)], [3, 4, pixmanValue($spec)]])->setRegion([[5, 5], [6, 7]], pixmanValue($spec));
    }
    expect($p->dump())->toBe($l->dump());

    $p->clear();
    $l->clear();
    expect($p->dump())->toBe($l->dump());
})->with('pixman formats');

it('takes RGBA8 in, and reads it out, as lustrous-php does', function (FormatSpec $spec) {
    [$p, $l] = both($spec, rgba8: random_bytes(PW * PH * 4));
    $region = new Region(3, 2, 11, 9);

    expect($p->dump())->toBe($l->dump())
        ->and(furthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(readOutSlack($spec))
        ->and(furthest($p->flush(FormatSpec::bgra8()), $l->flush(FormatSpec::bgra8())))->toBeLessThanOrEqual(readOutSlack($spec))
        ->and(furthest($p->flushRegion($region, FormatSpec::rgba8()), $l->flushRegion($region, FormatSpec::rgba8())))->toBeLessThanOrEqual(readOutSlack($spec))
        ->and($p->flush($spec))->toBe($l->flush($spec));
})->with('pixman formats');

it('scrolls to the same bytes', function (FormatSpec $spec) {
    [$p, $l] = both($spec, rgba8: random_bytes(PW * PH * 4));
    foreach ([[3, -2], [-5, 4], [0, 7]] as [$dx, $dy]) {
        $p->scroll(new Region(2, 2, 30, 18), $dx, $dy);
        $l->scroll(new Region(2, 2, 30, 18), $dx, $dy);
    }

    expect($p->dump())->toBe($l->dump());
})->with('pixman formats');

it('blits between images, and to and from Lustrous, without changing a byte', function (FormatSpec $spec) {
    [$p, $l] = both($spec, rgba8: random_bytes(PW * PH * 4));
    $copy = new PixmanFramebufferImage($spec, PW, PH);
    $lustrous = new FullFramebuffer(new PHPLandFramebufferDriver(), $spec, PW, PH);

    $p->blitTo($copy);
    $p->blitTo($lustrous);
    expect($copy->dump())->toBe($p->dump())
        ->and($lustrous->dump())->toBe($l->dump());

    $back = new PixmanFramebufferImage($spec, PW, PH);
    $back->blitFrom($lustrous);
    expect($back->dump())->toBe($l->dump());
})->with('pixman formats');

it('blends spans and masks within its tolerance of lustrous-php', function (FormatSpec $spec) {
    for ($t = 0; $t < 30; $t++) {
        $background = random_bytes(PW * PH * 4);
        $colour = mt_rand(0, 0xFFFFFFFF);

        $spans = '';
        for ($y = 0; $y < PH; $y += mt_rand(1, 2)) {
            for ($x = mt_rand(0, 3); $x < PW - 1; $x += mt_rand(2, 9)) {
                $length = mt_rand(1, min(6, PW - $x));
                $spans .= Spans::pack($y, $x, $length, mt_rand(1, 255));
                $x += $length;
            }
        }
        [$p, $l] = both($spec, rgba8: $background);
        $p->paintSpans($spans, $colour);
        $l->paintSpans($spans, $colour);
        expect(furthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(PixmanFramebufferImage::TOLERANCE);

        $masks = [[random_bytes(10 * 6), 10, mt_rand(-3, PW - 5), mt_rand(-3, PH - 3)], [random_bytes(4 * 9), 4, mt_rand(0, PW - 4), mt_rand(0, PH - 9)]];
        [$p, $l] = both($spec, rgba8: $background);
        $p->paintMasks($masks, $colour, new Region(1, 1, PW - 2, PH - 2));
        $l->paintMasks($masks, $colour, new Region(1, 1, PW - 2, PH - 2));
        // Measured: one 565 blend lands up to 9 from lustrous-php (pixman's 565 rounding), and where
        // the two masks overlap a pixel is blended twice, so the two errors add: up to 18. That is
        // outside 5%; it is pixman's 565, reported, not corrected toward Lustrous.
        $bound = $spec->bit_depth === BitDepth::B16 ? 18 : PixmanFramebufferImage::TOLERANCE;
        expect(furthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual($bound);
    }
})->with('pixman formats');

it('paints gradients within its tolerance of lustrous-php', function (FormatSpec $spec, string $kind, GradientSpread $spread) {
    $ramp = '';
    for ($i = 0; $i < 256; $i++) {
        $ramp .= pack('C4', $i, 255 - $i, (int) (128 + 127 * sin($i / 20)), (int) (60 + $i * 0.7));
    }
    $spans = '';
    for ($y = 0; $y < PH; $y++) {
        $spans .= Spans::pack($y, 1, PW - 2, mt_rand(1, 255));
    }
    [$p, $l] = both($spec, rgba8: random_bytes(PW * PH * 4));
    $inverse = [1 / 30, 0.004, -0.01, 1 / 25, -0.1, -0.2];
    $p->paintGradient($spans, $ramp, $kind, $spread, $inverse);
    $l->paintGradient($spans, $ramp, $kind, $spread, $inverse);

    expect(furthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(PixmanFramebufferImage::TOLERANCE);
})->with('pixman formats')->with(['linear', 'radial'])->with([GradientSpread::PAD, GradientSpread::REPEAT, GradientSpread::REFLECT]);

it('paints another framebuffer within its tolerance of lustrous-php', function (FormatSpec $spec, Affine $placement, Filter $filter, int $opacity, int $tint, ?array $window, bool $coverage) {
    $W = 48;
    $H = 40;
    for ($t = 0; $t < 10; $t++) {
        [$p, $l] = both($spec, $W, $H, random_bytes($W * $H * 4));
        [$ps, $ls] = both(FormatSpec::rgba8(), 9, 7, random_bytes(9 * 7 * 4));
        $spans = null;
        if ($coverage) {
            $spans = '';
            for ($y = 0; $y < $H; $y++) {
                if ($y % 3 !== 0) {
                    $spans .= Spans::pack($y, 2 + $y % 5, 20, mt_rand(1, 255));
                }
            }
        }
        $p->paintImage($ps, $placement, $opacity, $filter, null, $tint, $window, $spans);
        $l->paintImage($ls, $placement, $opacity, $filter, null, $tint, $window, $spans);

        expect(furthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(PixmanFramebufferImage::TOLERANCE);
    }
})->with('pixman formats')->with([
    'moved' => [Affine::translation(11, 7), Filter::NEAREST, 255, 0xFFFFFFFF, null, false],
    'scaled, linear, translucent, tinted' => [Affine::translation(4.3, 3.1)->multiply(Affine::scaling(1.7, 1.3)), Filter::LINEAR, 180, 0xFF8040C0, null, false],
    'turned, nearest' => [Affine::translation(24, 6)->multiply(Affine::rotation(M_PI / 6))->multiply(Affine::scaling(2, 2)), Filter::NEAREST, 255, 0xFFFFFFFF, null, false],
    'turned, windowed' => [Affine::translation(30, 4)->multiply(Affine::rotation(1.3))->multiply(Affine::scaling(2.5, 1.5)), Filter::LINEAR, 230, 0xFFFFFFFF, [1.5, 1.0, 7.2, 5.5], false],
    'scaled, through coverage' => [Affine::translation(3.25, 2.25)->multiply(Affine::scaling(3, 2.5)), Filter::NEAREST, 200, 0xFFFFFFFF, null, true],
]);

it('offers its pixels as memory for drawing in place', function () {
    $image = new PixmanFramebufferImage(FormatSpec::bgra8(), 8, 4);
    $memory = $image->memory();

    expect($memory)->not->toBeNull()
        ->and($image->pointer())->not->toBe(0)
        ->and($memory->width)->toBe(8)
        ->and($memory->height)->toBe(4)
        ->and($image->drewInto(new Region(0, 0, 2, 2), null))->toBe($image);
});

// Library to library is a pointer: C sources are read where they are (venusian-velvet/tests/Support).
moduleTicketChecks('pixman', fn (FormatSpec $f, int $w, int $h) => new PixmanFramebufferImage($f, $w, $h), FormatSpec::bgra8(), [
    'rgba8' => FormatSpec::rgba8(),
    'bgra8' => FormatSpec::bgra8(),
    'rgb888' => new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B24),
]);
