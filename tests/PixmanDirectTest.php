<?php

declare(strict_types=1);

/*
 * Runs inside venusian/topside's test suite, beside tests/VelvetGE/DirectDrawTest.php (its
 * directShapes()): see AGENTS.md.
 */

use Jovian\Rendering\Pixman\PixmanFramebufferDriver;
use Jovian\Rendering\Pixman\PixmanPixelStore;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\Endianness;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\VelvetGE\Drivers\Framebuffers\CLandFramebufferDriver;
use Surface\VelvetGE\Drivers\Rasterization\CLandRasterizationDriver;

it('offers its bits as PixelMemory, and ext-rasterize paints them with VelvetGE\'s exact bytes', function (FormatSpec $spec, Edges $edges): void {
    $pixman = (new PixmanFramebufferDriver())->full($spec, 40, 30);
    $velvet = (new CLandFramebufferDriver())->full($spec, 40, 30);
    $spanned = (new PixmanFramebufferDriver())->full($spec, 40, 30);
    $raster = (new CLandRasterizationDriver())->rasterizer(new Region(3, 2, 34, 25), $edges);

    $raster->fillBatch(directShapes(), $pixman);
    $raster->fillBatch(directShapes(), $velvet);
    $raster->fillBatch(directShapes(), $spanned, false);

    $sink = $pixman->memory();
    expect($sink)->toBeInstanceOf(PixelMemory::class)
        ->and($sink->stride * 30)->toBe(strlen($pixman->dump()))
        ->and(bin2hex($pixman->dump()))->toBe(bin2hex($velvet->dump()));

    // Through spans the blending is pixman's own: within its tolerance of the direct bytes.
    $worst = 0;
    foreach (str_split($pixman->dump()) as $i => $byte) {
        $worst = max($worst, abs(ord($byte) - ord($spanned->dump()[$i])));
    }
    expect($worst)->toBeLessThanOrEqual(PixmanPixelStore::TOLERANCE);
})->with([
    'rgba8' => [FormatSpec::rgba8()],
    'bgra8' => [FormatSpec::bgra8()],
    'rgb888' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B24)],
    'rgb565 little-endian' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B16, endianness: Endianness::LSB)],
])->with([Edges::ANTIALIASED, Edges::HARD]);
