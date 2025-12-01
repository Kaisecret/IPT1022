<?php
session_start();

// simple auth check: only allow logged-in users
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

require_once 'db.php';

$userId = (int)$_SESSION['user_id'];

// Load user preferences from DB (for default form values)
$stmt = $pdo->prepare("
    SELECT age, gender, goal, experience, equipment, workout_time
    FROM users
    WHERE id = :id
");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    // Fallback defaults if something is wrong
    $user = [
        'age'          => 20,
        'gender'       => 'Male',
        'goal'         => 'fat loss',
        'experience'   => 'beginner',
        'equipment'    => 'gym',
        'workout_time' => '45-60 min'
    ];
}

// If user clicked from history.php: home.php?analysis_id=123
$initialAnalysisId = isset($_GET['analysis_id']) ? (int)$_GET['analysis_id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Physique Check - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-black text-white" data-page="home">

<div class="relative min-h-screen flex">
    <!-- Mobile Overlay -->
    <div id="mobile-overlay" class="fixed inset-0 bg-black/60 z-40 hidden md:hidden"></div>

    <!-- Sidebar (Desktop) -->
    <aside id="sidebar" class="fixed top-0 left-0 h-screen bg-gray-900 text-white z-50 flex flex-col w-64 hidden md:flex border-r border-gray-800 transition-all duration-300">
        <div class="flex items-center px-6 h-20 border-b border-gray-800 overflow-hidden">
            <svg class="w-8 h-8 text-white flex-shrink-0" viewBox="0 0 100 100" fill="none">
                <path d="M50 10 C 70 10, 85 25, 85 45 C 85 70, 65 90, 50 90 C 35 90, 15 70, 15 45 C 15 25, 30 10, 50 10 Z" stroke="currentColor" stroke-width="5" />
                <path d="M35 50 L48 63 L65 40" stroke="#31FF75" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <span class="text-xl font-bold ml-3 sidebar-text whitespace-nowrap">Physique Check</span>
        </div>
        <nav class="flex-1 px-2 py-4 space-y-2">
            <a href="home.php" class="w-full flex items-center p-3 rounded-lg bg-green-500/20 text-green-400 font-semibold group">
                <svg class="w-6 h-6 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h7.5" />
                </svg>
                <span class="ml-4 sidebar-text whitespace-nowrap">Home</span>
            </a>
            <a href="stats.php" class="w-full flex items-center p-3 rounded-lg text-gray-300 hover:bg-gray-700/50 hover:text-white transition-colors group">
                <svg class="w-6 h-6 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                </svg>
                <span class="ml-4 sidebar-text whitespace-nowrap">Your Stats</span>
            </a>
            <a href="history.php" class="w-full flex items-center p-3 rounded-lg text-gray-300 hover:bg-gray-700/50 hover:text-white transition-colors group">
                <svg class="w-6 h-6 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="ml-4 sidebar-text whitespace-nowrap">History</span>
            </a>
            <a href="profile.php" class="w-full flex items-center p-3 rounded-lg text-gray-300 hover:bg-gray-700/50 hover:text-white transition-colors group">
                <svg class="w-6 h-6 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <span class="ml-4 sidebar-text whitespace-nowrap">Profile</span>
            </a>
        </nav>
        <div class="px-2 py-4 border-t border-gray-800 space-y-2">
            <a href="#" onclick="openLogoutModal();return false;" class="w-full flex items-center p-3 rounded-lg text-gray-300 hover:bg-red-500/50 hover:text-white transition-colors group">
                <svg class="w-6 h-6 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                </svg>
                <span class="ml-4 sidebar-text whitespace-nowrap">Logout</span>
            </a>
        </div>
        <button class="sidebar-toggle absolute -right-3 top-8 bg-gray-700 text-white hover:bg-green-500 rounded-full p-1.5 focus:outline-none">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
        </button>
    </aside>

    <!-- Mobile Sidebar -->
    <aside id="mobile-sidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-gray-900 -translate-x-full transition-transform duration-300 md:hidden">
        <div class="flex items-center px-6 h-20 border-b border-gray-800">
            <span class="text-xl font-bold ml-3">Physique Check</span>
        </div>
        <nav class="px-2 py-4 space-y-2">
            <a href="home.php" class="block p-3 rounded bg-gray-800 text-green-400">Home</a>
            <a href="stats.php" class="block p-3 rounded text-gray-300">Stats</a>
            <a href="history.php" class="block p-3 rounded text-gray-300">History</a>
            <a href="profile.php" class="block p-3 rounded text-gray-300">Profile</a>
            <a href="#" onclick="openLogoutModal();return false;" class="block p-3 rounded text-red-400">Logout</a>
        </nav>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="main-content flex-1 flex flex-col md:ml-64 transition-all duration-300">
        <!-- Mobile Header -->
        <div class="md:hidden sticky top-0 flex items-center p-4 bg-gray-900/80 backdrop-blur-sm border-b border-gray-800 z-30">
            <button class="sidebar-toggle">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>
            <span class="ml-4 font-bold text-xl">Physique Check</span>
        </div>

        <div class="p-6 container mx-auto max-w-6xl pb-24">
            <!-- BACKEND STATUS BANNER -->
            <div id="backend-status" class="mb-4 text-sm hidden"></div>

            <!-- UPLOAD VIEW -->
            <div id="upload-view" class="">
                <h2 class="text-3xl font-bold mb-2">Upload Your Physique Photos</h2>
                <p class="text-gray-400 mb-8">For the best analysis, please upload exactly 3 photos (Front, Side, Back).</p>

                <!-- Drop Zone -->
                <div id="drop-zone" class="drop-zone border-2 border-dashed border-gray-700 hover:border-green-600 rounded-xl p-8 text-center cursor-pointer bg-gray-900/20 mt-6 transition-colors">
                    <svg class="w-12 h-12 mx-auto text-gray-500 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    <p>Click to select or drag 'n' drop photos here (Max 3)</p>
                    <p id="photo-count" class="text-sm text-green-400 mt-2 font-bold hidden">0 / 3 Photos Selected</p>
                </div>

                <!-- Hidden Input -->
                <input type="file" id="file-input" multiple accept="image/*" class="hidden">

                <div id="image-previews" class="mt-8 grid grid-cols-3 gap-4"></div>

                <div class="mt-8 bg-gray-900/50 p-6 rounded-xl border border-gray-800">
                    <h3 class="text-xl font-bold mb-4">Personal Details & Preferences</h3>

                    <!-- Personal Stats Grid -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Sex</label>
                            <select id="pref-sex" class="w-full bg-gray-800 border-gray-700 rounded-md p-2">
                                <option value="Male"   <?php if ($user['gender'] === 'Male')   echo 'selected'; ?>>Male</option>
                                <option value="Female" <?php if ($user['gender'] === 'Female') echo 'selected'; ?>>Female</option>
                                <option value="Other"  <?php if ($user['gender'] === 'Other')  echo 'selected'; ?>>Other</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Age</label>
                            <input type="number" id="pref-age"
                                   value="<?php echo (int)$user['age']; ?>"
                                   class="w-full bg-gray-800 border-gray-700 rounded-md p-2 text-white focus:ring-green-500 focus:border-green-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Height (cm)</label>
                            <input type="number" id="pref-height" value="170" class="w-full bg-gray-800 border-gray-700 rounded-md p-2 text-white focus:ring-green-500 focus:border-green-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Weight (kg)</label>
                            <input type="number" id="pref-weight" value="65" class="w-full bg-gray-800 border-gray-700 rounded-md p-2 text-white focus:ring-green-500 focus:border-green-500">
                        </div>
                    </div>

                    <!-- Workout Prefs Grid -->
                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Activity Level</label>
                            <select id="pref-activity" class="w-full bg-gray-800 border-gray-700 rounded-md p-2">
                                <option value="Sedentary">Sedentary</option>
                                <option value="Lightly Active">Lightly Active</option>
                                <option value="Moderately Active">Moderately Active</option>
                                <option value="Very Active">Very Active</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Primary Goal</label>
                            <select id="pref-goal" class="w-full bg-gray-800 border-gray-700 rounded-md p-2">
                                <option value="fat loss"    <?php if ($user['goal'] === 'fat loss')    echo 'selected'; ?>>Fat Loss</option>
                                <option value="muscle gain" <?php if ($user['goal'] === 'muscle gain') echo 'selected'; ?>>Muscle Gain</option>
                                <option value="recomposition">Recomposition</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Experience</label>
                            <select id="pref-experience" class="w-full bg-gray-800 border-gray-700 rounded-md p-2">
                                <option value="beginner"     <?php if ($user['experience'] === 'beginner')     echo 'selected'; ?>>Beginner</option>
                                <option value="intermediate" <?php if ($user['experience'] === 'intermediate') echo 'selected'; ?>>Intermediate</option>
                                <option value="advanced"     <?php if ($user['experience'] === 'advanced')     echo 'selected'; ?>>Advanced</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Equipment</label>
                            <select id="pref-equipment" class="w-full bg-gray-800 border-gray-700 rounded-md p-2">
                                <option value="gym"               <?php if ($user['equipment'] === 'gym')               echo 'selected'; ?>>Gym</option>
                                <option value="home"              <?php if ($user['equipment'] === 'home')              echo 'selected'; ?>>Home</option>
                                <option value="minimal equipment" <?php if ($user['equipment'] === 'minimal equipment') echo 'selected'; ?>>Minimal Equipment</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Time per Workout</label>
                            <select id="pref-time" class="w-full bg-gray-800 border-gray-700 rounded-md p-2">
                                <option value="45-60 min" <?php if ($user['workout_time'] === '45-60 min') echo 'selected'; ?>>45-60 min</option>
                                <option value="30-45 min" <?php if ($user['workout_time'] === '30-45 min') echo 'selected'; ?>>30-45 min</option>
                                <option value="20-30 min" <?php if ($user['workout_time'] === '20-30 min') echo 'selected'; ?>>20-30 min</option>
                                <option value="60+ min"   <?php if ($user['workout_time'] === '60+ min')   echo 'selected'; ?>>60+ min</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mt-8 flex justify-end">
                    <button id="analyze-btn" disabled class="bg-gray-700 text-white font-bold text-lg px-8 py-3 rounded-lg opacity-50 cursor-not-allowed transition-colors hover:bg-green-500 hover:text-black">
                        Analyze My Physique
                    </button>
                </div>
            </div>

            <!-- LOADING VIEW -->
            <div id="analyzing-view" class="hidden min-h-[50vh] flex flex-col items-center justify-center text-center">
                <div class="loader mb-6"></div>
                <h2 class="text-3xl font-bold mb-2">Analyzing Your Physique...</h2>
                <p class="text-gray-400">Our AI is assessing your muscle development and structure.</p>
            </div>

            <!-- RESULTS VIEW -->
            <div id="results-view" class="hidden">
                <div class="flex justify-between items-center mb-8">
                    <h2 class="text-4xl font-bold">Your Analysis</h2>
                    <button class="new-analysis-btn flex items-center gap-2 text-green-400 border border-green-500 px-4 py-2 rounded hover:bg-green-500/10">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0011.664 0l3.181-3.183m-11.664 0l3.181-3.183a8.25 8.25 0 00-11.664 0l-3.181 3.183" />
                        </svg>
                        Start New Analysis
                    </button>
                </div>

                <!-- Tabs -->
                <div class="flex space-x-4 border-b border-gray-700 mb-8 overflow-x-auto">
                    <button class="tab-btn active px-4 py-3 font-semibold whitespace-nowrap" data-tab="tab-content-report">Physique Report</button>
                    <button class="tab-btn px-4 py-3 font-semibold text-gray-400 hover:text-white whitespace-nowrap" data-tab="tab-content-workout">Workout Plan</button>
                    <button class="tab-btn px-4 py-3 font-semibold text-gray-400 hover:text-white whitespace-nowrap" data-tab="tab-content-meal">Meal Guide</button>
                </div>

                <!-- Report Tab -->
                <div id="tab-content-report">
                    <div class="grid md:grid-cols-2 gap-8 items-center mb-8">
                        <div class="bg-gray-900/50 p-6 rounded-xl border border-gray-800 h-80" id="radar-chart-container">
                            <!-- Radar Chart Injected Here -->
                        </div>
                        <div class="space-y-4">
                            <div class="bg-gray-900/50 p-6 rounded-xl border border-gray-800">
                                <h4 class="text-lg font-semibold text-green-400">Overall Score: <span id="overall-score"></span></h4>
                                <p class="text-gray-300 mt-2" id="summary-text"></p>
                            </div>
                            <div class="bg-gray-900/50 p-6 rounded-xl border border-gray-800">
                                <h4 class="text-lg font-semibold text-green-400">Posture & Structure</h4>
                                <p class="text-gray-300 mt-2" id="posture-text"></p>
                            </div>
                        </div>
                    </div>
                    <h3 class="text-2xl font-bold mb-6 text-center">Detailed Muscle Group Report</h3>
                    <div id="muscle-details-grid" class="grid md:grid-cols-2 lg:grid-cols-3 gap-6"></div>
                </div>

                <!-- Workout Tab -->
                <div id="tab-content-workout" class="hidden">
                    <div id="workout-plan-container" class="space-y-6"></div>
                </div>

                <!-- Meal Tab -->
                <div id="tab-content-meal" class="hidden">
                    <div id="meal-guide-container" class="space-y-8"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Bottom Navigation -->
<nav class="fixed bottom-0 left-0 right-0 bg-gray-900 border-t border-gray-800 flex justify-around items-center h-16 z-50 md:hidden">
    <a href="home.php" class="flex flex-col items-center justify-center w-full h-full text-green-400">
        <svg class="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h7.5" />
        </svg>
        <span class="text-[10px] font-medium">Home</span>
    </a>
    <a href="stats.php" class="flex flex-col items-center justify-center w-full h-full text-gray-400 hover:text白 transition-colors">
        <svg class="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
        </svg>
        <span class="text-[10px] font-medium">Stats</span>
    </a>
    <a href="history.php" class="flex flex-col items-center justify-center w-full h-full text-gray-400 hover:text-white transition-colors">
        <svg class="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span class="text-[10px] font-medium">History</span>
    </a>
    <a href="profile.php" class="flex flex-col items-center justify-center w-full h-full text-gray-400 hover:text-white transition-colors">
        <svg class="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
        <span class="text-[10px] font-medium">Profile</span>
    </a>
</nav>

<script type="module">
    const BACKEND_URL = "http://127.0.0.1:8000"; // or "http://localhost:8000"
    const INITIAL_ANALYSIS_ID = <?php echo $initialAnalysisId; ?>;

    // ===== Backend call =====
    async function sendToBackend(selectedFiles, prefs) {
        const formData = new FormData();
        formData.append("front", selectedFiles[0]);
        formData.append("back", selectedFiles[1]);
        formData.append("legs", selectedFiles[2]);
        formData.append("preferences", JSON.stringify(prefs));

        let res;
        try {
            res = await fetch(`${BACKEND_URL}/analyze`, {
                method: "POST",
                body: formData
            });
        } catch (networkErr) {
            console.error("Network error when calling backend:", networkErr);
            throw new Error(`Cannot reach backend at ${BACKEND_URL}. Is it running?`);
        }

        if (!res.ok) {
            let text = "";
            try { text = await res.text(); } catch {}
            console.error("Backend returned error:", res.status, text);
            throw new Error("Backend error " + res.status);
        }

        const data = await res.json();
        console.log("Backend JSON response:", data);
        return data;
    }

    function initSidebar() {
        const sidebar = document.getElementById('sidebar');
        const mobileSidebar = document.getElementById('mobile-sidebar');
        const mainContent = document.querySelector('.main-content');
        const overlay = document.getElementById('mobile-overlay');

        const toggleSidebar = () => {
            const width = window.innerWidth;
            if (width < 768) {
                mobileSidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('hidden');
            } else {
                if (!sidebar) return;
                const collapsed = sidebar.classList.contains('w-20');
                sidebar.classList.toggle('w-20', !collapsed);
                sidebar.classList.toggle('w-64', collapsed);
                mainContent.classList.toggle('md:ml-20', !collapsed);
                mainContent.classList.toggle('md:ml-64', collapsed);
                document.querySelectorAll('.sidebar-text').forEach(el => {
                    el.classList.toggle('hidden', !collapsed);
                });
            }
        };

        document.querySelectorAll('.sidebar-toggle').forEach(btn => {
            btn.addEventListener('click', toggleSidebar);
        });
        if (overlay) {
            overlay.addEventListener('click', toggleSidebar);
        }
    }

    function renderRadarChart(data, containerId) {
        const container = document.getElementById(containerId);
        if (!container || !data) return;

        const size = 300;
        const center = size / 2;
        const radius = size * 0.4;
        const subjects = ['Chest', 'Abs', 'Arms', 'Back', 'Legs'];
        const scores = [
            data.chest.score,
            data.abs.score,
            data.arms.score,
            data.back.score,
            data.legs.score
        ];

        const angleSlice = (Math.PI * 2) / subjects.length;
        const levels = 5;
        let svg = `<svg width="100%" height="100%" viewBox="0 0 ${size} ${size}">`;

        for (let i = 1; i <= levels; i++) {
            const r = radius * (i / levels);
            let pts = "";
            for (let j = 0; j < subjects.length; j++) {
                const x = center + r * Math.cos(j * angleSlice - Math.PI / 2);
                const y = center + r * Math.sin(j * angleSlice - Math.PI / 2);
                pts += `${x},${y} `;
            }
            svg += `<polygon points="${pts}" stroke="#444" fill="none"/>`;
        }

        for (let j = 0; j < subjects.length; j++) {
            const ax = center + radius * Math.cos(j * angleSlice - Math.PI / 2);
            const ay = center + radius * Math.sin(j * angleSlice - Math.PI / 2);
            svg += `<line x1="${center}" y1="${center}" x2="${ax}" y2="${ay}" stroke="#555"/>`;

            const lx = center + (radius + 20) * Math.cos(j * angleSlice - Math.PI / 2);
            const ly = center + (radius + 20) * Math.sin(j * angleSlice - Math.PI / 2);
            svg += `<text x="${lx}" y="${ly}" fill="#ccc" font-size="12" text-anchor="middle">${subjects[j]}</text>`;
        }

        let pts = "";
        for (let j = 0; j < subjects.length; j++) {
            const val = scores[j] / 10;
            const x = center + (radius * val) * Math.cos(j * angleSlice - Math.PI / 2);
            const y = center + (radius * val) * Math.sin(j * angleSlice - Math.PI / 2);
            pts += `${x},${y} `;
        }
        svg += `<polygon points="${pts}" fill="rgba(0,255,128,0.3)" stroke="#22c55e" stroke-width="2"/>`;
        svg += `</svg>`;

        container.innerHTML = svg;
    }

    function renderReport(report, plans) {
        if (!report || !plans) {
            console.warn("renderReport called with missing data:", report, plans);
            return;
        }

        renderRadarChart(report.muscleAnalysis, 'radar-chart-container');

        document.getElementById('overall-score').innerText =
            report.physiqueRating.overallScore + "/10";
        document.getElementById('summary-text').innerText =
            report.physiqueRating.summary;
        document.getElementById('posture-text').innerText =
            report.postureNotes;

        const muscleContainer = document.getElementById('muscle-details-grid');
        muscleContainer.innerHTML = '';
        Object.entries(report.muscleAnalysis).forEach(([name, data]) => {
            muscleContainer.innerHTML += `
                <div class="bg-gray-900/50 p-6 rounded-xl border border-gray-800">
                    <h4 class="text-xl font-bold capitalize mb-3">${name}</h4>
                    <p><strong class="text-green-400">Strengths:</strong> ${data.strengths}</p>
                    <p class="mt-2"><strong class="text-yellow-400">Weaknesses:</strong> ${data.weaknesses}</p>
                    <p class="mt-2 text-sm text-gray-400"><strong class="text-blue-400">Symmetry:</strong> ${data.symmetryNotes}</p>
                </div>
            `;
        });

        const workoutContainer = document.getElementById('workout-plan-container');
        workoutContainer.innerHTML = plans.workoutPlan.plan.map(day => `
            <div class="bg-gray-900/50 p-6 rounded-xl border border-gray-800 mb-4">
                <div class="font-bold text-xl flex justify-between items-center mb-2">
                    <div><span class="text-green-400">${day.dayOfWeek}:</span> ${day.targetMuscle}</div>
                </div>
                <div class="text-gray-300 space-y-2">
                    <p><strong>Warm-up:</strong> ${day.warmup}</p>
                    <ul class="pl-4 border-l-2 border-gray-700 my-3">
                        ${day.exercises.map(ex =>
                            `<li><strong>${ex.name}</strong>: ${ex.sets} x ${ex.reps} (${ex.rest} rest)</li>`
                        ).join('')}
                    </ul>
                    <p><strong>Cooldown:</strong> ${day.cooldown}</p>
                </div>
            </div>
        `).join('');

        const mealContainer = document.getElementById('meal-guide-container');
        mealContainer.innerHTML = `
            <div class="text-center bg-gray-900/50 p-6 rounded-xl border border-gray-800 mb-6">
                <div class="grid grid-cols-4 gap-4">
                    <div><p class="text-gray-400">Calories</p><p class="text-2xl font-bold text-green-400">${plans.mealGuide.dailyCalorieTarget}</p></div>
                    <div><p class="text-gray-400">Protein</p><p class="text-2xl font-bold">${plans.mealGuide.macros.protein}</p></div>
                    <div><p class="text-gray-400">Carbs</p><p class="text-2xl font-bold">${plans.mealGuide.macros.carbs}</p></div>
                    <div><p class="text-gray-400">Fats</p><p class="text-2xl font-bold">${plans.mealGuide.macros.fats}</p></div>
                </div>
            </div>
            <div class="grid md:grid-cols-2 gap-6">
                ${plans.mealGuide.meals.map(meal => `
                    <div class="bg-gray-900/50 p-6 rounded-xl border border-gray-800">
                        <h4 class="text-xl font-bold mb-2">${meal.name}</h4>
                        <p class="text-gray-400 text-sm mb-2">${meal.notes}</p>
                        <ul class="list-disc list-inside text-gray-300 text-sm">
                            ${meal.ingredients.map(i => `<li>${i}</li>`).join('')}
                        </ul>
                    </div>
                `).join('')}
            </div>
        `;

        const tabs = {
            "tab-content-report": document.getElementById('tab-content-report'),
            "tab-content-workout": document.getElementById('tab-content-workout'),
            "tab-content-meal": document.getElementById('tab-content-meal')
        };

        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('.tab-btn').forEach(b =>
                    b.classList.remove('active', 'border-b-2', 'border-green-500', 'text-green-500')
                );
                e.currentTarget.classList.add('active', 'border-b-2', 'border-green-500', 'text-green-500');

                const targetId = e.currentTarget.dataset.tab;
                Object.values(tabs).forEach(el => el.classList.add('hidden'));
                tabs[targetId].classList.remove('hidden');
            });
        });
    }

    let selectedFiles = [];

    function initHomePage() {
        const uploadView = document.getElementById('upload-view');
        const analyzingView = document.getElementById('analyzing-view');
        const resultsView = document.getElementById('results-view');
        const photoCountLabel = document.getElementById('photo-count');
        const previewContainer = document.getElementById('image-previews');
        const analyzeBtn = document.getElementById('analyze-btn');
        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');

        selectedFiles = [];

        document.querySelectorAll('.new-analysis-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                resultsView.classList.add('hidden');
                uploadView.classList.remove('hidden');
                selectedFiles = [];
                updateFileUI();
            });
        });

        function addFiles(files) {
            const incoming = Array.from(files);
            const remaining = 3 - selectedFiles.length;
            if (remaining <= 0) {
                alert("You already selected 3 photos.");
                return;
            }
            const toAdd = incoming.slice(0, remaining);
            selectedFiles = [...selectedFiles, ...toAdd];
            updateFileUI();
        }

        function updateFileUI() {
            previewContainer.innerHTML = '';
            selectedFiles.forEach((file, index) => {
                const div = document.createElement('div');
                div.className = 'relative group';
                div.innerHTML = `
                    <img src="${URL.createObjectURL(file)}"
                         class="w-full h-32 object-cover rounded-lg border border-gray-700" />
                    <button onclick="window.removeFile(${index})"
                        class="absolute top-1 right-1 bg-red-600 text-white rounded-full p-1 shadow-lg opacity-90 hover:opacity-100 transition-opacity">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                `;
                previewContainer.appendChild(div);
            });

            if (selectedFiles.length > 0) {
                photoCountLabel.classList.remove('hidden');
                photoCountLabel.innerText = `${selectedFiles.length} / 3 Photos Selected`;
            } else {
                photoCountLabel.classList.add('hidden');
                photoCountLabel.innerText = `0 / 3 Photos Selected`;
            }

            if (selectedFiles.length === 3) {
                analyzeBtn.disabled = false;
                analyzeBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                analyzeBtn.innerText = "Analyze My Physique";
            } else {
                analyzeBtn.disabled = true;
                analyzeBtn.classList.add('opacity-50', 'cursor-not-allowed');
                analyzeBtn.innerText = `Select ${3 - selectedFiles.length} more photo(s)`;
            }
        }

        window.removeFile = (index) => {
            selectedFiles.splice(index, 1);
            updateFileUI();
        };

        dropZone.addEventListener('click', () => fileInput.click());
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('active');
        });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('active'));
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('active');
            addFiles(e.dataTransfer.files);
        });
        fileInput.addEventListener('change', (e) => {
            addFiles(e.target.files);
            fileInput.value = '';
        });

        analyzeBtn.addEventListener('click', async () => {
            if (selectedFiles.length !== 3) return;

            uploadView.classList.add('hidden');
            analyzingView.classList.remove('hidden');

            const prefs = {
                sex: document.getElementById('pref-sex').value,
                age: document.getElementById('pref-age').value,
                height: document.getElementById('pref-height').value,
                weight: document.getElementById('pref-weight').value,
                activity: document.getElementById('pref-activity').value,
                goal: document.getElementById('pref-goal').value,
                experience: document.getElementById('pref-experience').value,
                equipment: document.getElementById('pref-equipment').value,
                time: document.getElementById('pref-time').value
            };

            try {
                const backendRes = await sendToBackend(selectedFiles, prefs);

                if (!backendRes || !backendRes.analysis || !backendRes.plans) {
                    throw new Error("Invalid response from backend");
                }

                const report = backendRes.analysis;
                const plans = backendRes.plans;

                // Save analysis + plans to DB
                try {
                    const saveResponse = await fetch('save_analysis.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ analysis: report, plans: plans })
                    });
                    const saveRes = await saveResponse.json();
                    console.log('Saved analysis to DB:', saveRes);
                } catch (e) {
                    console.error('Error saving analysis to DB:', e);
                    alert('Analysis created, but saving to history failed.');
                }

                // Render view
                renderReport(report, plans);

                analyzingView.classList.add('hidden');
                resultsView.classList.remove('hidden');
            } catch (err) {
                console.error("Error during analyze:", err);
                alert("Error contacting backend: " + err.message);
                analyzingView.classList.add('hidden');
                uploadView.classList.remove('hidden');
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initSidebar();

        if (document.body.dataset.page === 'home') {
            initHomePage();

            // If coming from history.php with a specific analysis id
            if (INITIAL_ANALYSIS_ID > 0) {
                const uploadView = document.getElementById('upload-view');
                const analyzingView = document.getElementById('analyzing-view');
                const resultsView = document.getElementById('results-view');

                uploadView.classList.add('hidden');
                analyzingView.classList.remove('hidden');

                (async () => {
                    try {
                        const res = await fetch(`get_analysis.php?id=${INITIAL_ANALYSIS_ID}`);
                        if (!res.ok) throw new Error('Failed to load analysis');
                        const data = await res.json();
                        if (!data.analysis || !data.plans) throw new Error('Invalid analysis data');

                        renderReport(data.analysis, data.plans);

                        analyzingView.classList.add('hidden');
                        resultsView.classList.remove('hidden');
                    } catch (e) {
                        console.error(e);
                        alert('Could not load analysis from history.');
                        analyzingView.classList.add('hidden');
                        uploadView.classList.remove('hidden');
                    }
                })();
            }
        }
    });
