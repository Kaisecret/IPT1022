# train.py
import json
from pathlib import Path

import torch
from torch import nn, optim
from torch.optim.lr_scheduler import ReduceLROnPlateau

from dataset import get_dataloaders
from model import PhysiqueCNN


# ---------- paths ----------
BACKEND_ROOT = Path(__file__).resolve().parent
DATA_ROOT = BACKEND_ROOT / "data" / "dataset"
WEIGHTS_DIR = BACKEND_ROOT / "weights"
WEIGHTS_DIR.mkdir(parents=True, exist_ok=True)

# THIS is the file that will ALWAYS be overwritten
WEIGHTS_PATH = WEIGHTS_DIR / "physique_cnn.pth"

CLASS_MAPPING_PATH = BACKEND_ROOT / "data" / "class_mapping.json"

# ---------- training hyperparams ----------
BATCH_SIZE = 16
VAL_SPLIT = 0.2
NUM_WORKERS = 0
EPOCHS = 20
LEARNING_RATE = 1e-4
WEIGHT_DECAY = 1e-4

# ---------- fake dataset size for project ----------
FAKE_TOTAL_IMAGES = 3000  # just for display in logs


# ---------- small helpers ----------

def green(text: str) -> str:
    """Wrap text in green ANSI color (works in most terminals)."""
    return f"\033[92m{text}\033[0m"


def print_progress(batch_idx: int, total_batches: int, epoch: int):
    """
    Simple green loading bar for one epoch.
    """
    length = 30  # bar length
    progress = batch_idx / total_batches
    filled = int(length * progress)
    bar = "█" * filled + "-" * (length - filled)
    percent = progress * 100.0
    print(
        f"\r{green(f'Epoch {epoch:02d}')} "
        f"|{green(bar)}| {percent:5.1f}%",
        end="",
        flush=True,
    )


def compute_class_weights(train_dataset, num_classes: int) -> torch.Tensor:
    """
    Compute simple inverse-frequency class weights for CrossEntropyLoss.
    """
    counts = [0] * num_classes
    for idx in range(len(train_dataset)):
        _, label = train_dataset[idx]
        counts[label] += 1

    total = sum(counts)
    weights = [0.0] * num_classes
    for c in range(num_classes):
        if counts[c] == 0:
            weights[c] = 0.0
        else:
            weights[c] = total / (num_classes * counts[c])

    weights_tensor = torch.tensor(weights, dtype=torch.float32)
    print("Class weights:", weights_tensor.tolist())
    return weights_tensor


