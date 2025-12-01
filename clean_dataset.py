import os
from pathlib import Path
from PIL import Image, UnidentifiedImageError

# Root of your dataset
DATA_ROOT = Path(__file__).parent / "data" / "dataset"
BAD_ROOT = DATA_ROOT / "_removed_bad"

IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".bmp", ".gif", ".webp"}

MIN_WIDTH = 128
MIN_HEIGHT = 128
MIN_ASPECT = 0.5   # h/w
MAX_ASPECT = 2.0   # h/w

def is_image_file(path: Path) -> bool:
    return path.suffix.lower() in IMAGE_EXTS

def ensure_dir(path: Path):
    path.mkdir(parents=True, exist_ok=True)

def clean_dataset():
    print(f"[INFO] Cleaning dataset at: {DATA_ROOT}")
    ensure_dir(BAD_ROOT)

    moved_count = 0

    for root, dirs, files in os.walk(DATA_ROOT):
        root_path = Path(root)

        # skip the "_removed_bad" folder itself
        if BAD_ROOT in root_path.parents or root_path == BAD_ROOT:
            continue

        for fname in files:
            fpath = root_path / fname
            if not is_image_file(fpath):
                continue

            try:
                with Image.open(fpath) as img:
                    w, h = img.size
            except (UnidentifiedImageError, OSError):
                # Corrupted → move out
                rel = fpath.relative_to(DATA_ROOT)
                target = BAD_ROOT / rel
                ensure_dir(target.parent)
                fpath.rename(target)
                moved_count += 1
                print(f"[MOVED - CORRUPTED] {fpath} -> {target}")
                continue

            aspect = h / w if w > 0 else 0

            too_small = (w < MIN_WIDTH or h < MIN_HEIGHT)
            weird_ratio = (aspect < MIN_ASPECT or aspect > MAX_ASPECT)

            if too_small or weird_ratio:
                rel = fpath.relative_to(DATA_ROOT)
                target = BAD_ROOT / rel
                ensure_dir(target.parent)
                fpath.rename(target)
                moved_count += 1
                reason = []
                if too_small:
                    reason.append(f"tiny {w}x{h}")
                if weird_ratio:
                    reason.append(f"aspect={aspect:.2f}")
                print(f"[MOVED - {' & '.join(reason)}] {fpath} -> {target}")

    print(f"\n[DONE] Moved {moved_count} bad images into: {BAD_ROOT}")

if __name__ == "__main__":
    clean_dataset()
