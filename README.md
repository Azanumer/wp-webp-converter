# WP WebP Converter

Converts JPEG and PNG uploads to WebP automatically, and bulk-converts your existing Media Library in batches. WebP images are typically 25–35% smaller than the same JPEG/PNG at equal quality — faster pages, no visible difference, no external service.

## What it does

- **Convert on upload** — every new JPEG/PNG upload is converted to WebP via `WP_Image_Editor` (GD or Imagick). Thumbnails are then generated from the WebP file.
- **Bulk convert** — Media → WebP Converter converts existing images in batches of 20 with auto-continue, so a large library converts itself without timeouts.
- **Keep original (optional)** — keep the JPEG/PNG on disk as a fallback and serve the WebP instead; off by default, the original is replaced to save disk space.
- **Quality setting** — WebP quality 1–100 (default 82), overridable per site with the `wwc_quality` filter.
- **Graceful failure** — if the server can't write WebP, or a conversion fails, the original file is left untouched and the upload proceeds normally.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- GD or Imagick with WebP support (the plugin tells you on its Tools page if WebP is unavailable)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from Plugins.
3. Tune quality in Settings → WebP Converter.
4. Convert your existing library from Media → WebP Converter.

## Filters

| Filter | Purpose |
|---|---|
| `wwc_quality` | Override the WebP quality (int 1–100). |
| `wwc_convert_on_upload` | Return `false` to skip conversion for a specific upload. Receives the upload array. |

## Uninstall

Deletes the plugin option and the `_wwc_webp_file` / `_wwc_converted` postmeta markers. Converted WebP files are left in place — they are your media.

MIT licensed.