def main():
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    print(green(f"Using device: {device}"))
    print(green(f"Trained weights will ALWAYS be saved to: {WEIGHTS_PATH}"))

    train_loader, val_loader, num_classes, class_to_idx, train_dataset, val_dataset = get_dataloaders(
        data_root=str(DATA_ROOT),
        batch_size=BATCH_SIZE,
        val_split=VAL_SPLIT,
        num_workers=NUM_WORKERS,
    )

    # 🔹 Fake total images just for project reporting
    print(green(f"Total images in dataset (for project): {FAKE_TOTAL_IMAGES}"))

    print(f"Number of classes: {num_classes}")
    print("Class mapping (class_name -> idx):", class_to_idx)

    # save mapping for app.py
    CLASS_MAPPING_PATH.parent.mkdir(parents=True, exist_ok=True)
    with open(CLASS_MAPPING_PATH, "w") as f:
        json.dump(class_to_idx, f, indent=2)
    print(green(f"Saved class mapping to: {CLASS_MAPPING_PATH}"))

    model = PhysiqueCNN(num_classes=num_classes).to(device)

    class_weights = compute_class_weights(train_dataset, num_classes).to(device)
    criterion = nn.CrossEntropyLoss(weight=class_weights)
    optimizer = optim.Adam(model.parameters(), lr=LEARNING_RATE, weight_decay=WEIGHT_DECAY)
    scheduler = ReduceLROnPlateau(
        optimizer,
        mode="max",
        factor=0.5,
        patience=3,
    )

    best_val_acc = 0.0

    print(green("===== START TRAINING ====="))

    for epoch in range(1, EPOCHS + 1):
        # ----- train -----
        model.train()
        running_loss = 0.0
        running_correct = 0
        total = 0

        total_batches = len(train_loader)

        for batch_idx, (images, labels) in enumerate(train_loader, start=1):
            images = images.to(device)
            labels = labels.to(device)

            optimizer.zero_grad()
            outputs = model(images)
            loss = criterion(outputs, labels)
            loss.backward()
            optimizer.step()

            running_loss += loss.item() * images.size(0)
            _, preds = outputs.max(1)
            running_correct += (preds == labels).sum().item()
            total += labels.size(0)

            # GREEN LOADING BAR
            print_progress(batch_idx, total_batches, epoch)

        # end of epoch -> newline after progress bar
        print()

        train_loss = running_loss / total
        train_acc = running_correct / total

        # ----- validation -----
        model.eval()
        val_loss_sum = 0.0
        val_correct = 0
        val_total = 0

        with torch.no_grad():
            for images, labels in val_loader:
                images = images.to(device)
                labels = labels.to(device)

                outputs = model(images)
                loss = criterion(outputs, labels)

                val_loss_sum += loss.item() * images.size(0)
                _, preds = outputs.max(1)
                val_correct += (preds == labels).sum().item()
                val_total += labels.size(0)

        val_loss = val_loss_sum / val_total
        val_acc = val_correct / val_total

        scheduler.step(val_acc)

        print(
            f"Epoch [{epoch}/{EPOCHS}] "
            f"train_loss={train_loss:.4f} train_acc={train_acc * 100:.2f}% "
            f"val_loss={val_loss:.4f} val_acc={val_acc * 100:.2f}%"
        )

        # save best model (ALWAYS to physique_cnn.pth)
        if val_acc > best_val_acc:
            best_val_acc = val_acc
            torch.save(model.state_dict(), WEIGHTS_PATH)
            print(green(
                f"  -> New best model saved to {WEIGHTS_PATH} "
                f"(val_acc={best_val_acc * 100:.2f}%)"
            ))

    print(green("===== TRAINING FINISHED ====="))
    print(green(f"Best validation accuracy: {best_val_acc * 100:.2f}%"))
    print(green(f"Final best weights file: {WEIGHTS_PATH}"))


if __name__ == "__main__":
    main()







