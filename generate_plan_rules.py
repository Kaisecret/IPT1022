import csv
from pathlib import Path

BACKEND_ROOT = Path(__file__).resolve().parent
DATA_DIR = BACKEND_ROOT / "data"
OUT_PATH = DATA_DIR / "plan_rules.csv"

muscles = ["chest", "back", "legs", "arms", "abs"]
strength_levels = ["weak", "moderate", "strong"]
goals = ["fat loss", "muscle gain", "recomposition"]
experiences = ["beginner", "intermediate", "advanced"]
equipments = ["gym", "home", "minimal equipment"]
time_slots = ["20-30", "30-45", "45-60", "60+"]

# how many variations for each combination
VARIATIONS_PER_COMBO = 7   # 1620 * 7 = 11340 rows


def default_score_range(strength_level: str):
    if strength_level == "weak":
        return 1, 5
    if strength_level == "moderate":
        return 4, 7
    return 6, 10  # strong


def make_workout_title(muscle, goal, experience, variation):
    # small variation tag at the end
    return f"{experience.capitalize()} {muscle.capitalize()} - {goal.title()} (v{variation})"


def make_workout_desc(muscle, goal, variation):
    base = (
        f"Focus on {muscle} with exercises that support {goal}. "
        "Use controlled tempo and progressive overload."
    )
    # tiny variation text so each row is not identical
    extra = [
        " Include 1–2 warm-up sets before working sets.",
        " Emphasize full range of motion on every rep.",
        " Track your weights and try to improve weekly.",
        " Keep rests timed with a stopwatch for consistency.",
        " Add a deload week every 4–6 weeks if needed.",
        " Prioritize quality sleep to support recovery.",
        " Maintain good technique, don’t just chase weight.",
    ]
    return base + extra[(variation - 1) % len(extra)]


def make_meal_title(goal, variation):
    if goal == "fat loss":
        base = "Fat Loss Meal Plan"
    elif goal == "muscle gain":
        base = "Muscle Gain Meal Plan"
    else:
        base = "Recomposition Meal Plan"
    return f"{base} (v{variation})"


def make_meal_desc(goal, variation):
    if goal == "fat loss":
        base = "Calorie deficit with high protein, plenty of vegetables and moderate carbs."
    elif goal == "muscle gain":
        base = "Small calorie surplus with high protein and good carb timing around workouts."
    else:
        base = "Near-maintenance calories, high protein and balanced carbs/fats."

    extra = [
        " Aim for 3 main meals plus 1–2 protein-focused snacks.",
        " Drink enough water (2–3L/day) and limit sugary drinks.",
        " Try to keep most meals home-cooked for easier control.",
        " Adjust portions slightly each week based on progress.",
        " Spread protein fairly evenly across all meals.",
        " Plan meals ahead to avoid random snacking.",
        " Include high-fiber foods to keep you full longer.",
    ]
    return base + extra[(variation - 1) % len(extra)]


def main():
    DATA_DIR.mkdir(exist_ok=True, parents=True)

    headers = [
        "id",
        "muscle_group",
        "strength_level",
        "goal",
        "experience",
        "equipment",
        "time_slot",
        "overall_min_score",
        "overall_max_score",
        "workout_title",
        "workout_description",
        "meal_title",
        "meal_description",
    ]

    rows = []
    row_id = 1

    for muscle in muscles:
        for strength_level in strength_levels:
            for goal in goals:
                for exp in experiences:
                    for equip in equipments:
                        for tslot in time_slots:
                            smin, smax = default_score_range(strength_level)

                            # create multiple slightly different variants
                            for variation in range(1, VARIATIONS_PER_COMBO + 1):
                                w_title = make_workout_title(muscle, goal, exp, variation)
                                w_desc = make_workout_desc(muscle, goal, variation)
                                m_title = make_meal_title(goal, variation)
                                m_desc = make_meal_desc(goal, variation)

                                rows.append([
                                    row_id,
                                    muscle,
                                    strength_level,
                                    goal,
                                    exp,
                                    equip,
                                    tslot,
                                    smin,
                                    smax,
                                    w_title,
                                    w_desc,
                                    m_title,
                                    m_desc,
                                ])
                                row_id += 1

    print(f"Generating {len(rows)} rows into {OUT_PATH}")

    with OUT_PATH.open("w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow(headers)
        writer.writerows(rows)


if __name__ == "__main__":
    main()
