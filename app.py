# app.py
import io
import csv
import json
from pathlib import Path
from typing import Dict, Any, List

import torch
from fastapi import FastAPI, File, UploadFile, Form
from fastapi.middleware.cors import CORSMiddleware
from PIL import Image
from torchvision import transforms

from model import load_trained_model

# NEW: for GYM models (recommendations)
import pandas as pd
from train2 import load_gym_models

# ---------------- Paths & device ----------------

BACKEND_ROOT = Path(__file__).resolve().parent
DATA_DIR = BACKEND_ROOT / "data"
WEIGHTS_PATH = BACKEND_ROOT / "weights" / "physique_cnn.pth"
CLASS_MAPPING_PATH = DATA_DIR / "class_mapping.json"
PLAN_RULES_PATH = DATA_DIR / "plan_rules.csv"

DEVICE = torch.device("cuda" if torch.cuda.is_available() else "cpu")
print(f"[app.py] Inference device: {DEVICE}")

# ---------------- Class mapping ----------------

if not CLASS_MAPPING_PATH.exists():
    raise RuntimeError(
        f"class_mapping.json not found at {CLASS_MAPPING_PATH}. "
        f"Run train.py first."
    )

with CLASS_MAPPING_PATH.open("r", encoding="utf-8") as f:
    class_to_idx: Dict[str, int] = json.load(f)

IDX_TO_CLASS = {idx: name for name, idx in class_to_idx.items()}
NUM_CLASSES = len(IDX_TO_CLASS)

# ---------------- CNN Model ----------------

if not WEIGHTS_PATH.exists():
    raise RuntimeError(
        f"Model weights not found at {WEIGHTS_PATH}. "
        f"Train the model with train.py first."
    )

print(f"[app.py] Using weights: {WEIGHTS_PATH}")
MODEL = load_trained_model(str(WEIGHTS_PATH), NUM_CLASSES, DEVICE)

IMG_SIZE = 224
INFER_TRANSFORMS = transforms.Compose([
    transforms.Resize((IMG_SIZE, IMG_SIZE)),
    transforms.ToTensor(),
    transforms.Normalize(
        mean=[0.485, 0.456, 0.406],
        std=[0.229, 0.224, 0.225],
    ),
])

# ---------------- Load CSV rules ----------------

if not PLAN_RULES_PATH.exists():
    raise RuntimeError(
        f"plan_rules.csv not found at {PLAN_RULES_PATH}. "
        f"Run generate_plan_rules.py first to create it."
    )

PLAN_RULES: List[Dict[str, Any]] = []
with PLAN_RULES_PATH.open("r", encoding="utf-8") as f:
    reader = csv.DictReader(f)
    for row in reader:
        row["id"] = int(row["id"])
        row["overall_min_score"] = float(row["overall_min_score"])
        row["overall_max_score"] = float(row["overall_max_score"])
        PLAN_RULES.append(row)

print(f"[app.py] Loaded {len(PLAN_RULES)} plan rules from {PLAN_RULES_PATH}")

# ---------------- GYM.csv-based recommendation models ----------------

try:
    EXERCISE_MODEL, MEAL_MODEL, GYM_META = load_gym_models()
    GYM_FEATURE_COLS = GYM_META["feature_cols"]
    print(f"[app.py] Loaded GYM recommendation models. Features: {GYM_FEATURE_COLS}")
except Exception as e:
    EXERCISE_MODEL = None
    MEAL_MODEL = None
    GYM_META = None
    GYM_FEATURE_COLS = []
    print(f"[app.py] WARNING: Could not load GYM models: {e}")

# ---------------- FastAPI setup ----------------

app = FastAPI(title="Physique Check API")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],   # dev only
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# ---------------- Helper functions ----------------

def file_to_image_bytes(upload: UploadFile) -> Image.Image:
    """Read UploadFile into a PIL Image."""
    data = upload.file.read()
    img = Image.open(io.BytesIO(data)).convert("RGB")
    return img


def run_model_on_image(img: Image.Image) -> Dict[str, float]:
    """Run CNN on a PIL image and return probability per class_name."""
    MODEL.eval()
    with torch.no_grad():
        tensor = INFER_TRANSFORMS(img).unsqueeze(0).to(DEVICE)
        logits = MODEL(tensor)[0]
        probs = torch.softmax(logits, dim=0).cpu().numpy()
        return {IDX_TO_CLASS[i]: float(probs[i]) for i in range(NUM_CLASSES)}