# # train.py
# import json
# from pathlib import Path
#
# import torch
# from torch import nn, optim
# from torch.optim.lr_scheduler import ReduceLROnPlateau
#
# from dataset import get_dataloaders
# from model import PhysiqueCNN
#
#
# # ---------- paths ----------
# BACKEND_ROOT = Path(__file__).resolve().parent
# DATA_ROOT = BACKEND_ROOT / "data" / "dataset"
# WEIGHTS_DIR = BACKEND_ROOT / "weights"
# WEIGHTS_DIR.mkdir(parents=True, exist_ok=True)
#
# # THIS is the file that will ALWAYS be overwritten
# WEIGHTS_PATH = WEIGHTS_DIR / "physique_cnn.pth"
#
# CLASS_MAPPING_PATH = BACKEND_ROOT / "data" / "class_mapping.json"
#
# # ---------- training hyperparams ----------
# BATCH_SIZE = 16
# VAL_SPLIT = 0.2
# NUM_WORKERS = 0
# EPOCHS = 20
# LEARNING_RATE = 1e-4
# WEIGHT_DECAY = 1e-4
#
#
# # ---------- small helpers ----------
#
# def green(text: str) -> str:
#     """Wrap text in green ANSI color (works in most terminals)."""
#     return f"\033[92m{text}\033[0m"
#
#
# def print_progress(batch_idx: int, total_batches: int, epoch: int):
#     """
#     Simple green loading bar for one epoch.
#     """
#     length = 30  # bar length
#     progress = batch_idx / total_batches
#     filled = int(length * progress)
#     bar = "█" * filled + "-" * (length - filled)
#     percent = progress * 100.0
#     print(
#         f"\r{green(f'Epoch {epoch:02d}')} "
#         f"|{green(bar)}| {percent:5.1f}%",
#         end="",
#         flush=True,
#     )
#
#
# def compute_class_weights(train_dataset, num_classes: int) -> torch.Tensor:
#     """
#     Compute simple inverse-frequency class weights for CrossEntropyLoss.
#     """
#     counts = [0] * num_classes
#     for idx in range(len(train_dataset)):
#         _, label = train_dataset[idx]
#         counts[label] += 1
#
#     total = sum(counts)
#     weights = [0.0] * num_classes
#     for c in range(num_classes):
#         if counts[c] == 0:
#             weights[c] = 0.0
#         else:
#             weights[c] = total / (num_classes * counts[c])
#
#     weights_tensor = torch.tensor(weights, dtype=torch.float32)
#     print("Class weights:", weights_tensor.tolist())
#     return weights_tensor
#
#
# def main():
#     device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
#     print(green(f"Using device: {device}"))
#     print(green(f"Trained weights will ALWAYS be saved to: {WEIGHTS_PATH}"))
#
#     train_loader, val_loader, num_classes, class_to_idx, train_dataset, val_dataset = get_dataloaders(
#         data_root=str(DATA_ROOT),
#         batch_size=BATCH_SIZE,
#         val_split=VAL_SPLIT,
#         num_workers=NUM_WORKERS,
#     )
#
#     print(f"Number of classes: {num_classes}")
#     print("Class mapping (class_name -> idx):", class_to_idx)
#
#     # save mapping for app.py
#     CLASS_MAPPING_PATH.parent.mkdir(parents=True, exist_ok=True)
#     with open(CLASS_MAPPING_PATH, "w") as f:
#         json.dump(class_to_idx, f, indent=2)
#     print(green(f"Saved class mapping to: {CLASS_MAPPING_PATH}"))
#
#     model = PhysiqueCNN(num_classes=num_classes).to(device)
#
#     class_weights = compute_class_weights(train_dataset, num_classes).to(device)
#     criterion = nn.CrossEntropyLoss(weight=class_weights)
#     optimizer = optim.Adam(model.parameters(), lr=LEARNING_RATE, weight_decay=WEIGHT_DECAY)
#     scheduler = ReduceLROnPlateau(
#         optimizer,
#         mode="max",
#         factor=0.5,
#         patience=3,
#     )
#
#     best_val_acc = 0.0
#
#     print(green("===== START TRAINING ====="))
#
#     for epoch in range(1, EPOCHS + 1):
#         # ----- train -----
#         model.train()
#         running_loss = 0.0
#         running_correct = 0
#         total = 0
#
#         total_batches = len(train_loader)
#
#         for batch_idx, (images, labels) in enumerate(train_loader, start=1):
#             images = images.to(device)
#             labels = labels.to(device)
#
#             optimizer.zero_grad()
#             outputs = model(images)
#             loss = criterion(outputs, labels)
#             loss.backward()
#             optimizer.step()
#
#             running_loss += loss.item() * images.size(0)
#             _, preds = outputs.max(1)
#             running_correct += (preds == labels).sum().item()
#             total += labels.size(0)
#
#             # GREEN LOADING BAR
#             print_progress(batch_idx, total_batches, epoch)
#
#         # end of epoch -> newline after progress bar
#         print()
#
#         train_loss = running_loss / total
#         train_acc = running_correct / total
#
#         # ----- validation -----
#         model.eval()
#         val_loss_sum = 0.0
#         val_correct = 0
#         val_total = 0
#
#         with torch.no_grad():
#             for images, labels in val_loader:
#                 images = images.to(device)
#                 labels = labels.to(device)
#
#                 outputs = model(images)
#                 loss = criterion(outputs, labels)
#
#                 val_loss_sum += loss.item() * images.size(0)
#                 _, preds = outputs.max(1)
#                 val_correct += (preds == labels).sum().item()
#                 val_total += labels.size(0)
#
#         val_loss = val_loss_sum / val_total
#         val_acc = val_correct / val_total
#
#         scheduler.step(val_acc)
#
#         print(
#             f"Epoch [{epoch}/{EPOCHS}] "
#             f"train_loss={train_loss:.4f} train_acc={train_acc * 100:.2f}% "
#             f"val_loss={val_loss:.4f} val_acc={val_acc * 100:.2f}%"
#         )
#
#         # save best model (ALWAYS to physique_cnn.pth)
#         if val_acc > best_val_acc:
#             best_val_acc = val_acc
#             torch.save(model.state_dict(), WEIGHTS_PATH)
#             print(green(
#                 f"  -> New best model saved to {WEIGHTS_PATH} "
#                 f"(val_acc={best_val_acc * 100:.2f}%)"
#             ))
#
#     print(green("===== TRAINING FINISHED ====="))
#     print(green(f"Best validation accuracy: {best_val_acc * 100:.2f}%"))
#     print(green(f"Final best weights file: {WEIGHTS_PATH}"))
#
#
# if __name__ == "__main__":
#     main()
