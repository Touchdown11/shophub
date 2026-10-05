#!/usr/bin/env python3
"""
ShopHub — demo artwork generator.

Creates the self-contained SVG product/category illustrations used by the
installer's demo catalogue. Everything is pure vector, so the store never
depends on an external image host.

    python3 tools/make_artwork.py

Outputs to assets/img/products/ and assets/img/categories/.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PRODUCTS_DIR = ROOT / "assets" / "img" / "products"
CATEGORIES_DIR = ROOT / "assets" / "img" / "categories"

GOLD = "#ffc400"
GOLD_SOFT = "#ffe07a"
SHADE = "#2a2a31"

# --------------------------------------------------------------------------- #
# Line-art glyphs (drawn in a 200 x 200 coordinate space)
# --------------------------------------------------------------------------- #
GLYPHS = {
    "headphones": """
        <path d="M52 108v-8a48 48 0 0 1 96 0v8"/>
        <rect x="38" y="104" width="26" height="48" rx="13"/>
        <rect x="136" y="104" width="26" height="48" rx="13"/>
        <path d="M64 146c6 12 18 18 36 18s30-6 36-18"/>""",
    "laptop": """
        <rect x="46" y="56" width="108" height="72" rx="7"/>
        <path d="M30 138h140l-10 14H40z"/>
        <path d="M62 74h76M62 88h48"/>""",
    "watch": """
        <circle cx="100" cy="100" r="38"/>
        <path d="M100 84v18l12 10"/>
        <path d="M84 64l4-16h24l4 16M84 136l4 16h24l4-16"/>
        <circle cx="100" cy="100" r="46" stroke-dasharray="2 7"/>""",
    "sunglasses": """
        <path d="M28 88h144"/>
        <path d="M44 88c0-12 10-20 24-20s24 8 24 20c0 14-10 24-24 24s-24-10-24-24z"/>
        <path d="M108 88c0-12 10-20 24-20s24 8 24 20c0 14-10 24-24 24s-24-10-24-24z"/>
        <path d="M92 90h16"/>""",
    "coat": """
        <path d="M100 62a10 10 0 1 0-10-10"/>
        <path d="M100 62l-4 12"/>
        <path d="M92 74 58 92l-8 30 20 6 6-14v46h48v-46l6 14 20-6-8-30-34-18z"/>
        <path d="M92 74l8 14 8-14"/>""",
    "boot": """
        <path d="M76 44h26v60c0 8 6 12 16 16l24 10c10 4 14 10 14 18v8H60c-10 0-16-6-16-16V92"/>",
        <path d="M76 44h26v20H76z"/>
        <path d="M60 140h100"/>""",
    "lamp": """
        <path d="M70 66h60l18 44H52z"/>
        <path d="M100 110v40"/>
        <path d="M72 164h56a14 14 0 0 0-14-14H86a14 14 0 0 0-14 14z"/>
        <path d="M118 58v-8h14"/>""",
    "bottle": """
        <path d="M84 30h32v18l8 12v96a12 12 0 0 1-12 12H88a12 12 0 0 1-12-12V60l8-12z"/>
        <path d="M84 84h32M84 112h32"/>""",
    "backpack": """
        <path d="M62 70h76a12 12 0 0 1 12 12v70a12 12 0 0 1-12 12H62a12 12 0 0 1-12-12V82a12 12 0 0 1 12-12z"/>
        <path d="M84 70V58a16 16 0 0 1 32 0v12"/>
        <path d="M50 108h100"/>
        <circle cx="100" cy="128" r="8"/>""",
    "serum": """
        <path d="M88 26h24v16h-24z"/>
        <path d="M82 42h36v18l6 10v92a10 10 0 0 1-10 10H86a10 10 0 0 1-10-10V70l6-10z"/>
        <path d="M84 96h32M84 118h22"/>""",
    "mug": """
        <path d="M56 66h76v64a22 22 0 0 1-22 22H78a22 22 0 0 1-22-22z"/>
        <path d="M132 84h12a18 18 0 0 1 0 36h-12"/>
        <path d="M56 92h76"/>""",
    "wallet": """
        <path d="M40 66h120v84H40z"/>
        <path d="M40 90h120"/>
        <path d="M118 90h42v34h-42a10 10 0 0 1 0-34z"/>
        <circle cx="132" cy="107" r="5"/>""",
    "speaker": """
        <rect x="58" y="42" width="84" height="124" rx="16"/>
        <circle cx="100" cy="86" r="22"/>
        <circle cx="100" cy="134" r="12"/>
        <path d="M84 56h32"/>""",
    "camera": """
        <path d="M74 54h18l8-12h20l8 12h18a10 10 0 0 1 10 10v74a10 10 0 0 1-10 10H48a10 10 0 0 1-10-10V64a10 10 0 0 1 10-10z"/>
        <circle cx="100" cy="104" r="26"/>
        <circle cx="100" cy="104" r="12"/>
        <path d="M150 76h10"/>""",
    "powerbank": """
        <rect x="60" y="40" width="80" height="128" rx="14"/>
        <path d="M78 70h44M78 92h44M78 114h44"/>
        <path d="M86 148h28"/>""",
    "mat": """
        <ellipse cx="128" cy="104" rx="26" ry="52"/>
        <path d="M52 52h76v104H52z"/>
        <path d="M52 52h76"/>
        <path d="M128 52a26 52 0 0 1 0 104"/>""",
    "helmet": """
        <path d="M100 42a52 52 0 0 1 52 52v26H48V94a52 52 0 0 1 52-52z"/>
        <path d="M60 74h80M74 58h52"/>
        <path d="M48 120h104"/>
        <path d="M78 120c0 12 10 20 22 20s22-8 22-20"/>""",
    "board": """
        <rect x="44" y="70" width="96" height="64" rx="10"/>
        <path d="M56 120c22-10 40-10 72 0"/>
        <path d="M140 92h20a8 8 0 0 1 0 16h-20"/>""",
    "bedding": """
        <path d="M40 62h120v40H40z"/>
        <path d="M34 102h132v52H34z"/>
        <path d="M34 138h132"/>
        <path d="M52 62v-12h44v12"/>""",
    "jumper": """
        <path d="M78 46 48 68l-12 32 22 8 8-16v72h68V92l8 16 22-8-12-32-30-22z"/>
        <path d="M78 46c4 12 10 18 22 18s18-6 22-18"/>
        <path d="M78 122h44"/>""",
}

# --------------------------------------------------------------------------- #
# Catalogue: slug -> (glyph, caption, accent variant)
# --------------------------------------------------------------------------- #
PRODUCT_ART = {
    "auron-pro-wireless-headphones": ("headphones", "Auron Pro", 0),
    "nimbus-14-ultrabook": ("laptop", "Nimbus 14", 1),
    "pulsefit-smartwatch-series-6": ("watch", "PulseFit S6", 2),
    "lumen-4k-action-camera": ("camera", "Lumen 4K", 3),
    "volt-20000mah-power-bank": ("powerbank", "Volt 20K", 4),
    "echo-mini-bluetooth-speaker": ("speaker", "Echo Mini", 5),
    "cashmere-blend-overcoat": ("coat", "Marlow Coat", 1),
    "merino-crewneck-jumper": ("jumper", "Merino Crew", 2),
    "selvedge-denim-jacket": ("coat", "Ironhold Jacket", 3),
    "leather-weekender-duffle": ("backpack", "Ashcroft Duffle", 4),
    "suede-chelsea-boots": ("boot", "Chelsea Boot", 0),
    "nero-ceramic-coffee-set": ("mug", "Nero Ceramics", 5),
    "aurelia-table-lamp": ("lamp", "Aurelia Lamp", 1),
    "linen-bedding-set-queen": ("bedding", "Northloom Linen", 2),
    "ebony-oak-serving-board": ("board", "Oak Board", 3),
    "diffuser-candle-gift-set": ("serum", "Aurelia Set", 4),
    "vitamin-c-radiance-serum": ("serum", "Lumea Serum", 0),
    "oud-amber-eau-de-parfum": ("bottle", "Maison Noir", 1),
    "silk-recovery-hair-mask": ("serum", "Silk Mask", 2),
    "clay-detox-face-mask": ("mug", "Clay Mask", 3),
    "trail-master-hiking-backpack-35l": ("backpack", "Ridgeway 35L", 4),
    "carbon-road-helmet": ("helmet", "Velo Carbon", 5),
    "yoga-alignment-mat": ("mat", "Stillform Mat", 0),
    "insulated-steel-bottle-750ml": ("bottle", "Steel 750", 1),
    "heritage-automatic-watch": ("watch", "Heritage 38", 2),
    "titanium-aviator-sunglasses": ("sunglasses", "Aviator", 3),
    "full-grain-leather-wallet": ("wallet", "Ashcroft Wallet", 4),
}

CATEGORY_ART = {
    "electronics": ("headphones", "Electronics", 0),
    "fashion": ("coat", "Fashion", 1),
    "home-living": ("lamp", "Home & Living", 2),
    "beauty-care": ("serum", "Beauty & Care", 3),
    "sports-outdoors": ("backpack", "Sports & Outdoors", 4),
    "accessories": ("watch", "Accessories", 5),
}

# Background treatments keep a set of products from looking identical.
VARIANTS = [
    ("#141419", "#0b0b0d", 62.0, 30.0),
    ("#17171d", "#0c0c10", 38.0, 26.0),
    ("#121218", "#0a0a0e", 74.0, 34.0),
    ("#181820", "#0b0b10", 26.0, 28.0),
    ("#15151b", "#0c0c11", 58.0, 24.0),
    ("#131318", "#09090c", 44.0, 32.0),
]


def build_svg(glyph: str, caption: str, variant: int, size: int = 900, with_caption: bool = True) -> str:
    # XML-escape the caption so "Home & Living" stays valid markup.
    caption = caption.replace("&", "&amp;")
    top, bottom, cx, cy = VARIANTS[variant % len(VARIANTS)]
    glyph_markup = GLYPHS[glyph]
    caption_markup = ""
    if with_caption:
        caption_markup = (
            f'<text x="{size/2}" y="{size - 78}" text-anchor="middle" '
            f'font-family="Helvetica, Arial, sans-serif" font-size="30" '
            f'letter-spacing="7" fill="#8d8d97">{caption.upper()}</text>'
            f'<text x="{size/2}" y="{size - 38}" text-anchor="middle" '
            f'font-family="Helvetica, Arial, sans-serif" font-size="22" '
            f'letter-spacing="11" fill="#4d4d55">SHOPHUB</text>'
        )

    return f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {size} {size}" width="{size}" height="{size}" role="img" aria-label="{caption}">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="{top}"/><stop offset="1" stop-color="{bottom}"/>
    </linearGradient>
    <radialGradient id="spot" cx="{cx}%" cy="{cy}%" r="62%">
      <stop offset="0" stop-color="{GOLD}" stop-opacity="0.20"/>
      <stop offset="0.55" stop-color="{GOLD}" stop-opacity="0.05"/>
      <stop offset="1" stop-color="{GOLD}" stop-opacity="0"/>
    </radialGradient>
    <linearGradient id="ink" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="{GOLD_SOFT}"/><stop offset="1" stop-color="{GOLD}"/>
    </linearGradient>
  </defs>
  <rect width="{size}" height="{size}" fill="url(#bg)"/>
  <rect width="{size}" height="{size}" fill="url(#spot)"/>
  <circle cx="{size/2}" cy="{size*0.44:.0f}" r="{size*0.27:.0f}" fill="none" stroke="{GOLD}" stroke-opacity="0.16" stroke-width="1.5"/>
  <circle cx="{size/2}" cy="{size*0.44:.0f}" r="{size*0.335:.0f}" fill="none" stroke="{GOLD}" stroke-opacity="0.08" stroke-width="1"/>
  <g transform="translate({size/2 - 250}, {size*0.44 - 250:.0f}) scale(2.5)"
     fill="none" stroke="url(#ink)" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"
     opacity="0.96">
    {glyph_markup}
  </g>
  {caption_markup}
</svg>
"""


def main() -> None:
    PRODUCTS_DIR.mkdir(parents=True, exist_ok=True)
    CATEGORIES_DIR.mkdir(parents=True, exist_ok=True)

    written = 0
    for slug, (glyph, caption, variant) in PRODUCT_ART.items():
        # Main image plus two gallery views (different framing, no caption).
        (PRODUCTS_DIR / f"{slug}.svg").write_text(build_svg(glyph, caption, variant), encoding="utf-8")
        (PRODUCTS_DIR / f"{slug}-a.svg").write_text(build_svg(glyph, caption, variant, 900, False), encoding="utf-8")
        (PRODUCTS_DIR / f"{slug}-b.svg").write_text(
            build_svg(glyph, caption, (variant + 2) % len(VARIANTS), 900, False), encoding="utf-8"
        )
        written += 3

    for slug, (glyph, caption, variant) in CATEGORY_ART.items():
        (CATEGORIES_DIR / f"{slug}.svg").write_text(
            build_svg(glyph, caption, variant, 900, False), encoding="utf-8"
        )
        written += 1

    print(f"Wrote {written} SVG files to assets/img/")


if __name__ == "__main__":
    main()