def combine_predictions(pred_list: List[Dict[str, float]]) -> Dict[str, float]:
    """Average probabilities from multiple images."""
    combined: Dict[str, float] = {name: 0.0 for name in class_to_idx.keys()}
    if not pred_list:
        return combined
    for preds in pred_list:
        for name, p in preds.items():
            combined[name] += p
    n = float(len(pred_list))
    for name in combined:
        combined[name] /= n
    return combined


def is_physique_like(preds: Dict[str, float], threshold: float = 0.4) -> bool:
    """
    Simple sanity check for non-physique images.

    If the model's best class probability is <= threshold (e.g. 0.3–0.4),
    we assume the image is NOT a clear physique photo.
    """
    if not preds:
        return False
    max_p = max(preds.values())
    return max_p > threshold   # any <= threshold will be treated as invalid


def analysis_from_probs(class_probs: Dict[str, float]) -> Dict[str, Any]:
    """
    Convert class probabilities like {"chest_strong": 0.7, "chest_weak": 0.2, ...}
    into the structured analysis used by the frontend, with custom scoring.
    """
    MUSCLE_LABELS = {
        "chest": ("chest_strong", "chest_weak"),
        "abs": ("abs_strong", "abs_weak"),
        "arms": ("arms_strong", "arms_weak"),
        "back": ("back_strong", "back_weak"),
        "legs": ("legs_strong", "legs_weak"),
    }

    muscle_analysis: Dict[str, Dict[str, Any]] = {}
    scores: List[float] = []
    strong_muscles: List[str] = []
    weak_muscles: List[str] = []

    for muscle, (strong_label, weak_label) in MUSCLE_LABELS.items():
        p_strong = class_probs.get(strong_label, 0.0)
        p_weak = class_probs.get(weak_label, 0.0)

        if p_strong >= p_weak:
            base_score = 8.5 + 1.5 * (p_strong - p_weak)  # 8.5..10
            base_score = min(10.0, base_score)
            strengths = f"{muscle.capitalize()} looks relatively well-developed."
            weaknesses = f"Focus on fine-tuning {muscle} size and symmetry."
            symmetry = f"{muscle.capitalize()} appears balanced overall."
        else:
            base_score = 5.0 - 4.0 * (p_weak - p_strong)  # 5..1
            base_score = max(1.0, base_score)
            strengths = f"{muscle.capitalize()} has room to grow."
            weaknesses = f"{muscle.capitalize()} appears under-developed compared with other areas."
            symmetry = f"Work on controlled technique to improve {muscle} balance and definition."

        score = round(base_score, 1)
        scores.append(score)

        if score >= 8.5:
            strong_muscles.append(muscle)
        if score <= 5.0:
            weak_muscles.append(muscle)

        muscle_analysis[muscle] = {
            "score": score,
            "strengths": strengths,
            "weaknesses": weaknesses,
            "symmetryNotes": symmetry,
        }

    num_muscles = len(MUSCLE_LABELS)
    mean_score = sum(scores) / num_muscles
    num_strong = len(strong_muscles)
    num_weak = len(weak_muscles)

    overall_score = mean_score

    if num_strong == num_muscles:
        overall_score = 10.0
    elif num_strong == num_muscles - 1:
        overall_score = max(mean_score, 9.0)
    elif num_strong == num_muscles - 2:
        overall_score = max(mean_score, 8.0)
    elif num_strong >= 2:
        overall_score = max(mean_score, 7.0)
    elif num_strong == 1:
        overall_score = max(mean_score, 6.0)

    if num_weak >= 4:
        overall_score = min(overall_score, 5.0)

    overall_score = round(overall_score, 1)
    pct_strong = int(round(100.0 * num_strong / num_muscles))

    if num_strong == 0:
        summary = (
            f"{num_strong} of {num_muscles} muscle groups are strong "
            f"({pct_strong}% strong). All groups are currently in a moderate "
            "range; consistent training will turn them into clear strengths."
        )
    elif num_strong == num_muscles:
        summary = (
            f"All {num_muscles} muscle groups are strong (100% strong). "
            "This is a very well-balanced, advanced physique."
        )
    else:
        strong_list = ", ".join(m.capitalize() for m in strong_muscles) or "none yet"
        weak_list = ", ".join(m.capitalize() for m in weak_muscles) or "mainly moderate groups"
        summary = (
            f"{num_strong} of {num_muscles} muscle groups are strong "
            f"({pct_strong}% strong). Stronger areas: {strong_list}. "
            f"Weaker focus areas: {weak_list}."
        )

    if "back" in weak_muscles or "abs" in weak_muscles:
        posture_notes = (
            "Posture may benefit from stronger core and back. "
            "Focus on bracing your core and keeping shoulder blades pulled back "
            "during standing and lifting."
        )
    else:
        posture_notes = (
            "Posture appears generally solid. Maintain core engagement and neutral spine "
            "during both daily activities and training."
        )

    return {
        "physiqueRating": {
            "overallScore": overall_score,
            "summary": summary,
        },
        "postureNotes": posture_notes,
        "muscleAnalysis": muscle_analysis,
    }

