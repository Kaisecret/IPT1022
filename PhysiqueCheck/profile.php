<?php
session_start();

// simple auth check: only allow logged-in users
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

require_once 'db.php';

$userId = (int)$_SESSION['user_id'];

$successMsg = '';
$errorMsg   = '';

// Fetch current user + prefs from DB
try {
    // Base user info
    $stmt = $pdo->prepare("
        SELECT full_name, username, age, gender, goal, email, profile_picture
        FROM users
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // If somehow session is invalid
        die('User not found. Please log in again.');
    }

    // Split full_name into first / last
    $fullName = $user['full_name'] ?? '';
    $parts = preg_split('/\s+/', trim($fullName), 2);
    $currentFirstName = $parts[0] ?? '';
    $currentLastName  = $parts[1] ?? '';

    $currentUsername     = $user['username'] ?? '';
    $currentEmail        = $user['email'] ?? '';
    $currentAge          = $user['age'] ?? '';
    $currentSex          = $user['gender'] ?? 'Male';      // ENUM('Male','Female','Other')
    $currentGoal         = $user['goal'] ?? 'muscle gain'; // ENUM('muscle gain','fat loss')
    $currentProfilePic   = $user['profile_picture'] ?? '';

    // Preferences (experience, equipment, time_per_workout)
    $prefStmt = $pdo->prepare("
        SELECT experience, equipment, time_per_workout
        FROM user_preferences
        WHERE user_id = :uid
        LIMIT 1
    ");
    $prefStmt->execute([':uid' => $userId]);
    $prefs = $prefStmt->fetch(PDO::FETCH_ASSOC);

    if (!$prefs) {
        $prefs = [
            'experience'      => 'beginner',
            'equipment'       => 'gym',
            'time_per_workout'=> '45-60 min',
        ];
    }

    $currentExperience = $prefs['experience'];
    $currentEquipment  = $prefs['equipment'];
    $currentTime       = $prefs['time_per_workout'];

} catch (Exception $e) {
    die('Error loading profile: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

// Handle POST (Save Changes)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName  = trim($_POST['prof-fname'] ?? '');
    $lastName   = trim($_POST['prof-lname'] ?? '');
    $username   = trim($_POST['prof-username'] ?? '');
    $email      = trim($_POST['prof-email'] ?? '');
    $age        = (int)($_POST['prof-age'] ?? 0);
    $sex        = $_POST['prof-sex'] ?? 'Male';
    $goal       = $_POST['prof-goal'] ?? 'muscle gain'; // must be 'muscle gain' or 'fat loss' to fit your ENUM
    $experience = $_POST['prof-experience'] ?? 'beginner';
    $equipment  = $_POST['prof-equipment'] ?? 'gym';
    $time       = $_POST['prof-time'] ?? '45-60 min';

    $fullNameNew = trim($firstName . ' ' . $lastName);
    if ($fullNameNew === '') {
        $fullNameNew = 'User';
    }

    // --- handle profile picture upload (store path in DB, file in uploads/profile/) ---
    $newProfilePicPath = $currentProfilePic;

    if (isset($_FILES['profile-picture']) && $_FILES['profile-picture']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/profile/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $tmpName      = $_FILES['profile-picture']['tmp_name'];
        $originalName = $_FILES['profile-picture']['name'];
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed      = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (in_array($ext, $allowed, true)) {
            $fileName    = 'user_' . $userId . '_' . time() . '.' . $ext;
            $destPathAbs = $uploadDir . $fileName;

            if (move_uploaded_file($tmpName, $destPathAbs)) {
                // delete old file if exists
                if (!empty($currentProfilePic)) {
                    $oldAbs = __DIR__ . '/' . $currentProfilePic;
                    if (is_file($oldAbs)) {
                        @unlink($oldAbs);
                    }
                }
                // path saved in DB (relative)
                $newProfilePicPath = 'uploads/profile/' . $fileName;
            } else {
                $errorMsg = 'Error saving uploaded profile picture.';
            }
        } else {
            $errorMsg = 'Invalid profile picture type. Please upload JPG, PNG, GIF or WEBP.';
        }
    }

    try {
        $pdo->beginTransaction();

        // Update users table (including profile_picture)
        $updUser = $pdo->prepare("
            UPDATE users
            SET full_name       = :full_name,
                username        = :username,
                email           = :email,
                age             = :age,
                gender          = :gender,
                goal            = :goal,
                profile_picture = :profile_picture
            WHERE id = :id
        ");
        $updUser->execute([
            ':full_name'       => $fullNameNew,
            ':username'        => $username,
            ':email'           => $email,
            ':age'             => $age,
            ':gender'          => $sex,
            ':goal'            => $goal,
            ':profile_picture' => $newProfilePicPath,
            ':id'              => $userId,
        ]);

        // Upsert into user_preferences
        $checkPref = $pdo->prepare("SELECT id FROM user_preferences WHERE user_id = :uid LIMIT 1");
        $checkPref->execute([':uid' => $userId]);
        $prefId = $checkPref->fetchColumn();

        if ($prefId) {
            $updPref = $pdo->prepare("
                UPDATE user_preferences
                SET experience       = :experience,
                    equipment        = :equipment,
                    time_per_workout = :time
                WHERE user_id = :uid
            ");
        } else {
            $updPref = $pdo->prepare("
                INSERT INTO user_preferences (user_id, experience, equipment, time_per_workout)
                VALUES (:uid, :experience, :equipment, :time)
            ");
        }

        $updPref->execute([
            ':uid'        => $userId,
            ':experience' => $experience,
            ':equipment'  => $equipment,
            ':time'       => $time,
        ]);

        $pdo->commit();

        // Refresh current data for display
        $currentFirstName   = $firstName;
        $currentLastName    = $lastName;
        $currentUsername    = $username;
        $currentEmail       = $email;
        $currentAge         = $age;
        $currentSex         = $sex;
        $currentGoal        = $goal;
        $currentExperience  = $experience;
        $currentEquipment   = $equipment;
        $currentTime        = $time;
        $currentProfilePic  = $newProfilePicPath;

        // Optionally refresh some session vars
        $_SESSION['username']   = $username;
        $_SESSION['email']      = $email;
        $_SESSION['first_name'] = $firstName;
        $_SESSION['last_name']  = $lastName;

        if (!$errorMsg) {
            $successMsg = 'Profile updated successfully.';
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $errorMsg = 'Error updating profile: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}

// final image path for avatar (placeholder fallback)
$profileImgPath = $currentProfilePic ? $currentProfilePic : 'https://via.placeholder.com/150';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- Added for proper mobile scaling -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Physique Check - Profile</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-black text-white" data-page="profile">

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
            <a href="home.php" class="w-full flex items-center p-3 rounded-lg text-gray-300 hover:bg-gray-700/50 hover:text-white transition-colors group">
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
            <a href="profile.php" class="w-full flex items-center p-3 rounded-lg bg-green-500/20 text-green-400 font-semibold group">
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
            <a href="stats.php" class="block p-3 rounded text-gray-300">Stats</a>
            <a href="history.php" class="block p-3 rounded text-gray-300">History</a>
            <a href="profile.php" class="block p-3 rounded bg-gray-800 text-green-400">Profile</a>
            <a href="#" onclick="openLogoutModal(); return false;" class="block p-3 rounded text-red-400">Logout</a>
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
            <span class="ml-4 font-bold text-xl">Profile</span>
        </div>

        <div class="p-6 container mx-auto max-w-6xl pb-24">
            <h2 class="text-4xl font-bold mb-2">Your Profile</h2>
            <p class="text-gray-400 mb-4">Manage your personal details and fitness preferences.</p>

            <?php if ($successMsg): ?>
                <div class="mb-4 px-4 py-3 rounded-lg bg-green-500/10 border border-green-500 text-green-300 text-sm">
                    <?php echo $successMsg; ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMsg): ?>
                <div class="mb-4 px-4 py-3 rounded-lg bg-red-500/10 border border-red-500 text-red-300 text-sm">
                    <?php echo $errorMsg; ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- Left: Identity Card -->
                <div class="lg:col-span-1">
                    <!-- made sticky only on large screens -->
                    <div class="bg-gray-900/50 p-6 rounded-xl border border-gray-800 text-center lg:sticky lg:top-24">
                        <div class="relative w-32 h-32 mx-auto mb-4 group">
                            <img id="profile-avatar"
                                 src="<?php echo htmlspecialchars($profileImgPath, ENT_QUOTES, 'UTF-8'); ?>"
                                 alt="Profile"
                                 class="w-32 h-32 rounded-full object-cover border-4 border-gray-800 group-hover:border-green-500 transition-colors">
                            <div id="avatar-overlay" class="absolute inset-0 rounded-full bg-black/50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer">
                                <span class="text-white font-bold text-xs uppercase">Change</span>
                            </div>
                            <input type="file" id="avatar-input" name="profile-picture" class="hidden" accept="image/*" form="profile-form">
                        </div>
                        <h3 class="text-2xl font-bold" id="display-name">
                            <?php echo htmlspecialchars(trim($currentFirstName . ' ' . $currentLastName), ENT_QUOTES, 'UTF-8'); ?>
                        </h3>
                        <p class="text-gray-400 text-sm" id="display-username">
                            @<?php echo htmlspecialchars($currentUsername ?: 'username', ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <p class="text-green-400 text-sm mt-2" id="display-goal">
                            <?php echo strtoupper(htmlspecialchars($currentGoal, ENT_QUOTES, 'UTF-8')); ?>
                        </p>
                    </div>
                </div>

                <!-- Right: Forms -->
                <div class="lg:col-span-2 space-y-8">
                    
                    <form method="post" id="profile-form" enctype="multipart/form-data" class="space-y-8">
                        <!-- Personal Info -->
                        <div class="bg-gray-900/50 p-6 rounded-xl border border-gray-800">
                            <h4 class="font-bold text-lg mb-4 flex items-center gap-2">
                                <svg class="w-5 h-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                Personal Information
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">First Name</label>
                                    <input type="text" id="prof-fname" name="prof-fname"
                                           value="<?php echo htmlspecialchars($currentFirstName, ENT_QUOTES, 'UTF-8'); ?>"
                                           class="w-full bg-gray-800 border-gray-700 rounded-md p-2 focus:ring-green-500 focus:border-green-500 text-white">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Last Name</label>
                                    <input type="text" id="prof-lname" name="prof-lname"
                                           value="<?php echo htmlspecialchars($currentLastName, ENT_QUOTES, 'UTF-8'); ?>"
                                           class="w-full bg-gray-800 border-gray-700 rounded-md p-2 focus:ring-green-500 focus:border-green-500 text-white">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Username</label>
                                    <input type="text" id="prof-username" name="prof-username"
                                           value="<?php echo htmlspecialchars($currentUsername, ENT_QUOTES, 'UTF-8'); ?>"
                                           class="w-full bg-gray-800 border-gray-700 rounded-md p-2 focus:ring-green-500 focus:border-green-500 text-white">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Email</label>
                                    <input type="email" id="prof-email" name="prof-email"
                                           value="<?php echo htmlspecialchars($currentEmail, ENT_QUOTES, 'UTF-8'); ?>"
                                           class="w-full bg-gray-800 border-gray-700 rounded-md p-2 focus:ring-green-500 focus:border-green-500 text-white">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Age</label>
                                    <input type="number" id="prof-age" name="prof-age"
                                           value="<?php echo htmlspecialchars((string)$currentAge, ENT_QUOTES, 'UTF-8'); ?>"
                                           class="w-full bg-gray-800 border-gray-700 rounded-md p-2 focus:ring-green-500 focus:border-green-500 text-white">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Sex</label>
                                    <select id="prof-sex" name="prof-sex"
                                            class="w-full bg-gray-800 border-gray-700 rounded-md p-2 focus:ring-green-500 focus:border-green-500 text-white">
                                        <option value="Male"   <?php echo ($currentSex === 'Male')   ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($currentSex === 'Female') ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other"  <?php echo ($currentSex === 'Other')  ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Fitness Prefs -->
                        <div class="bg-gray-900/50 p-6 rounded-xl border border-gray-800">
                            <h4 class="font-bold text-lg mb-4 flex items-center gap-2">
                                <svg class="w-5 h-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                                Fitness Preferences
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Primary Goal</label>
                                    <select id="prof-goal" name="prof-goal"
                                            class="w-full bg-gray-800 border-gray-700 rounded-md p-2">
                                        <option value="muscle gain" <?php echo ($currentGoal === 'muscle gain') ? 'selected' : ''; ?>>Muscle Gain</option>
                                        <option value="fat loss"    <?php echo ($currentGoal === 'fat loss')    ? 'selected' : ''; ?>>Fat Loss</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Experience</label>
                                    <select id="prof-experience" name="prof-experience"
                                            class="w-full bg-gray-800 border-gray-700 rounded-md p-2">
                                        <option value="beginner"     <?php echo ($currentExperience === 'beginner')     ? 'selected' : ''; ?>>Beginner</option>
                                        <option value="intermediate" <?php echo ($currentExperience === 'intermediate') ? 'selected' : ''; ?>>Intermediate</option>
                                        <option value="advanced"     <?php echo ($currentExperience === 'advanced')     ? 'selected' : ''; ?>>Advanced</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Equipment</label>
                                    <select id="prof-equipment" name="prof-equipment"
                                            class="w-full bg-gray-800 border-gray-700 rounded-md p-2">
                                        <option value="gym"              <?php echo ($currentEquipment === 'gym')              ? 'selected' : ''; ?>>Gym</option>
                                        <option value="home"             <?php echo ($currentEquipment === 'home')             ? 'selected' : ''; ?>>Home</option>
                                        <option value="minimal equipment"<?php echo ($currentEquipment === 'minimal equipment')? 'selected' : ''; ?>>Minimal Equipment</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Time / Workout</label>
                                    <select id="prof-time" name="prof-time"
                                            class="w-full bg-gray-800 border-gray-700 rounded-md p-2">
                                        <option value="20-30 min" <?php echo ($currentTime === '20-30 min') ? 'selected' : ''; ?>>20-30 min</option>
                                        <option value="30-45 min" <?php echo ($currentTime === '30-45 min') ? 'selected' : ''; ?>>30-45 min</option>
                                        <option value="45-60 min" <?php echo ($currentTime === '45-60 min') ? 'selected' : ''; ?>>45-60 min</option>
                                        <option value="60+ min"   <?php echo ($currentTime === '60+ min')   ? 'selected' : ''; ?>>60+ min</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit"
                                    class="bg-green-500 text-black font-bold py-3 px-8 rounded-lg hover:bg-green-400 transform transition-transform hover:scale-105 shadow-lg shadow-green-500/20">
                                Save Changes
                            </button>
                        </div>
                    </form>

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
    <a href="stats.php" class="flex flex-col items-center justify-center w-full h-full text-gray-400 hover:text-white transition-colors">
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
    <a href="profile.php" class="flex flex-col items-center justify-center w-full h-full text-green-400">
        <svg class="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
        <span class="text-[10px] font-medium">Profile</span>
    </a>
</nav>

<script>
    // Sidebar logic
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

        document.querySelectorAll('.sidebar-toggle').forEach(btn =>
            btn.addEventListener('click', toggleSidebar)
        );
        if (overlay) {
            overlay.addEventListener('click', toggleSidebar);
        }
    }

    // Avatar preview (front-end only, not saved in DB yet)
    function initAvatar() {
        const avatarImg = document.getElementById('profile-avatar');
        const avatarInput = document.getElementById('avatar-input');
        const avatarOverlay = document.getElementById('avatar-overlay');

        if (!avatarImg || !avatarInput || !avatarOverlay) return;

        avatarOverlay.addEventListener('click', () => avatarInput.click());
        avatarInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(ev) {
                    avatarImg.src = ev.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initSidebar();
        initAvatar();
    });

    // Logout modal
    function openLogoutModal() {
        const modal = document.getElementById('logout-modal');
        if (modal) modal.classList.remove('hidden');
    }

    function closeLogoutModal() {
        const modal = document.getElementById('logout-modal');
        if (modal) modal.classList.add('hidden');
    }

    function confirmLogout() {
        // PHP logout will destroy the session
        window.location.href = 'logout.php';
    }
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

</body>
</html>
