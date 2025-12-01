<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---- DB CONNECTION ----
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'physique_check';

$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

$statsHistory = [];
$activeReport = null;

if (!$mysqli->connect_error) {

    // -----------------------------
    // LOAD physique_analyses
    // -----------------------------
    $stmt1 = $mysqli->prepare("
        SELECT overall_score, chest_score, abs_score, arms_score, back_score, legs_score, created_at
        FROM physique_analyses
        WHERE user_id = ?
    ");
    $stmt1->bind_param('i', $userId);
    $stmt1->execute();
    $res1 = $stmt1->get_result();

    while ($row = $res1->fetch_assoc()) {
        $statsHistory[] = [
            'timestamp'    => $row['created_at'],
            'overallScore' => (float)$row['overall_score'],
            'chest'        => (float)$row['chest_score'],
            'abs'          => (float)$row['abs_score'],
            'arms'         => (float)$row['arms_score'],
            'back'         => (float)$row['back_score'],
            'legs'         => (float)$row['legs_score'],
        ];
    }
    $stmt1->close();


    // -----------------------------
    // LOAD analyses (simple table)
    // -----------------------------
    $stmt2 = $mysqli->prepare("
        SELECT overall_score, chest_score, abs_score, arms_score, back_score, legs_score, created_at
        FROM analyses
        WHERE user_id = ?
    ");
    $stmt2->bind_param('i', $userId);
    $stmt2->execute();
    $res2 = $stmt2->get_result();

    while ($row = $res2->fetch_assoc()) {
        $statsHistory[] = [
            'timestamp'    => $row['created_at'],
            'overallScore' => (float)$row['overall_score'],
            'chest'        => (float)$row['chest_score'],
            'abs'          => (float)$row['abs_score'],
            'arms'         => (float)$row['arms_score'],
            'back'         => (float)$row['back_score'],
            'legs'         => (float)$row['legs_score'],
        ];
    }
    $stmt2->close();

    // -----------------------------
    // FINAL MERGE (B1 — chronological)
    // -----------------------------
    usort($statsHistory, function($a, $b) {
        return strtotime($a['timestamp']) <=> strtotime($b['timestamp']);
    });

    // Build active report (latest)
    if (!empty($statsHistory)) {
        $latest = end($statsHistory);

        $activeReport = [
            'physiqueRating' => [
                'overallScore' => $latest['overallScore'],
            ],
            'muscleAnalysis' => [
                'chest' => ['score' => $latest['chest']],
                'abs'   => ['score' => $latest['abs']],
                'arms'  => ['score' => $latest['arms']],
                'back'  => ['score' => $latest['back']],
                'legs'  => ['score' => $latest['legs']],
            ],
        ];
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Physique Check - Stats</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-black text-white" data-page="stats">

    <!-- Make DB data available to JS (no UI change) -->
    <script>
        const DB_STATS_HISTORY = <?php echo json_encode($statsHistory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const DB_ACTIVE_REPORT = <?php echo json_encode($activeReport, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>

    <div class="relative min-h-screen flex">
        <!-- Optional mobile overlay -->
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
                <a href="home.php" class="w-full flex items-center p-3 rounded-lg text-gray-300 hover:bg-gray-700/50 hover:text-white transition-colors group">
                    <svg class="w-6 h-6 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h7.5" />
                    </svg>
                    <span class="ml-4 sidebar-text whitespace-nowrap">Home</span>
                </a>
                <a href="stats.php" class="w-full flex items-center p-3 rounded-lg bg-green-500/20 text-green-400 font-semibold group">
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
                <a href="#" onclick="openLogoutModal(); return false;" class="w-full flex items-center p-3 rounded-lg text-gray-300 hover:bg-red-500/50 hover:text-white transition-colors group">
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
                <a href="home.php" class="block p-3 rounded text-gray-300">Home</a>
                <a href="stats.php" class="block p-3 rounded bg-gray-800 text-green-400">Stats</a>
                <a href="history.php" class="block p-3 rounded text-gray-300">History</a>
                <a href="profile.php" class="block p-3 rounded text-gray-300">Profile</a>
                <a href="#" onclick="openLogoutModal(); return false;" class="block p-3 rounded text-red-400">Logout</a>
            </nav>
        </aside>

        <div class="main-content flex-1 flex flex-col md:ml-64 transition-all duration-300">
            <!-- Mobile Header -->
            <div class="md:hidden sticky top-0 flex items-center p-4 bg-gray-900/80 backdrop-blur-sm border-b border-gray-800 z-30">
                <button class="sidebar-toggle">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>
                <span class="ml-4 font-bold text-xl">Your Stats</span>
            </div>

            <div class="p-6 container mx-auto max-w-6xl pb-24">
                <!-- No Stats State -->
                <div id="no-stats-view" class="hidden bg-gray-900/50 p-8 rounded-xl border border-gray-800 text-center">
                    <h3 class="text-xl font-bold">No Stats to Display Yet</h3>
                    <p class="text-gray-400 mt-2">Complete your first physique analysis to unlock your personalized stats dashboard.</p>
                    <a href="home.php" class="mt-6 inline-block bg-green-500 text-black font-bold px-4 py-2 rounded-lg hover:bg-green-400 transition-colors">Analyze Physique</a>
                </div>

                <!-- Stats Content -->
                <div id="stats-content" class="space-y-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div class="bg-gray-800/50 p-6 rounded-xl">
                            <h3 class="text-lg font-semibold text-gray-300">Current Physique Rating</h3>
                            <p class="text-5xl font-bold text-green-400 mt-2" id="stat-overall-score">-</p>
                            <p class="text-gray-400 text-xs mt-3" id="stat-latest-date">Last analysis: -</p>
                        </div>
                        <div class="bg-green-500/10 border border-green-500/50 p-6 rounded-xl">
                             <h3 class="text-lg font-semibold text-green-300">Strongest Area (latest)</h3>
                             <p class="text-3xl font-bold capitalize mt-2" id="stat-strongest-name">-</p>
                        </div>
                        <div class="bg-yellow-500/10 border border-yellow-500/50 p-6 rounded-xl">
                             <h3 class="text-lg font-semibold text-yellow-300">Needs More Work (latest)</h3>
                             <p class="text-3xl font-bold capitalize mt-2" id="stat-weakest-name">-</p>
                        </div>
                    </div>
                    
                    <!-- Animated bar chart -->
                    <div class="bg-gray-800/50 p-6 rounded-xl h-96" id="stats-bar-chart">
                        <!-- Bar Chart Injected via JS -->
                    </div>

                    <!-- Progress history details -->
                    <div id="stats-history" class="bg-gray-800/50 p-6 rounded-xl space-y-4">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-200">Step-by-Step Progress Log</h3>
                                <p class="text-gray-400 text-sm">
                                    Each time you run an analysis, we save a timestamp and all muscle scores. 
                                    Use this log together with the bar chart to see how your physique evolves over time.
                                </p>
                            </div>
                        </div>

                        <div class="mt-4 max-h-64 overflow-y-auto border-t border-gray-700 pt-4" id="history-list">
                            <!-- History Cards Injected via JS -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <nav class="fixed bottom-0 left-0 right-0 bg-gray-900 border-t border-gray-800 flex justify-around items-center h-16 z-50 md:hidden">
        <a href="home.php" class="flex flex-col items-center justify-center w-full h-full text-gray-400 hover:text-white transition-colors">
            <svg class="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h7.5" />
            </svg>
            <span class="text-[10px] font-medium">Home</span>
        </a>
        <a href="stats.php" class="flex flex-col items-center justify-center w-full h-full text-green-400">
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

    <script>
        const MUSCLES = ['chest', 'abs', 'arms', 'back', 'legs'];

        function initSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mobileSidebar = document.getElementById('mobile-sidebar');
            const mainContent = document.querySelector('.main-content');
            const overlay = document.getElementById('mobile-overlay');

            const toggleSidebar = () => {
                const width = window.innerWidth;
                if (width < 768) {
                    mobileSidebar.classList.toggle('-translate-x-full');
                    if (overlay) overlay.classList.toggle('hidden');
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

            document.querySelectorAll('.sidebar-toggle').forEach(btn => btn.addEventListener('click', toggleSidebar));
            if (overlay) overlay.addEventListener('click', toggleSidebar);
        }

        document.addEventListener('DOMContentLoaded', () => {
            initSidebar();

            const statsHistory = Array.isArray(DB_STATS_HISTORY) ? DB_STATS_HISTORY : [];
            const activeReport = DB_ACTIVE_REPORT || null;

            if (!activeReport || !activeReport.muscleAnalysis || statsHistory.length === 0) {
                document.getElementById('no-stats-view').classList.remove('hidden');
                document.getElementById('stats-content').classList.add('hidden');
                return;
            }

            const latestSnapshot = statsHistory[statsHistory.length - 1];
            const latestDateText = latestSnapshot && latestSnapshot.timestamp
                ? new Date(latestSnapshot.timestamp).toLocaleString()
                : '-';

            // Current overview
            document.getElementById('stat-overall-score').innerText =
                activeReport.physiqueRating.overallScore;
            document.getElementById('stat-latest-date').innerText =
                `Last analysis: ${latestDateText}`;

            // Strongest / weakest from latest report
            let strongest = {name: '', score: -1};
            let weakest = {name: '', score: 11};

            Object.entries(activeReport.muscleAnalysis).forEach(([name, data]) => {
                const score = data.score ?? 0;
                if (score > strongest.score) strongest = {name, score};
                if (score < weakest.score) weakest = {name, score};
            });

            document.getElementById('stat-strongest-name').innerText = strongest.name;
            document.getElementById('stat-weakest-name').innerText = weakest.name;

            renderAnimatedBarChart(statsHistory, 'stats-bar-chart');
            renderHistoryList(statsHistory, 'history-list');
        });

        // Animated bar chart: CSS-driven height animation
       function renderAnimatedBarChart(history, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    if (!history || history.length === 0) {
        container.innerHTML = `<p class="text-gray-400 text-sm">No analyses saved yet.</p>`;
        return;
    }

    const safeHistory = history.slice(-12);
    const maxBarHeight = 260;

    container.innerHTML = `
        <div class="flex flex-col h-full w-full">
            
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm md:text-base font-semibold text-gray-100">
                    Overall Progress (each green bar = one analysis)
                </h3>
            </div>

            <div class="flex flex-row h-full w-full">

                <!-- Y-Axis labels (FIXED alignment) -->
                <div class="grid text-gray-400 text-xs pr-2"
                     style="height:${maxBarHeight}px; grid-template-rows: repeat(11, 1fr);">
                    ${Array.from({ length: 11 })
                        .map((_, i) => `<div class="flex items-center">${10 - i}</div>`)
                        .join('')}
                </div>

                <!-- Chart -->
                <div class="relative flex-1 h-full">

                    <!-- Grid lines -->
                    <div class="absolute left-0 right-0 pointer-events-none"
                         style="height:${maxBarHeight}px;
                                display:grid;
                                grid-template-rows:repeat(10, 1fr);">
                        ${Array.from({ length: 10 })
                            .map(() => `<div class="border-t border-gray-700/40"></div>`)
                            .join('')}
                    </div>

                    <!-- Bars -->
                    <div id="bars-row"
                         class="absolute bottom-0 left-0 right-0 flex items-end gap-4 pb-4 overflow-x-auto"
                         style="height:${maxBarHeight}px;">
                    </div>

                </div>
            </div>
        </div>
    `;

    const barsRow = document.getElementById("bars-row");

    safeHistory.forEach((entry, idx) => {
        const score = entry.overallScore ?? 0;
        const dateLabel = entry.timestamp
            ? new Date(entry.timestamp).toLocaleDateString()
            : '';

        const col = document.createElement('div');
        col.className = 'flex flex-col items-center justify-end min-w-[55px]';

        const bar = document.createElement('div');
        bar.className = 'w-full bg-green-500 rounded-sm';
        bar.style.height = '0px';
        bar.style.transition = 'height 0.7s ease-out';

        const barWrapper = document.createElement('div');
        barWrapper.className = 'w-full bg-gray-900/40 rounded-t-md overflow-hidden flex items-end';
        barWrapper.style.height = maxBarHeight + 'px';

        barWrapper.appendChild(bar);

        const label = document.createElement('div');
        label.className = 'mt-1 text-[10px] text-gray-400 text-center';
        label.textContent = dateLabel;

        col.appendChild(barWrapper);
        col.appendChild(label);
        barsRow.appendChild(col);

        requestAnimationFrame(() => {
            const targetHeight = (score / 10) * maxBarHeight;
            setTimeout(() => {
                bar.style.height = targetHeight + 'px';
            }, 60 + idx * 80);
        });
    });
}


        function renderHistoryList(history, containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;

            if (!history || history.length === 0) {
                container.innerHTML = `<p class="text-gray-400 text-sm">No saved analyses yet.</p>`;
                return;
            }

            const items = [...history].reverse(); // latest first
            let html = '';

            items.forEach((entry, indexFromLatest) => {
                const date = entry.timestamp
                    ? new Date(entry.timestamp).toLocaleString()
                    : '-';
                const stepLabel = `Analysis #${history.length - indexFromLatest}`;

                const muscleLine = MUSCLES
                    .map(m => {
                        const val = entry[m];
                        const nice = (val != null && !isNaN(val)) ? Number(val).toFixed(1) : '-';
                        return `${m.charAt(0).toUpperCase() + m.slice(1)} ${nice}`;
                    })
                    .join(' · ');

                const overallText = (entry.overallScore != null && !isNaN(entry.overallScore))
                    ? Number(entry.overallScore).toFixed(1)
                    : '-';

                html += `
                    <div class="border border-gray-700 rounded-lg p-3 flex flex-col md:flex-row md:items-center md:justify-between gap-2 text-xs md:text-sm">
                        <div>
                            <p class="font-semibold text-gray-200">${stepLabel}</p>
                            <p class="text-gray-400">${date}</p>
                            <p class="text-gray-400 mt-1">
                                Overall score: <span class="text-green-400 font-semibold">${overallText}</span>/10
                            </p>
                        </div>
                        <p class="text-gray-400">${muscleLine}</p>
                    </div>
                `;
            });

            container.innerHTML = html;
        }
    </script>

    <!-- Logout Confirmation Modal (shared pattern) -->
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
            // If you no longer use localStorage for stats, this is optional
            localStorage.removeItem('physique_app_state');
            localStorage.removeItem('physique_current_report');
            localStorage.removeItem('physique_plans');
            localStorage.removeItem('physique_history');
            localStorage.removeItem('physique_report_history');
            localStorage.removeItem('physique_preferences');
            localStorage.removeItem('physique_user_profile');

            window.location.href = 'logout.php';
        }
    </script>
</body>
</html>