# ---------- Experience / equipment helpers ----------

def get_equipment_mode(prefs: Dict[str, Any]) -> str:
    """
    Normalize equipment from prefs into: 'gym' | 'home' | 'minimal'
    """
    raw = str(prefs.get("equipment", "gym")).lower()
    if "minimal" in raw:
        return "minimal"
    if "home" in raw:
        return "home"
    return "gym"


def sets_for_experience(base_sets: int, experience: str) -> str:
    """
    Adjust sets based on training experience.
      - beginner: slightly fewer sets
      - intermediate: base
      - advanced: one extra set
    """
    exp = (experience or "").lower()
    if "beginner" in exp:
        s = max(2, base_sets - 1)
    elif "advanced" in exp:
        s = base_sets + 1
    else:  # intermediate / default
        s = base_sets
    return str(s)

# ---------- GYM.csv recommendation helper ----------

def gym_recommendations_from_prefs(prefs: Dict[str, Any]) -> Dict[str, Any]:
    """
    Use the models trained on GYM.csv to recommend:
      - Exercise Schedule
      - Meal Plan label/type
    """
    if EXERCISE_MODEL is None or MEAL_MODEL is None:
        return {
            "exerciseSchedule": None,
            "mealPlanLabel": None,
        }

    gender = prefs.get("gender", "Male")
    goal_raw = prefs.get("goal", "muscle gain")
    bmi_cat = prefs.get("bmiCategory", "Normal")

    goal_map = {
        "fat loss": "Weight Loss",
        "weight loss": "Weight Loss",
        "muscle gain": "Muscle Gain",
        "recomposition": "Recomposition",
        "maintain": "Maintain",
    }
    goal = goal_map.get(str(goal_raw).lower(), str(goal_raw))

    row = {
        "Gender": str(gender).title(),
        "Goal": goal,
        "BMI Category": str(bmi_cat),
    }

    df = pd.DataFrame([row])

    for col in GYM_FEATURE_COLS:
        if col not in df.columns:
            df[col] = None
    df = df[GYM_FEATURE_COLS]

    exercise_schedule = str(EXERCISE_MODEL.predict(df)[0])
    meal_plan_label = str(MEAL_MODEL.predict(df)[0])

    return {
        "exerciseSchedule": exercise_schedule,
        "mealPlanLabel": meal_plan_label,
    }

# -------------- CSV-based rule selection helpers --------------

def score_to_strength_level(score: float) -> str:
    """Map muscle score (1–10) to 'weak' | 'moderate' | 'strong'."""
    if score < 4.0:
        return "weak"
    if score < 7.0:
        return "moderate"
    return "strong"


def map_time_slot(pref_time: str) -> str:
    """Map frontend 'Time per Workout' to CSV time_slot."""
    pref_time = (pref_time or "").strip()
    mapping = {
        "20-30 min": "20-30",
        "30-45 min": "30-45",
        "45-60 min": "45-60",
        "60+ min": "60+",
    }
    return mapping.get(pref_time, "30-45")


