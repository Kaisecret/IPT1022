# train2.py
"""
Train tabular models using data/dataset/GYM.csv and save the
best models to weights/gym_tabular_models.pkl.

We predict:
  - 'Exercise Schedule'
  - 'Meal Plan'
from:
  - 'Gender', 'Goal', 'BMI Category'

Usage:
    python train2.py
"""

from pathlib import Path
from typing import List, Optional, Tuple

import joblib
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import accuracy_score, classification_report
from sklearn.model_selection import train_test_split
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler

# ---------- paths ----------

BACKEND_ROOT = Path(__file__).resolve().parent
DATASET_CSV = BACKEND_ROOT / "data" / "dataset" / "GYM.csv"
WEIGHTS_DIR = BACKEND_ROOT / "weights"
WEIGHTS_DIR.mkdir(parents=True, exist_ok=True)

# Pkl that will store BOTH models (exercise + meal)
GYM_MODEL_PATH = WEIGHTS_DIR / "gym_tabular_models.pkl"

# ---------- config ----------

FEATURE_COLS: List[str] = ["Gender", "Goal", "BMI Category"]
TARGET_EXERCISE_COL = "Exercise Schedule"
TARGET_MEAL_COL = "Meal Plan"

TEST_SIZE = 0.2
RANDOM_STATE = 42


# ---------- helpers ----------

def green(text: str) -> str:
    """Wrap text in green ANSI color (works in most terminals)."""
    return f"\033[92m{text}\033[0m"


def load_csv(path: Path) -> pd.DataFrame:
    if not path.exists():
        raise FileNotFoundError(f"GYM.csv not found at: {path}")
    print(green(f"[train2] Loading CSV from: {path}"))
    df = pd.read_csv(path)
    print(green(f"[train2] GYM.csv shape: {df.shape}"))
    print(green(f"[train2] Columns: {list(df.columns)}"))
    return df


def split_features_targets(df: pd.DataFrame):
    # check features
    for c in FEATURE_COLS:
        if c not in df.columns:
            raise ValueError(
                f"[train2] FEATURE_COLS contains {c!r} but it is not in GYM.csv.\n"
                f"Available columns: {list(df.columns)}"
            )

    for c in [TARGET_EXERCISE_COL, TARGET_MEAL_COL]:
        if c not in df.columns:
            raise ValueError(
                f"[train2] target column {c!r} not in GYM.csv.\n"
                f"Available columns: {list(df.columns)}"
            )

    X = df[FEATURE_COLS].copy()
    y_ex = df[TARGET_EXERCISE_COL].copy()
    y_meal = df[TARGET_MEAL_COL].copy()
    return X, y_ex, y_meal


def build_preprocessor(X: pd.DataFrame) -> Tuple[ColumnTransformer, List[str], List[str]]:
    numeric_features: List[str] = X.select_dtypes(
        include=["int64", "float64", "int32", "float32"]
    ).columns.tolist()
    categorical_features: List[str] = [c for c in X.columns if c not in numeric_features]

    print(green(f"[train2] Numeric features: {numeric_features}"))
    print(green(f"[train2] Categorical features: {categorical_features}"))

    numeric_transformer = Pipeline(
        steps=[
            ("scaler", StandardScaler()),
        ]
    )

    categorical_transformer = Pipeline(
        steps=[
            ("onehot", OneHotEncoder(handle_unknown="ignore")),
        ]
    )

    preprocessor = ColumnTransformer(
        transformers=[
            ("num", numeric_transformer, numeric_features),
            ("cat", categorical_transformer, categorical_features),
        ]
    )

    return preprocessor, numeric_features, categorical_features


def build_model(preprocessor: ColumnTransformer) -> Pipeline:
    clf = RandomForestClassifier(
        n_estimators=300,
        max_depth=None,
        random_state=RANDOM_STATE,
        n_jobs=-1,
    )

    model = Pipeline(
        steps=[
            ("preprocess", preprocessor),
            ("clf", clf),
        ]
    )
    return model


def _get_previous_best_score(path: Path) -> float:
    if not path.exists():
        return 0.0
    try:
        bundle = joblib.load(path)
        return float(bundle.get("avg_val_accuracy", 0.0))
    except Exception:
        return 0.0


# ---------- main training ----------

