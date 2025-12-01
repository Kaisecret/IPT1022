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

# ---------------- Model ----------------

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


def analysis_from_probs(class_probs: Dict[str, float]) -> Dict[str, Any]:
    """
    Convert class probabilities like {"chest_strong": 0.7, "chest_weak": 0.2, ...}
    into the structured analysis used by the frontend, with custom scoring:
      - Any muscle predicted as STRONG gets a high score (>= 8.5)
      - If all 5 muscles are strong -> overall score = 10
      - If 4/5, 3/5, 2/5, 1/5 are strong -> boost overall score
      - If 4 or more muscles are weak (<=5) -> clamp overall score to <= 5
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

        # Decide strong vs weak based on which prob is larger
        if p_strong >= p_weak:
            # Marked as STRONG: give high base score 8.5–10
            base_score = 8.5 + 1.5 * (p_strong - p_weak)  # 8.5..10
            base_score = min(10.0, base_score)
            strengths = f"{muscle.capitalize()} looks relatively well-developed."
            weaknesses = f"Focus on fine-tuning {muscle} size and symmetry."
            symmetry = f"{muscle.capitalize()} appears balanced overall."
        else:
            # Marked as WEAK: give lower base score 5..1
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

    # --- Overall score logic ---
    overall_score = mean_score

    # If ALL muscles strong -> perfect 10
    if num_strong == num_muscles:
        overall_score = 10.0
    # 4/5 strong -> high 9+
    elif num_strong == num_muscles - 1:
        overall_score = max(mean_score, 9.0)
    # 3/5 strong -> around 8+
    elif num_strong == num_muscles - 2:
        overall_score = max(mean_score, 8.0)
    # 2 or more strong -> at least 7
    elif num_strong >= 2:
        overall_score = max(mean_score, 7.0)
    # 1 strong -> at least 6
    elif num_strong == 1:
        overall_score = max(mean_score, 6.0)

    # If 4 or more muscles weak, cap score at <= 5
    if num_weak >= 4:
        overall_score = min(overall_score, 5.0)

    overall_score = round(overall_score, 1)

    # --- Summary text ---
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

    # Posture notes still depend mostly on abs/back being weak
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
    equipment = prefs.get("equipment", "gym").lower()
    time_slot = map_time_slot(prefs.get("time", "30-45 min"))

    print(f"[_select_rule_for_muscle] muscle={muscle_name}, "
          f"score={muscle_score}, strength_level={strength_level}, "
          f"goal={goal}, experience={experience}, equipment={equipment}, "
          f"time_slot={time_slot}, overall_score={overall_score}")

    def matches_full(row):
        return (
            row["muscle_group"] == muscle_name
            and row["strength_level"] == strength_level
            and row["goal"] == goal
            and row["experience"] == experience
            and row["equipment"] == equipment
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
            and row["equipment"] == equipment
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
            and row["equipment"] == equipment
        )

    candidates = [r for r in PLAN_RULES if matches_loose(r)]
    if candidates:
        chosen = candidates[0]
        print(f"  -> matched LOOSE rule id={chosen['id']} for {muscle_name}")
        return chosen

    # final fallback: any rule with this muscle, or very first row
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


def workout_plan_from_rules(
    rules: List[Dict[str, Any]],
    analysis: Dict[str, Any],
) -> Dict[str, Any]:
    """
    Build a multi-day workout plan: one day per weak muscle.
    Each day uses the CSV rule as the main focus exercise +
    some generic accessory work for that muscle group.
    """
    muscle_analysis = analysis["muscleAnalysis"]

    base_exercises = {
        "chest": [
            ("Incline Dumbbell Press", "3", "8–12", "75s"),
            ("Cable Fly", "3", "12–15", "60s"),
        ],
        "back": [
            ("Lat Pulldown", "3", "8–12", "75s"),
            ("Seated Row", "3", "10–12", "75s"),
        ],
        "arms": [
            ("Barbell Curl", "3", "8–12", "60s"),
            ("Triceps Pushdown", "3", "10–12", "60s"),
        ],
        "legs": [
            ("Back Squat", "3", "6–10", "90s"),
            ("Leg Press", "3", "10–12", "75s"),
        ],
        "abs": [
            ("Cable Crunch", "3", "12–15", "45s"),
            ("Plank", "3", "30–45s", "45s"),
        ],
    }

    days = []
    for idx, rule in enumerate(rules):
        muscle = rule["muscle_group"]
        muscle_score = muscle_analysis.get(muscle, {}).get("score", 0.0)
        day_name = f"Day {idx + 1}"

        extra_ex = base_exercises.get(muscle, [])
        exercises = [
            {
                "name": rule["workout_title"],
                "sets": "3",
                "reps": "8–12",
                "rest": "60–90s",
            },
        ] + [
            {"name": n, "sets": s, "reps": r, "rest": rest}
            for (n, s, r, rest) in extra_ex
        ]

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
        "Use a weight where the last 2 reps of each set feel challenging but doable.",
        "Rest 60–90 seconds between sets unless otherwise specified.",
        "Increase the weight slightly once you can hit the top of the rep range with good form.",
        "Finish with the cooldown to help recovery and mobility.",
    ]

    return {
        "plan": days,
        "focusedMuscles": [r["muscle_group"] for r in rules],
        "rulesUsed": [r["id"] for r in rules],
        "stepByStep": step_by_step,
    }


def meal_guide_from_rule(rule: Dict[str, Any], prefs: Dict[str, Any]) -> Dict[str, Any]:
    """Use CSV meal_title/meal_description + simple macro calculation."""
    goal = prefs.get("goal", "recomposition").lower()
    weight = float(prefs.get("weight", 65))

    if goal == "fat loss":
        calories = int(weight * 28)
        protein = int(weight * 2.0)
    elif goal == "muscle gain":
        calories = int(weight * 36)
        protein = int(weight * 2.2)
    else:
        calories = int(weight * 32)
        protein = int(weight * 2.0)

    carbs = int(calories * 0.4 / 4)
    fats = int(calories * 0.25 / 9)

    m_title = rule["meal_title"]
    m_desc = rule["meal_description"]

    meals = [
        {
            "name": "Breakfast",
            "notes": f"{m_title} – {m_desc}",
            "ingredients": [
                "Oats with whey protein",
                "1–2 whole eggs + egg whites",
                "Fruit (banana or berries)",
            ],
        },
        {
            "name": "Lunch",
            "notes": "Balanced meal with lean protein, carbs and vegetables.",
            "ingredients": [
                "Grilled chicken or fish",
                "Rice, pasta or potatoes",
                "Mixed vegetables or salad",
            ],
        },
        {
            "name": "Dinner",
            "notes": "Similar to lunch, slightly lighter on carbs for fat loss goals.",
            "ingredients": [
                "Lean protein (chicken, beef, tofu)",
                "Smaller carb portion",
                "Plenty of vegetables",
            ],
        },
        {
            "name": "Snacks",
            "notes": "Keep snacks protein-focused to support muscle recovery.",
            "ingredients": [
                "Greek yogurt or cottage cheese",
                "Protein shake",
                "Nuts or rice cakes",
            ],
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

    combined_probs = combine_predictions([preds_front, preds_back, preds_legs])
    analysis = analysis_from_probs(combined_probs)

    # NEW: select rules for all weak muscles
    rules = select_rules_for_all_weak(analysis, prefs)
    workout_plan = workout_plan_from_rules(rules, analysis)

    # for meal guide, just use the first rule (primary focus)
    primary_rule = rules[0]
    meal_guide = meal_guide_from_rule(primary_rule, prefs)

    def top_class(preds: Dict[str, float]):
        if not preds:
            return ("none", 0.0)
        name = max(preds.items(), key=lambda kv: kv[1])[0]
        return name, preds[name]

    f_top, f_conf = top_class(preds_front)
    b_top, b_conf = top_class(preds_back)
    l_top, l_conf = top_class(preds_legs)
    c_top, c_conf = top_class(combined_probs)
    overall_score = analysis["physiqueRating"]["overallScore"]

    print("========== /analyze request ==========")
    print(f"Front   top: {f_top:15s} conf={f_conf:.3f}")
    print(f"Back    top: {b_top:15s} conf={b_conf:.3f}")
    print(f"Legs    top: {l_top:15s} conf={l_conf:.3f}")
    # removed COMBINED top line as requested
    print(f"Overall physique score (1–10): {overall_score}")
    print(f"Matched rule ids={[r['id'] for r in rules]} "
          f"muscles={[r['muscle_group'] for r in rules]}")
    print("======================================")

    return {
        "analysis": analysis,
        "plans": {
            "workoutPlan": workout_plan,
            "mealGuide": meal_guide,
        },
        "inference": {
            "front": preds_front,
            "back": preds_back,
            "legs": preds_legs,
            "combined": combined_probs,
            "combined_top_class": c_top,
            "combined_confidence": c_conf,
        },
    }


@app.get("/")
def root():
    return {"status": "ok", "message": "Physique Check API running"}
