#!/usr/bin/env python3
"""Test images for the sandbox: a heavy 2400px hero, six 1800px photos, a small logo (needs Pillow)."""
import os, random, sys
from PIL import Image, ImageDraw, ImageFilter

out = sys.argv[1] if len(sys.argv) > 1 else 'img'
os.makedirs(out, exist_ok=True)

def photo(name, w, h, seed):
    random.seed(seed)
    im = Image.new('RGB', (w, h)); d = ImageDraw.Draw(im)
    for y in range(h):
        d.line([(0, y), (w, y)], fill=(int(40 + 120 * y / h), int(70 + 90 * y / h), int(110 + 100 * (1 - y / h))))
    for _ in range(900):
        x, y, r = random.randint(0, w), random.randint(0, h), random.randint(8, 90)
        d.ellipse([x - r, y - r, x + r, y + r], fill=tuple(random.randint(0, 255) for _ in range(3)))
    im = im.filter(ImageFilter.GaussianBlur(1.2)); px = im.load()
    for _ in range(w * h // 6):
        x, y = random.randrange(w), random.randrange(h); n = random.randint(-30, 30)
        px[x, y] = tuple(max(0, min(255, c + n)) for c in px[x, y])
    im.save(os.path.join(out, name), 'JPEG', quality=92)

photo('hero-original.jpg', 2400, 1600, 1)
for i in range(6):
    photo(f'work-{i + 1}.jpg', 1800, 1200, 10 + i)
