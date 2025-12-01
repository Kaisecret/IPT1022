import os
from pathlib import Path
from collections import Counter

from PIL import Image, UnidentifiedImageError  # pip install pillow

# === CONFIG ===
# Root of your dataset: physique-check/backend/data/dataset
DATA_ROOT = Path(__file__).parent / "data" / "dataset"
IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".bmp", ".gif", ".webp"}

def is_image_file(path: Path) -> bool:
    return path.suffix.lower() in IMAGE_EXTS

def scan_dataset():
    if not DATA_ROOT.exists():
        print(f"[ERROR] Data root does not exist: {DATA_ROOT}")
        return

    print(f"[INFO] Scanning dataset at: {DATA_ROOT}")

    # We will walk **recursively** (female/male + their subfolders)
    class_counts = Counter()
    corrupted_files = []
    non_image_files = []
    tiny_images = []
    weird_aspect = []

    MIN_WIDTH = 128
    MIN_HEIGHT = 128
    MIN_ASPECT = 0.5   # h/w
    MAX_ASPECT = 2.0   # h/w

    # Example structure expected:
    # data/dataset/female/back_strong_female/image1.jpg
    # data/dataset/male/back_strong_male/image2.jpg
    # We'll treat the **last folder name** as the class,
    # e.g. "back_strong_female", "Frontview_male_Weak", etc.
    for root, dirs, files in os.walk(DATA_ROOT):
        root_path = Path(root)

        # skip the root itself if it is exactly DATA_ROOT
        if root_path == DATA_ROOT:
            continue

        # class name = last folder in this path
        class_name = root_path.name

        image_files = []
        for fname in files:
            fpath = root_path / fname
            if is_image_file(fpath):
                image_files.append(fpath)
            else:
                # ignore typical system/junk files
                if fpath.is_file():
                    non_image_files.append(fpath)
                    print(f"[WARN] Non-image file: {fpath}")

        if not image_files:
            continue

        print(f"\n[CLASS FOLDER] {class_name}  (path: {root_path})")
        print(f"  Images in this folder: {len(image_files)}")

        for img_path in image_files:
            try:
                with Image.open(img_path) as img:
                    w, h = img.size

                    if w < MIN_WIDTH or h < MIN_HEIGHT:
                        tiny_images.append((img_path, w, h))

                    aspect = h / w if w > 0 else 0
                    if aspect < MIN_ASPECT or aspect > MAX_ASPECT:
                        weird_aspect.append((img_path, w, h))

            except UnidentifiedImageError:
                corrupted_files.append(img_path)
                print(f"  [ERROR] Corrupted or unreadable image: {img_path}")
            except Exception as e:
                corrupted_files.append(img_path)
                print(f"  [ERROR] Failed to open {img_path}: {img_path}  ({e})")

        # Count images for this class
        class_counts[class_name] += len(image_files)

    # === SUMMARY ===
    print("\n========== DATASET SUMMARY ==========")
    total_images = sum(class_counts.values())
    print(f"Total images: {total_images}")
    print("Images per class:")
    for cls, cnt in class_counts.most_common():
        print(f"  {cls:25s}: {cnt}")

    print("\nNon-image files found:")
    if not non_image_files:
        print("  None ✅")
    else:
        for f in non_image_files:
            print(f"  {f}")

    print("\nCorrupted images found:")
    if not corrupted_files:
        print("  None ✅")
    else:
        for f in corrupted_files:
            print(f"  {f}")

    print("\nTiny images (below 128x128):")
    if not tiny_images:
        print("  None ✅")
    else:
        for path, w, h in tiny_images:
            print(f"  {path} ({w}x{h})")

    print("\nWeird aspect ratio images (h/w < 0.5 or > 2.0):")
    if not weird_aspect:
        print("  None ✅")
    else:
        for path, w, h in weird_aspect:
            print(f"  {path} ({w}x{h})")

    print("\n=====================================")

if __name__ == "__main__":
    scan_dataset()