</script>

<!-- Logout Confirmation Modal (shared across pages) -->
<div id="logout-modal" class="fixed inset-0 z-[999] hidden bg-black/70 flex items-center justify-center">
    <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 w-full max-w-sm">
        <h2 class="text-xl font-bold mb-2">Log out?</h2>
        <p class="text-gray-300 mb-6">You’ll be signed out of your account on this device.</p>
        <div class="flex justify-end gap-3">
            <button type="button"
                    onclick="closeLogoutModal()"
                    class="px-4 py-2 rounded-lg bg-gray-700 hover:bg-gray-600 text-sm">
                Cancel
            </button>
            <button type="button"
                    onclick="confirmLogout()"
                    class="px-4 py-2 rounded-lg bg-red-500 hover:bg-red-400 text-sm font-semibold text-black">
                Log out
            </button>
        </div>
    </div>
</div>

<script>
    function openLogoutModal() {
        const modal = document.getElementById('logout-modal');
        if (modal) modal.classList.remove('hidden');
    }

    function closeLogoutModal() {
        const modal = document.getElementById('logout-modal');
        if (modal) modal.classList.add('hidden');
    }

    function confirmLogout() {
        // No more localStorage usage; just kill session on server
        window.location.href = 'logout.php';
    }
</script>

</body>
</html>
