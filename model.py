# model.py
from typing import Optional
import torch
from torch import nn
from torchvision import models


class PhysiqueCNN(nn.Module):
    """
    Simple ResNet18-based classifier.
    Final layer size is num_classes (10 with your new dataset).
    """

    def __init__(self, num_classes: int):
        super().__init__()

        # Handle different torchvision versions
        try:
            backbone = models.resnet18(weights=models.ResNet18_Weights.DEFAULT)
        except AttributeError:
            backbone = models.resnet18(pretrained=True)

        in_features = backbone.fc.in_features
        backbone.fc = nn.Linear(in_features, num_classes)
        self.backbone = backbone

    def forward(self, x: torch.Tensor) -> torch.Tensor:
        return self.backbone(x)


def load_trained_model(
    weights_path: str,
    num_classes: int,
    device: torch.device,
) -> PhysiqueCNN:
    """Load trained weights for inference."""
    model = PhysiqueCNN(num_classes=num_classes)
    state = torch.load(weights_path, map_location=device)
    model.load_state_dict(state)
    model.to(device)
    model.eval()
    return model
