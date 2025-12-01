<?php
session_start();

// simple auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

require_once 'db.php';

$userId = (int)$_SESSION['user_id'];

// Fetch all analyses for this user (newest first)
$stmt = $pdo->prepare("
    SELECT id, created_at, analysis_json
    FROM analyses
    WHERE user_id = :uid
    ORDER BY created_at DESC
");
$stmt->execute([':uid' => $userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Physique Check - History</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-black text-white" data-page="history">
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
            <a href="home.php" class="w-full flex items-center p-3 rounded-lg text-gray-300 hover:bg-gray-700/50 hover:text-white">
                <svg class="w-6 h-6 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h7.5" />
                </svg>
                <span class="ml-4 sidebar-text whitespace-nowrap">Home</span>
            </a>
            <a href="stats.php" class="w-full flex items-center p-3 rounded-lg text-gray-300 hover:bg-gray-700/50 hover:text-white">
                <svg class="w-6 h-6 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                </svg>
                <span class="ml-4 sidebar-text whitespace-nowrap">Your Stats</span>
            </a>
            <a href="history.php" class="w-full flex items-center p-3 rounded-lg bg-green-500/20 text-green-400">
                <svg class="w-6 h-6 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="ml-4 sidebar-text whitespace-nowrap">History</span>
            </a>
            <a href="profile.php" class="w-full flex items-center p-3 rounded-lg text-gray-300 hover:bg-gray-700/50 hover:text-white">
                <svg class="w-6 h-6 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <span class="ml-4 sidebar-text whitespace-nowrap">Profile</span>
            </a>
        </nav>
        <div class="px-2 py-4 border-t border-gray-800 space-y-2">
            <a href="#" onclick="openLogoutModal(); return false;" class="w-full flex items-center p-3 rounded-lg text-gray-300 hover:bg-red-500/50 hover:text-white">
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
            <a href="stats.php" class="block p-3 rounded text-gray-300">Stats</a>
            <a href="history.php" class="block p-3 rounded bg-gray-800 text-green-400">History</a>
            <a href="profile.php" class="block p-3 rounded text-gray-300">Profile</a>
            <a href="#" onclick="openLogoutModal(); return false;" class="block p-3 rounded text-red-400">Logout</a>
        </nav>
    </aside>

    <!-- MAIN -->
    <div class="main-content flex-1 flex flex-col md:ml-64 transition-all duration-300">
        <!-- Mobile Header -->
        <div class="md:hidden sticky top-0 flex items-center p-4 bg-gray-900/80 backdrop-blur-sm border-b border-gray-800 z-30">
            <button class="sidebar-toggle">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>
            <span class="ml-4 font-bold text-xl">History</span>
        </div>

        <div class="p-6 container mx-auto max-w-6xl pb-24">
            <h1 class="text-3xl font-bold mb-2">Analysis History</h1>
            <p class="text-gray-400 mb-6 text-sm">
                Tap a previous analysis to reopen the full report (physique score, workout plan, and meal guide).
            </p>

            <div id="history-list" class="space-y-4">
                <?php if (empty($rows)): ?>
                    <div class="text-center text-gray-400 py-10">
                        No analyses yet. Do a scan on the Home page first.
                    </div>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $analysis = json_decode($row['analysis_json'], true);
                            $overall  = $analysis['physiqueRating']['overallScore'] ?? '-';
                            $created  = $row['created_at'];
                            $dateLabel = date('M d, Y H:i', strtotime($created));
                        ?>
                        <a href="home.php?analysis_id=<?php echo (int)$row['id']; ?>"
                           class="w-full bg-gray-900/70 border border-gray-800 rounded-2xl px-6 py-4 flex items-center gap-4 hover:border-green-500/70 hover:bg-gray-900 transition-colors text-left">
                            <div class="w-20 h-20 rounded-lg bg-gray-800 flex items-center justify-center">
                                <!-- AI-style icon, recolored to green -->
                                <svg xmlns="http://www.w3.org/2000/svg"
                                     viewBox="0 0 48 48"
                                     class="w-10 h-10">
                                    <path fill="#22c55e" d="M23.426,31.911l-1.719,3.936c-0.661,1.513-2.754,1.513-3.415,0l-1.719-3.936	c-1.529-3.503-4.282-6.291-7.716-7.815l-4.73-2.1c-1.504-0.668-1.504-2.855,0-3.523l4.583-2.034	c3.522-1.563,6.324-4.455,7.827-8.077l1.741-4.195c0.646-1.557,2.797-1.557,3.443,0l1.741,4.195	c1.503,3.622,4.305,6.514,7.827,8.077l4.583,2.034c1.504,0.668,1.504,2.855,0,3.523l-4.73,2.1	C27.708,25.62,24.955,28.409,23.426,31.911z"></path>
                                    <path fill="#16a34a" d="M38.423,43.248l-0.493,1.131c-0.361,0.828-1.507,0.828-1.868,0l-0.493-1.131	c-0.879-2.016-2.464-3.621-4.44-4.5l-1.52-0.675c-0.822-0.365-0.822-1.56,0-1.925l1.435-0.638c2.027-0.901,3.64-2.565,4.504-4.65	l0.507-1.222c0.353-0.852,1.531-0.852,1.884,0l0.507,1.222c0.864,2.085,2.477,3.749,4.504,4.65l1.435,0.638	c0.822,0.365,0.822,1.56,0,1.925l-1.52,0.675C40.887,39.627,39.303,41.232,38.423,43.248z"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <p class="font-semibold text-lg">
                                    <?php echo htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <div class="mt-2 inline-flex items-center px-3 py-1 rounded-full bg-green-500/10 text-green-400 text-sm font-bold">
                                    Score: <?php echo htmlspecialchars((string)$overall, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Bottom Navigation -->
<nav class="fixed bottom-0 left-0 right-0 bg-gray-900 border-t border-gray-800 flex justify-around items-center h-16 z-50 md:hidden">
    <a href="home.php" class="flex flex-col items-center justify-center w-full h-full text-gray-400">
        <svg class="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h7.5" />
        </svg>
        <span class="text-[10px] font-medium">Home</span>
    </a>
    <a href="stats.php" class="flex flex-col items-center justify-center w-full h-full text-gray-400">
        <svg class="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
        </svg>
        <span class="text-[10px] font-medium">Stats</span>
    </a>
    <a href="history.php" class="flex flex-col items-center justify-center w-full h-full text-green-400">
        <svg class="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span class="text-[10px] font-medium">History</span>
    </a>
    <a href="profile.php" class="flex flex-col items-center justify-center w-full h-full text-gray-400">
        <svg class="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
        <span class="text-[10px] font-medium">Profile</span>
    </a>
</nav>

<script>
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

        document.querySelectorAll('.sidebar-toggle').forEach(btn => {
            btn.addEventListener('click', toggleSidebar);
        });
        if (overlay) overlay.addEventListener('click', toggleSidebar);
    }

    document.addEventListener('DOMContentLoaded', () => {
        initSidebar();
    });
</script>

<!-- Logout Confirmation Modal -->
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
        // Just end the PHP session via logout.php
        window.location.href = 'logout.php';
    }
</script>
</body>
</html>