def main():
    print(green(f"[train2] Using dataset: {DATASET_CSV}"))
    print(green(f"[train2] Models will be saved to: {GYM_MODEL_PATH}"))
    print(green(f"[train2] Feature columns: {FEATURE_COLS}"))
    print(green(f"[train2] Target exercise col: {TARGET_EXERCISE_COL!r}"))
    print(green(f"[train2] Target meal col: {TARGET_MEAL_COL!r}"))

    df = load_csv(DATASET_CSV)
    X, y_ex, y_meal = split_features_targets(df)

    preprocessor, num_feats, cat_feats = build_preprocessor(X)

    # we build two separate pipelines (same type but independent)
    exercise_model = build_model(preprocessor)
    # new preprocessor instance for the second model
    preprocessor2, _, _ = build_preprocessor(X)
    meal_model = build_model(preprocessor2)

    stratify = y_ex if y_ex.nunique() > 1 else None

    X_train, X_val, y_ex_train, y_ex_val, y_meal_train, y_meal_val = train_test_split(
        X,
        y_ex,
        y_meal,
        test_size=TEST_SIZE,
        random_state=RANDOM_STATE,
        stratify=stratify,
    )

    print(green(f"[train2] Training samples: {X_train.shape[0]}"))
    print(green(f"[train2] Validation samples: {X_val.shape[0]}"))

    print(green("[train2] ===== TRAIN EXERCISE MODEL ====="))
    exercise_model.fit(X_train, y_ex_train)
    y_ex_pred = exercise_model.predict(X_val)
    val_acc_ex = accuracy_score(y_ex_val, y_ex_pred)
    print(green(f"[train2] Exercise Schedule val acc: {val_acc_ex * 100:.2f}%"))
    print("[train2] Exercise Schedule report:")
    print(classification_report(y_ex_val, y_ex_pred))

    print(green("[train2] ===== TRAIN MEAL MODEL ====="))
    meal_model.fit(X_train, y_meal_train)
    y_meal_pred = meal_model.predict(X_val)
    val_acc_meal = accuracy_score(y_meal_val, y_meal_pred)
    print(green(f"[train2] Meal Plan val acc: {val_acc_meal * 100:.2f}%"))
    print("[train2] Meal Plan report:")
    print(classification_report(y_meal_val, y_meal_pred))

    avg_val_acc = (val_acc_ex + val_acc_meal) / 2.0
    prev_best = _get_previous_best_score(GYM_MODEL_PATH)
    print(green(f"[train2] Previous avg best acc: {prev_best * 100:.2f}%"))

    if avg_val_acc > prev_best:
        bundle = {
            "exercise_model": exercise_model,
            "meal_model": meal_model,
            "feature_cols": FEATURE_COLS,
            "numeric_features": num_feats,
            "categorical_features": cat_feats,
            "exercise_target_col": TARGET_EXERCISE_COL,
            "meal_target_col": TARGET_MEAL_COL,
            "val_accuracy_exercise": val_acc_ex,
            "val_accuracy_meal": val_acc_meal,
            "avg_val_accuracy": avg_val_acc,
        }
        joblib.dump(bundle, GYM_MODEL_PATH)
        print(
            green(
                f"[train2] -> New BEST models saved to {GYM_MODEL_PATH} "
                f"(avg_val_acc={avg_val_acc * 100:.2f}%)"
            )
        )
    else:
        print(
            green(
                "[train2] New models did NOT beat previous best. "
                "Keeping existing gym_tabular_models.pkl."
            )
        )

    print(green("[train2] ===== TRAINING FINISHED ====="))


# ---------- loader (for use in app.py) ----------

def load_gym_models(weights_path: Optional[str] = None):
    """
    Load the trained GYM tabular models.

    Returns:
        exercise_model: sklearn Pipeline predicting 'Exercise Schedule'
        meal_model:     sklearn Pipeline predicting 'Meal Plan'
        meta:           full metadata bundle (dict)
    """
    if weights_path is None:
        path = GYM_MODEL_PATH
    else:
        path = Path(weights_path)

    if not path.exists():
        raise FileNotFoundError(
            f"Gym models not found at {path}. Run train2.py first."
        )

    bundle = joblib.load(path)
    return bundle["exercise_model"], bundle["meal_model"], bundle


if __name__ == "__main__":
    main()