def _select_rule_for_muscle(
    muscle_name: str,
    muscle_score: float,
    overall_score: float,
    prefs: Dict[str, Any],
) -> Dict[str, Any]:
    """
    Internal helper: pick one best CSV rule for a SINGLE muscle.
    Used by select_rules_for_all_weak.
    """
    strength_level = score_to_strength_level(muscle_score)

    goal = prefs.get("goal", "recomposition").lower()
    experience = prefs.get("experience", "beginner").lower()
    equipment_mode = get_equipment_mode(prefs)  # gym | home | minimal
    equipment_for_rules = "home" if equipment_mode == "minimal" else equipment_mode
    time_slot = map_time_slot(prefs.get("time", "30-45 min"))

    print(f"[_select_rule_for_muscle] muscle={muscle_name}, "
          f"score={muscle_score}, strength_level={strength_level}, "
          f"goal={goal}, experience={experience}, equipment={equipment_for_rules}, "
          f"time_slot={time_slot}, overall_score={overall_score}")

    def matches_full(row):
        return (
            row["muscle_group"] == muscle_name
            and row["strength_level"] == strength_level
            and row["goal"] == goal
            and row["experience"] == experience
            and row["equipment"] == equipment_for_rules
            and row["time_slot"] == time_slot
            and row["overall_min_score"] <= overall_score <= row["overall_max_score"]
        )

    candidates = [r for r in PLAN_RULES if matches_full(r)]
    if candidates:
        chosen = candidates[0]
        print(f"  -> matched FULL rule id={chosen['id']} for {muscle_name}")
        return chosen

    def matches_no_time(row):
        return (
            row["muscle_group"] == muscle_name
            and row["strength_level"] == strength_level
            and row["goal"] == goal
            and row["experience"] == experience
            and row["equipment"] == equipment_for_rules
            and row["overall_min_score"] <= overall_score <= row["overall_max_score"]
        )

    candidates = [r for r in PLAN_RULES if matches_no_time(r)]
    if candidates:
        chosen = candidates[0]
        print(f"  -> matched NO-TIME rule id={chosen['id']} for {muscle_name}")
        return chosen

    def matches_loose(row):
        return (
            row["muscle_group"] == muscle_name
            and row["goal"] == goal
            and row["experience"] == experience
            and row["equipment"] == equipment_for_rules
        )

    candidates = [r for r in PLAN_RULES if matches_loose(r)]
    if candidates:
        chosen = candidates[0]
        print(f"  -> matched LOOSE rule id={chosen['id']} for {muscle_name}")
        return chosen

    for r in PLAN_RULES:
        if r["muscle_group"] == muscle_name:
            print(f"  -> fallback rule (same muscle) id={r['id']} for {muscle_name}")
            return r

    print("  -> no good match at all, using first rule as global fallback")
    return PLAN_RULES[0]


def select_rules_for_all_weak(analysis: Dict[str, Any], prefs: Dict[str, Any]) -> List[Dict[str, Any]]:
    """
    Instead of only the single weakest muscle, choose rules for
    EVERY weak muscle (score <= 5). If nothing is <= 5, take the
    3 lowest-scoring muscles.
    """
    muscle_analysis = analysis["muscleAnalysis"]
    overall_score = float(analysis["physiqueRating"]["overallScore"])

    weak_muscles = [
        (name, float(info["score"]))
        for name, info in muscle_analysis.items()
        if info["score"] <= 5.0
    ]

    if not weak_muscles:
        sorted_muscles = sorted(
            muscle_analysis.items(),
            key=lambda kv: kv[1]["score"]
        )
        weak_muscles = [
            (name, float(info["score"]))
            for name, info in sorted_muscles[:3]
        ]

    rules: List[Dict[str, Any]] = []
    seen_ids = set()

    for muscle_name, score in weak_muscles:
        rule = _select_rule_for_muscle(muscle_name, score, overall_score, prefs)
        if rule["id"] not in seen_ids:
            seen_ids.add(rule["id"])
            rules.append(rule)

    print("[select_rules_for_all_weak] muscles covered:",
          ", ".join([r["muscle_group"] for r in rules]))
    return rules

# ---------- Equipment-based base exercises (gym vs home vs minimal) ----------

