# venusian-pixman

The `pixman` framebuffer driver for Venusian Topside. It keeps a framebuffer's pixels in pixman images through [ext-pixman](https://github.com/php-io-extensions/pixman) and gives the same bytes as VelvetGE's `native` and `ext` drivers for every call.

```bash
composer require jovian/venusian-pixman
```

The service provider registers the driver on Topside's framebuffer manager. Pick it by name, or leave the default `auto`:

```php
// config/rendering.php
'framebuffer' => ['default' => 'pixman'],
```

## What it does

`PixmanFramebufferDriver` extends VelvetGE's `StoreFramebufferDriver`, so all five kinds work (full, dirty, ePaper, paged, ring). It mints one store per surface:

- **Formats pixman can hold byte for byte** get a `PixmanPixelStore`. pixman's memory matches VelvetGE's bytes exactly for these formats on a little-endian host:

  | FormatSpec | pixman |
  |---|---|
  | RGBA8888, channel order RGBA / BGRA / ARGB / ABGR | `a8b8g8r8` / `a8r8g8b8` / `b8g8r8a8` / `r8g8b8a8` |
  | RGB888, RGB / BGR | `b8g8r8` / `r8g8b8` |
  | RGB565 little-endian, RGB / BGR | `r5g6b5` / `b5g6r5` |

  Storage, fills, rects, copies, blits and loads use pixman's SRC operator, which copies pixels exactly. Wherever a value goes through VelvetGE's arithmetic (encoding a pixel value, converting to another format, building a plane, widening 565 to RGBA8), the store asks a VelvetGE store for the answer, so the result matches by construction. `pointer()` is the address of pixman's bits.
- **Every other format** (mono, palettes, planar, 444, 666, big-endian 565, bottom-up rows) gets the store VelvetGE would give it: ext-fb's when it is loaded, PHP's otherwise. So the driver holds every format and is safe as the first choice under `auto`.

`PixmanPixelStore` implements Topside's core tier and its painting tier:

- **Core** (storage, fills, copies, blits, loads, conversions) is byte-exact.
- **Spans and masks** on the 32- and 24-bit layouts blend with pixman's `OVER`: a premultiplied solid through an `a8` coverage mask. That is VelvetGE's straight-alpha blend with pixman's rounding, at most **2** off per byte (`PixmanPixelStore::TOLERANCE`, measured over random spans and masks at every alpha and coverage). Angel allowed a tolerance for library drivers on 2026-11-13.
- **565 spans and masks, gradients and image painting** run VelvetGE's arithmetic on lent pixels, so they're exact. pixman widens 565 by bit replication before blending, which lands up to one 565 step (9 in RGBA8) off VelvetGE.
- **Effects** (filters, Kawase, glass) come from Topside's `CompletingPixelStore`.
- **Direct painting.** `memory()` answers a `PixelMemory` over pixman's bits (they hold exactly what `bytes()` answers: straight colours, rows padded to 4 bytes). VelvetGE's `DirectDraw` then lets a producer that draws into that memory work in place: ext-rasterize's scanner on every layout pixman holds, ext-raycast's marcher on the four 32-bit byte orders, each with VelvetGE's exact arithmetic. Mask scanners (PlutoVG) land through `paintMasks()`. So the same shapes can come out up to `TOLERANCE` apart depending on the path: direct (exact) or through `paintSpans()` (pixman's `OVER`). Both are allowed. ext-rasterize engages for packed rows only: 32-bit always, 24-bit when `width * 3` is a multiple of 4, 565 when the width is even; otherwise the batch goes through spans.

## Tests

`tests/PixmanParityTest.php` checks every mapped format against `PHPLandPixelStore`: the core tier call by call, exactly, and painting within `TOLERANCE` (exactly for 565). Topside's 43 golden fixtures pass through `FixtureRunner::run($driver, $file, tolerance: PixmanPixelStore::TOLERANCE)`. They pass at 0 too, because none of them exercises a translucent blend on a pixman layout. `tests/PixmanDirectTest.php` paints topside's direct-fill shapes with ext-rasterize into pixman frames (RGBA8, BGRA8, RGB888, little-endian 565; hard and antialiased): byte for byte what it paints into ext-fb's store, and within `TOLERANCE` of the spans path.

## License

MIT.
