# venusian-pixman

The `pixman` framebuffer module for Velvet. It keeps a framebuffer's pixels in a pixman image through
[ext-pixman](https://github.com/php-io-extensions/pixman), and pixman does every write, copy, conversion and blend.

```bash
composer require jovian/venusian-pixman
```

The service provider registers `pixman` on Velvet's framebuffer manager. Pick it in Velvet's config:

```php
// config/velvet.php
'cpu' => ['framebuffers' => ['default' => env('VELVET_FRAMEBUFFER', 'pixman')]],
```

## What it does

`VelvetPixmanFramebufferDriver::make()` gives one `PixmanFramebufferImage` a framebuffer. It holds every format pixman
has a byte layout for (`PixmanFormats`), as the framework defines those bytes: straight colours, so reads hand back the
bytes as they are. A format pixman has no layout for is refused.

- **The core** (pixel values, fills, segments, clears, RGBA8 in and out, blits to and from other framebuffers,
  scrolling) does not blend, and gives lustrous-php's bytes.
- **Compositing and gradients** blend with pixman's `OVER` and a premultiplied source: spans and masks of a solid
  colour, gradients, and another framebuffer's pixels. Over a translucent destination pixman's arithmetic is not
  Lustrous's straight-alpha lerp, so blended bytes land within `PixmanFramebufferImage::TOLERANCE` (13, 5% of 255) of
  lustrous-php's.
- **Drawing in place.** `memory()` is pixman's bits, so a direct drawer paints them where they are.
- **No effects** (filter chains, Kawase levels, glass) and no kinds: pixman has one image.

## Tests

`tests/Framebuffers/PixmanFramebufferImageTest.php` checks it against lustrous-php: the core byte for byte, blends
within `TOLERANCE`. It runs inside venusian-velvet's suite, with this package's classes on the autoloader.

## License

MIT.