# (name, base_sets, reps, rest)
BASE_EXERCISES = {
    "gym": {
        "chest": [
            ("Barbell Bench Press", 3, "6–10", "90s"),
            ("Incline Dumbbell Press", 3, "8–12", "75s"),
            ("Cable Fly", 3, "12–15", "60s"),
            ("Chest Press Machine", 3, "10–12", "75s"),
        ],
        "back": [
            ("Lat Pulldown", 3, "8–12", "75s"),
            ("Seated Row", 3, "10–12", "75s"),
            ("Chest-Supported Row", 3, "8–12", "75s"),
            ("Straight-Arm Pulldown", 3, "12–15", "60s"),
        ],
        "arms": [
            ("Barbell Curl", 3, "8–12", "60s"),
            ("Dumbbell Hammer Curl", 3, "10–12", "60s"),
            ("Triceps Pushdown", 3, "10–12", "60s"),
            ("Overhead Triceps Extension", 3, "10–12", "60s"),
        ],
        "legs": [
            ("Back Squat", 3, "6–10", "90s"),
            ("Leg Press", 3, "10–12", "75s"),
            ("Romanian Deadlift", 3, "8–12", "90s"),
            ("Leg Curl Machine", 3, "10–15", "75s"),
        ],
        "abs": [
            ("Cable Crunch", 3, "12–15", "45s"),
            ("Hanging Knee Raise", 3, "10–15", "45s"),
            ("Plank", 3, "30–45s", "45s"),
            ("Russian Twist", 3, "16–20", "45s"),
        ],
    },
    "home": {
        "chest": [
            ("Push-Up", 3, "max-2", "60s"),
            ("Incline Push-Up (on chair)", 3, "10–15", "60s"),
            ("Decline Push-Up", 3, "8–12", "60s"),
            ("Wide-Arm Push-Up", 3, "10–15", "60s"),
        ],
        "back": [
            ("Back Extensions (floor or bench)", 3, "12–15", "60s"),
            ("Doorframe or Inverted Row (if safe)", 3, "8–12", "60s"),
            ("Superman Hold", 3, "20–30s", "45s"),
            ("Banded Row (if resistance band)", 3, "12–15", "60s"),
        ],
        "arms": [
            ("Diamond Push-Up", 3, "8–12", "60s"),
            ("Bench/Chair Dips", 3, "10–15", "60s"),
            ("Banded Curl (or water bottle curl)", 3, "12–15", "60s"),
            ("Overhead Triceps Extension (band/dumbbell)", 3, "12–15", "60s"),
        ],
        "legs": [
            ("Bodyweight Squat", 3, "12–20", "60s"),
            ("Reverse Lunge", 3, "10–12/leg", "60s"),
            ("Glute Bridge", 3, "12–15", "60s"),
            ("Wall Sit", 3, "30–45s", "45s"),
        ],
        "abs": [
            ("Crunch", 3, "15–20", "45s"),
            ("Plank", 3, "30–45s", "45s"),
            ("Dead Bug", 3, "10–12/side", "45s"),
            ("Bicycle Crunch", 3, "16–20", "45s"),
        ],
    },
    "minimal": {
        "chest": [
            ("Dumbbell Floor Press", 3, "8–12", "75s"),
            ("Dumbbell Fly (on floor or bench)", 3, "10–12", "60s"),
            ("Push-Up", 3, "max-2", "60s"),
            ("Incline Push-Up (on chair)", 3, "10–15", "60s"),
        ],
        "back": [
            ("Single-Arm Dumbbell Row (on bench/chair)", 3, "8–12/side", "75s"),
            ("Banded Row", 3, "12–15", "60s"),
            ("Back Extensions (floor)", 3, "12–15", "60s"),
            ("Superman Hold", 3, "20–30s", "45s"),
        ],
        "arms": [
            ("Dumbbell Curl", 3, "10–12", "60s"),
            ("Hammer Curl", 3, "10–12", "60s"),
            ("Overhead Triceps Extension (dumbbell)", 3, "10–12", "60s"),
            ("Bench/Chair Dips", 3, "10–15", "60s"),
        ],
        "legs": [
            ("Goblet Squat (dumbbell)", 3, "8–12", "75s"),
            ("Reverse Lunge (bodyweight or dumbbell)", 3, "10–12/leg", "60s"),
            ("Romanian Deadlift (dumbbells)", 3, "8–12", "75s"),
            ("Glute Bridge", 3, "12–15", "60s"),
        ],
        "abs": [
            ("Crunch", 3, "15–20", "45s"),
            ("Plank", 3, "30–45s", "45s"),
            ("Russian Twist (with or without weight)", 3, "16–20", "45s"),
            ("Leg Raise (lying)", 3, "10–15", "45s"),
        ],
    },
}

