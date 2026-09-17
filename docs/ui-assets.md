# FolderFolio — UI assets

Drop-in brand and token assets for `moustakalis/folderfolio`. Every path below
is relative to the **repo root**, so unzipping over the repo puts each file
where it belongs.

```
.wordpress-org/                  ← wp.org directory listing (never in the plugin zip)
  icon.svg                         preferred: wp.org uses SVG and falls back to PNG
  icon-128x128.png
  icon-256x256.png
  banner-772x250.png               the banner that ships
  banner-1544x500.png              same artwork at 2x, for retina
  src/
    icon-src.svg
    banner-772x250.svg             editable source — type is live, not outlined
    banner-1544x500.svg
    render.mjs                     regenerates the four PNGs from the SVGs
assets/
  brand/                         ← source SVGs; excluded from the plugin zip
    mark.svg                       currentColor — the one to use at runtime
    mark-ink.svg                   #1d2327, for light surfaces
    mark-light.svg                 #f0f0f1, for dark surfaces
    block-icon.svg                 24px, currentColor
  src/
    core/
      _tokens.css                  colour + geometry tokens, per admin scheme
includes/
  Admin/
    Brand.php                    ← the mark as a data URI for add_menu_page()
```

## Why the PNGs live in `.wordpress-org/` and not `../../../../Downloads/ui-assets/assets`

`bin/build-zip.sh` allowlists `assets/build` — nothing else under `../../../../Downloads/ui-assets/assets`
reaches a user archive. Putting directory artwork there would make the folder
ambiguous without shipping anything. `.wordpress-org/` is the convention the
wp.org deploy actions read, it maps to the SVN `/assets` directory, and the
name states that it is listing artwork rather than plugin code.

No change to `build-zip.sh` is needed. `assets/brand/` and `.wordpress-org/`
are both outside the allowlist, so both are already excluded. `FORBIDDEN` will
not trip on either.

## Wiring the menu icon

```php
use FolderFolio\Admin\Brand;

add_menu_page(
    __( 'FolderFolio', 'folderfolio' ),
    __( 'FolderFolio', 'folderfolio' ),
    'upload_files',
    'folderfolio',
    [ $this, 'render_settings' ],
    Brand::menu_icon(),
    11 // directly under Media
);
```

`Brand::menu_icon()` returns a base64 data URI of the mark drawn in
`currentColor`, so core's menu CSS colours it `#a7aaad` at rest and `#fff` when
current — the same as every other menu item, under all eight schemes. Do not
substitute a PNG or a red icon here: red is for the directory icons and the
banner only.

`Brand::mark()` returns the raw SVG for inlining — the block icon, the settings
header, the wizard.

## Tokens

`_tokens.css` is an `@import`-able partial, not a built file. Import it at the
top of the admin entry stylesheet:

```css
@import './_tokens.css';
```

It sets one `.folderfolio` block of custom properties for Fresh, then overrides
only what changes under `.admin-color-modern` and `.admin-color-midnight`. Core
puts the scheme class on `<body>`, so a scoped rail element inherits the right
values with no JS. The remaining five core schemes fall through to Fresh, which
is correct — they differ from Fresh only in accent, and picking up the wrong
accent is worse than picking up Fresh's.

Consume them as `var(--ff-sel)`, `var(--ff-line)` and so on. No hex literals in
component CSS.

## Regenerating the PNGs

The banners set type in Archivo, which no rasteriser has installed. Rather than
ship outlined paths that cannot be edited later, `render.mjs` renders through
headless Chromium with the webfont loaded and awaited:

```
npm i -D playwright && npx playwright install chromium
node .wordpress-org/src/render.mjs
```

The PNGs in this bundle were produced the same way and are ready to commit —
run the script only after editing an SVG.

## Notes

- **Banners must be raster.** wp.org accepts PNG/JPG for banners and
  screenshots; only the icon may be SVG. `icon.svg` plus the two icon PNGs
  covers both paths.
- **Screenshots.** wp.org reads `screenshot-1.png`, `screenshot-2.png` … from
  this same folder, in the order `readme.txt`'s Screenshots section lists them.
  None are included here — capture them from a real install, not from the mocks.
- The mark is a construction of mine rather than a designer's. Worth a
  professional pass before submission, particularly the 45° chamfer at 16px.
