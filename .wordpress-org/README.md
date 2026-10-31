# WordPress.org directory assets

These files are uploaded to the WP.org SVN repository under `assets/` (NOT inside the plugin zip). They render the plugin page on https://wordpress.org/plugins/qbitflow-for-woocommerce/ — banner at the top and icon in the plugin lists.

## Files

| File | Dimensions | Purpose |
|------|------------|---------|
| `banner-1544x500.png` | 1544×500 | Header banner (high-DPI) |
| `banner-772x250.png` | 772×250 | Header banner (low-DPI fallback) |
| `icon-256x256.png` | 256×256 | Plugin list icon (high-DPI) |
| `icon-128x128.png` | 128×128 | Plugin list icon (low-DPI) |
| `screenshot-1.png` … `screenshot-8.png` | 1280×800 | Listing screenshots — numbered to match the `== Screenshots ==` section in `readme.txt` |

## Status

The banners and icons use the plugin's logo (`assets/img/qbitflow-icon.png`) and QBitFlow's palette and fonts (Switzer, IBM Plex Mono); Switzer's licence forbids redistributing it, so no font file is in this repository. The screenshots show a demo "Acme Store" (captions in `readme.txt`).

## Publishing flow

WP.org expects this layout in the SVN repo:

```
qbitflow-for-woocommerce/
├── trunk/                    ← plugin code goes here
└── assets/                   ← contents of this folder go here
    ├── banner-1544x500.png
    ├── banner-772x250.png
    ├── icon-256x256.png
    └── icon-128x128.png
```