def workout_plan_from_rules(
    rules: List[Dict[str, Any]],
    analysis: Dict[str, Any],
    prefs: Dict[str, Any],
) -> Dict[str, Any]:
    """
    Build a multi-day workout plan: one day per weak muscle.
    """
    muscle_analysis = analysis["muscleAnalysis"]

    equipment_mode = get_equipment_mode(prefs)
    if equipment_mode not in BASE_EXERCISES:
        equipment_mode = "gym"

    experience = str(prefs.get("experience", "beginner"))

    days = []
    for idx, rule in enumerate(rules):
        muscle = rule["muscle_group"]
        muscle_score = muscle_analysis.get(muscle, {}).get("score", 0.0)
        day_name = f"Day {idx + 1}"

        muscle_exercises = BASE_EXERCISES.get(equipment_mode, {}).get(muscle, [])
        accessory_exercises = muscle_exercises[:4]

        main_sets = sets_for_experience(3, experience)
        exercises = [
            {
                "name": rule["workout_title"],
                "sets": main_sets,
                "reps": "8–12",
                "rest": "60–90s",
            },
        ]

        for (n, base_sets, r, rest) in accessory_exercises:
            exercises.append({
                "name": n,
                "sets": sets_for_experience(base_sets, experience),
                "reps": r,
                "rest": rest,
            })

        days.append({
            "dayOfWeek": day_name,
            "targetMuscle": muscle.capitalize(),
            "warmup": "5–10 min light cardio + dynamic stretching",
            "exercises": exercises,
            "cooldown": "Light stretching for 5–10 minutes",
            "notes": (
                f"{muscle.capitalize()} scored {muscle_score}/10. "
                f"Focus on controlled technique and progressive overload. "
                f"{rule['workout_description']}"
            ),
        })

    step_by_step = [
        "Train 3–4 days per week following the days listed.",
        "Always start with the warm-up before your first exercise.",
        "Use a weight or difficulty where the last 2 reps of each set feel challenging but doable.",
        "Rest 60–90 seconds between sets unless otherwise specified.",
        "Increase the difficulty (weight, reps, or tempo) once you can hit the top of the rep range with good form.",
        "Finish with the cooldown to help recovery and mobility.",
    ]

    return {
        "plan": days,
        "focusedMuscles": [r["muscle_group"] for r in rules],
        "rulesUsed": [r["id"] for r in rules],
        "stepByStep": step_by_step,
        "equipment": equipment_mode,
        "experience": experience,
    }

# ---------- Smarter meal guide (goal + BMI + gender + activity level) ----------

def meal_guide_from_rule(rule: Dict[str, Any], prefs: Dict[str, Any]) -> Dict[str, Any]:
    """
    Use CSV meal_title/meal_description + macro calculation,
    adapted by goal, BMI, gender, activity level.
    """
    goal_raw = prefs.get("goal", "recomposition")
    goal = str(goal_raw).lower()
    weight = float(prefs.get("weight", 65))
    bmi_cat = str(prefs.get("bmiCategory", "Normal")).lower()
    gender = str(prefs.get("gender", "male")).lower()
    activity_raw = str(prefs.get("activityLevel", "moderate")).lower()

    if goal == "fat loss":
        base_calories = weight * 26
        protein = int(weight * 2.0)
    elif goal == "muscle gain":
        base_calories = weight * 36
        protein = int(weight * 2.2)
    else:
        base_calories = weight * 30
        protein = int(weight * 2.0)

    if "sedentary" in activity_raw:
        activity_factor = 1.2
    elif "light" in activity_raw:
        activity_factor = 1.375
    elif "moderate" in activity_raw:
        activity_factor = 1.55
    elif "active" in activity_raw:
        activity_factor = 1.725
    elif "very" in activity_raw:
        activity_factor = 1.9
    else:
        activity_factor = 1.4

    gender_factor = 0.9 if gender == "female" else 1.0

    calories = int(base_calories * activity_factor * gender_factor)

    carbs = int(calories * 0.40 / 4)
    fats = int(calories * 0.25 / 9)

    m_title = rule["meal_title"]
    m_desc = rule["meal_description"]

    if goal == "fat loss":
        breakfast_ingredients = [
            "Oats with whey protein OR plain Greek yogurt",
            "1 boiled egg + extra egg whites",
            "Fruit (berries preferred)",
        ]
        lunch_ingredients = [
            "Grilled chicken or white fish",
            "Small portion of rice, quinoa or potatoes",
            "Big serving of mixed vegetables or salad",
        ]
        dinner_ingredients = [
            "Lean protein (chicken, turkey, tofu)",
            "Half portion of carbs compared to lunch",
            "Plenty of green vegetables",
        ]
        snacks_ingredients = [
            "Greek yogurt or cottage cheese (low-fat)",
            "Carrot/cucumber sticks",
            "A small handful of nuts",
        ]
        lunch_notes = "Lower-calorie meal with lean protein, high veggies and controlled carbs."
        dinner_notes = "Light evening meal, prioritizing protein and veg over carbs."

    elif goal == "muscle gain":
        breakfast_ingredients = [
            "Oats with whey protein and peanut butter",
            "2 whole eggs + egg whites",
            "Fruit (banana or berries)",
        ]
        lunch_ingredients = [
            "Grilled chicken, beef or fish",
            "Generous portion of rice, pasta or potatoes",
            "Mixed vegetables or salad",
        ]
        dinner_ingredients = [
            "Lean protein (chicken, beef, tofu)",
            "Moderate portion of carbs (rice, pasta, potatoes)",
            "Vegetables or salad",
        ]
        snacks_ingredients = [
            "Protein shake with fruit",
            "Greek yogurt with granola",
            "Nuts, trail mix or rice cakes with peanut butter",
        ]
        lunch_notes = "Higher-calorie meal with good carbs and lean protein to support growth."
        dinner_notes = "Evening meal with enough carbs to recover but not overly heavy."

    else:
        breakfast_ingredients = [
            "Oats with whey protein OR 2 eggs + egg whites",
            "Fruit (banana or berries)",
        ]
        lunch_ingredients = [
            "Grilled chicken or fish",
            "Moderate portion of rice, pasta or potatoes",
            "Mixed vegetables or salad",
        ]
        dinner_ingredients = [
            "Lean protein (chicken, fish, tofu)",
            "Smaller portion of carbs",
            "Plenty of vegetables",
        ]
        snacks_ingredients = [
            "Greek yogurt or cottage cheese",
            "Protein shake",
            "Fruit or a small handful of nuts",
        ]
        lunch_notes = "Balanced meal with lean protein, moderate carbs and vegetables."
        dinner_notes = "Slightly lighter than lunch to avoid overeating late."

    if "underweight" in bmi_cat:
        snacks_ingredients.append("Extra spoon of peanut butter or nut butter")
        snacks_ingredients.append("Additional glass of milk or soy milk")

    if "overweight" in bmi_cat or "obese" in bmi_cat:
        snacks_ingredients = [
            "Greek yogurt (low-fat) or cottage cheese",
            "Fresh fruit (apple, berries, orange)",
            "Raw veggies (carrot, cucumber, bell pepper)",
            "Herbal tea or zero-calorie drink if craving something",
        ]

    meals = [
        {
            "name": "Breakfast",
            "notes": f"{m_title} – {m_desc}",
            "ingredients": breakfast_ingredients,
        },
        {
            "name": "Lunch",
            "notes": lunch_notes,
            "ingredients": lunch_ingredients,
        },
        {
            "name": "Dinner",
            "notes": dinner_notes,
            "ingredients": dinner_ingredients,
        },
        {
            "name": "Snacks",
            "notes": "Adjust number of snacks to hit your calorie target. "
                     "Keep them mostly protein-focused.",
            "ingredients": snacks_ingredients,
        },
    ]

    return {
        "dailyCalorieTarget": calories,
        "macros": {
            "protein": f"{protein} g",
            "carbs": f"{carbs} g",
            "fats": f"{fats} g",
        },
        "meals": meals,
        "planName": m_title,
        "planDescription": m_desc,
        "activityLevel": activity_raw,
        "gender": gender,
    }

# ---------------- API ----------------

@app.post("/analyze")
async def analyze_physique(
    front: UploadFile = File(...),
    back: UploadFile = File(...),
    legs: UploadFile = File(...),
    preferences: str = Form(...),
):
    """Main endpoint used by the frontend."""
    prefs = json.loads(preferences)

    front_img = file_to_image_bytes(front)
    back_img = file_to_image_bytes(back)
    legs_img = file_to_image_bytes(legs)

    preds_front = run_model_on_image(front_img)
    preds_back = run_model_on_image(back_img)
    preds_legs = run_model_on_image(legs_img)

    # Helper to get top class + confidence
    def top_class(preds: Dict[str, float]):
        if not preds:
            return ("none", 0.0)
        name, p = max(preds.items(), key=lambda kv: kv[1])
        return name, p

    f_top, f_conf = top_class(preds_front)
    b_top, b_conf = top_class(preds_back)
    l_top, l_conf = top_class(preds_legs)

    # 1) reject obvious NON-physique images based on per-image confidence
    if not (
        is_physique_like(preds_front)
        and is_physique_like(preds_back)
        and is_physique_like(preds_legs)
    ):
        return {
            "validImages": False,
            "message": (
                f"The Photos is not a muscle\n"
                f"The uploaded images do not look like clear physique photos "
                f"with good lighting."
            ),
            "inference": {
                "front": preds_front,
                "back": preds_back,
                "legs": preds_legs,
            },
        }

    # 2) extra rule: average of the 3 top confidences must be > MIN_AVG_CONF
    MIN_AVG_CONF = 0.8  # you can tweak this (0.5, 0.55, etc.)
    avg_conf = (f_conf + b_conf + l_conf) / 3.0

    if avg_conf <= MIN_AVG_CONF:
        return {
            "validImages": False,
            "message": (
                f"The Photos is not a muscle"
                "clear or consistent physique photos. Please upload sharper, "
                "well-lit front, back and leg photos."
            ),
            "inference": {
                "front": preds_front,
                "back": preds_back,
                "legs": preds_legs,
                "avg_confidence": avg_conf,
            },
        }

    # 3) normal analysis pipeline (only if passes all confidence checks)
    combined_probs = combine_predictions([preds_front, preds_back, preds_legs])
    analysis = analysis_from_probs(combined_probs)

    overall_score = float(analysis["physiqueRating"]["overallScore"])

    rules = select_rules_for_all_weak(analysis, prefs)
    workout_plan = workout_plan_from_rules(rules, analysis, prefs)

    primary_rule = rules[0]
    meal_guide = meal_guide_from_rule(primary_rule, prefs)

    gym_recos = gym_recommendations_from_prefs(prefs)
    workout_plan["recommendedSchedule"] = gym_recos["exerciseSchedule"]
    meal_guide["gymMealPlanLabel"] = gym_recos["mealPlanLabel"]

    c_top, c_conf = top_class(combined_probs)

    print("========== /analyze request ==========")
    print(f"Front   top: {f_top:15s} conf={f_conf:.3f}")
    print(f"Back    top: {b_top:15s} conf={b_conf:.3f}")
    print(f"Legs    top: {l_top:15s} conf={l_conf:.3f}")
    print(f"Avg confidence of 3 images: {avg_conf:.3f}")
    print(f"Overall physique score (1–10): {overall_score}")
    print(f"Matched rule ids={[r['id'] for r in rules]} "
          f"muscles={[r['muscle_group'] for r in rules]}")
    print("GYM recommendations:", gym_recos)
    print("======================================")

    return {
        "validImages": True,
        "analysis": analysis,
        "plans": {
            "workoutPlan": workout_plan,
            "mealGuide": meal_guide,
        },
        "gymRecommendations": gym_recos,
        "inference": {
            "front": preds_front,
            "back": preds_back,
            "legs": preds_legs,
            "combined": combined_probs,
            "combined_top_class": c_top,
            "combined_confidence": c_conf,
            "avg_confidence": avg_conf,
        },
    }


@app.get("/")
def root():
    return {"status": "ok", "message": "Physique Check API running"}
