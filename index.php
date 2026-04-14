<?php
ob_start();
session_start();

// Configuration
define("MAIN_ADMIN_PASSWORD", "1234");
define("MAIN_ADMIN_UUID", "admin_12341234");
define("DB_FILE", "database.txt");
define("SERIES_NAME", "Maple");
define("TAG_NAME", "Maple");
define("ADMIN_DB_FILE", "admins.txt");

// Helper for secure comparison
function secure_compare($a, $b) {
    return $a === $b;
}

// Check if main admin password is provided via GET or POST for main subscription
$provided_pass = $_GET['pass'] ?? $_POST['pass'] ?? '';
$reqOwner = $_GET['owner'] ?? '';
$is_authenticated_sub = false;

if (empty($reqOwner) || $reqOwner === MAIN_ADMIN_UUID) {
    // Main Admin Auth
    if (secure_compare($provided_pass, MAIN_ADMIN_PASSWORD)) {
        $is_authenticated_sub = true;
    }
} else {
    // Sub Admin Auth
    $admins = get_admin_users();
    foreach ($admins as $admin) {
        if ($admin['uuid'] === $reqOwner && secure_compare($provided_pass, $admin['password'])) {
            $is_authenticated_sub = true;
            break;
        }
    }
}

// Initialize main admin if no admins exist
if (!file_exists(ADMIN_DB_FILE) || filesize(ADMIN_DB_FILE) === 0) {
    add_admin_user('main_admin', MAIN_ADMIN_PASSWORD, MAIN_ADMIN_UUID);
}

// Server-side fetching logic: acts exactly like a VPN client.
// Fast timeout (3s) + cURL integration for maximum reliability against Firewalls/WAFs
function get_data_from_url($url) {
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) return '?';
    
    $response = false;
    $userAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36";
    
    // 1. Try cURL first (Most robust, handles SSL and redirects best)
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $response = curl_exec($ch);
        curl_close($ch);
    } 
    
    // 2. Fallback to file_get_contents if cURL is disabled or failed
    if ($response === false || empty($response)) {
        $opts = [
            "http" => ["method" => "GET", "header" => "User-Agent: $userAgent\r\n", "timeout" => 3],
            "ssl" => ["verify_peer" => false, "verify_peer_name" => false]
        ];
        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);
    }
    
    if (empty($response)) return "?";

    // Decode Base64 if the panel returned raw subscription format
    if (strpos($response, '://') === false) {
        $decoded = base64_decode(trim($response), true);
        if ($decoded !== false && strpos($decoded, '://') !== false) {
            $response = $decoded;
        }
    }

    $response = urldecode($response);
    
    // Robust Regex to find patterns like "-1.75GB" or "838.68MB", ignoring prepended non-digit characters.
    if (preg_match_all('/(\d+(?:\.\d+)?)\s*(GB|MB)/i', $response, $matches)) {
        $lastIndex = count($matches[1]) - 1;
        $val = floatval($matches[1][$lastIndex]);
        $unit = strtoupper($matches[2][$lastIndex]);

        if ($unit === 'MB') {
            $val = $val / 1024;
        }
        return number_format($val, 2, '.', '');
    }

    return "?";
}

/* ================= 0.5 BUILT-IN AJAX PROXY ================= */
// This allows the frontend to fetch data without CORS/CSP errors
// Strict anti-caching headers added to prevent "incognito mode" bugs
if (isset($_GET['ajax_fetch'])) {
    ob_clean();
    header('Content-Type: text/plain');
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    echo get_data_from_url($_GET['ajax_fetch']);
    exit;
}

/* ================= 0. DATABASE HELPER FUNCTIONS (GROUP AWARE) ================= */

function get_database_groups() {
    $groups =[];
    $lines = file_exists(DB_FILE) ? file(DB_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) :[];
    
    $currentName = '';
    $currentUUID = '';
    $currentPass = '';
    $currentNote = '';
    $currentInfo = ''; 
    $currentType = 'auto'; 
    $currentExclude = false;
    $currentFree = false; 
    $currentHideStats = false;
    $currentOwner = '';
    $currentExpireDate = 0;
    $currentNoTimeLimit = false;
    $currentQuota = 0;
    $currentConfigs =[];
    $needsSave = false;

    $finalizeGroup = function() use (&$groups, &$currentName, &$currentUUID, &$currentPass, &$currentNote, &$currentInfo, &$currentType, &$currentExclude, &$currentFree, &$currentHideStats, &$currentOwner, &$currentExpireDate, &$currentNoTimeLimit, &$currentQuota, &$currentConfigs, &$needsSave) {
        if (!empty($currentConfigs)) {
            if (empty($currentUUID)) {
                $currentUUID = uniqid(); 
                $needsSave = true;
            }
            if (empty($currentPass) && !$currentFree) { 
                $currentPass = '1234'; 
                $needsSave = true;
            }
            $groups[] =[
                'uuid' => $currentUUID,
                'name' => $currentName ?: 'Config ' . (count($groups) + 1),
                'pass' => $currentPass,
                'note' => $currentNote,
                'info' => $currentInfo,
                'type' => $currentType,
                'exclude' => $currentExclude,
                'free' => $currentFree,
                'hide_stats' => $currentHideStats,
                'owner' => $currentOwner, 
                'expire_date' => $currentExpireDate,
                'no_time_limit' => $currentNoTimeLimit,
                'quota' => $currentQuota,
                'configs' => $currentConfigs
            ];
        }
        $currentName = '';
        $currentUUID = '';
        $currentPass = '';
        $currentNote = '';
        $currentInfo = '';
        $currentType = 'auto';
        $currentExclude = false;
        $currentFree = false; 
        $currentHideStats = false;
        $currentOwner = ''; 
        $currentExpireDate = 0;
        $currentNoTimeLimit = false;
        $currentQuota = 0;
        $currentConfigs =[];
    };

    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#NAME: ') === 0) {
            $finalizeGroup();
            $currentName = trim(substr($line, 7));
        } elseif (strpos($line, '#UUID: ') === 0) {
            $currentUUID = trim(substr($line, 7));
        } elseif (strpos($line, '#PASS: ') === 0) {
            $currentPass = trim(substr($line, 7));
        } elseif (strpos($line, '#NOTE: ') === 0) {
            $currentNote = trim(substr($line, 7));
        } elseif (strpos($line, '#INFO: ') === 0) {
            $currentInfo = trim(substr($line, 7));
        } elseif (strpos($line, '#TYPE: ') === 0) {
            $currentType = trim(substr($line, 7));
        } elseif (strpos($line, '#EXCLUDE: ') === 0) {
            $currentExclude = trim(substr($line, 10)) === 'true';
        } elseif (strpos($line, '#FREE: ') === 0) { 
            $currentFree = trim(substr($line, 7)) === 'true';
        } elseif (strpos($line, '#HIDESTATS: ') === 0) { 
            $currentHideStats = trim(substr($line, 12)) === 'true';
        } elseif (strpos($line, '#OWNER: ') === 0) { 
            $currentOwner = trim(substr($line, 8));
        } elseif (strpos($line, '#EXPIRE: ') === 0) { 
            $currentExpireDate = (int)trim(substr($line, 9));
        } elseif (strpos($line, '#NOTIME: ') === 0) { 
            $currentNoTimeLimit = trim(substr($line, 9)) === 'true';
        } elseif (strpos($line, '#QUOTA: ') === 0) { 
            $currentQuota = (float)trim(substr($line, 8));
        } elseif (preg_match('/^[a-z0-9]+\:\/\//i', $line)) {
            $currentConfigs[] = $line;
        }
    }
    $finalizeGroup();

    if ($needsSave) {
        save_database_groups($groups);
    }

    return $groups;
}

function save_database_groups($groups) {
    $output =[];
    foreach ($groups as $group) {
        if (!empty($group['name'])) $output[] = "#NAME: " . $group['name'];
        if (!empty($group['uuid'])) $output[] = "#UUID: " . $group['uuid'];
        if (!empty($group['pass'])) $output[] = "#PASS: " . $group['pass'];
        if (!empty($group['note'])) $output[] = "#NOTE: " . $group['note'];
        if (!empty($group['info'])) $output[] = "#INFO: " . $group['info'];
        if (!empty($group['type'])) $output[] = "#TYPE: " . $group['type']; 
        $output[] = "#EXCLUDE: " . ($group['exclude'] ? 'true' : 'false');
        $output[] = "#FREE: " . ($group['free'] ? 'true' : 'false');
        $output[] = "#HIDESTATS: " . (!empty($group['hide_stats']) ? 'true' : 'false');
        if (!empty($group['owner'])) $output[] = "#OWNER: " . $group['owner'];
        $output[] = "#EXPIRE: " . ($group['expire_date'] ?? 0);
        $output[] = "#NOTIME: " . (!empty($group['no_time_limit']) ? 'true' : 'false');
        $output[] = "#QUOTA: " . ($group['quota'] ?? 0);
        foreach ($group['configs'] as $cfg) {
            $output[] = $cfg;
        }
    }
    file_put_contents(DB_FILE, implode(PHP_EOL, $output) . PHP_EOL, LOCK_EX);
}

function get_admin_users() {
    $admins =[];
    $lines = file_exists(ADMIN_DB_FILE) ? file(ADMIN_DB_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) :[];

    $currentUUID = '';
    $currentUsername = '';
    $currentPassword = '';

    $finalizeAdmin = function() use (&$admins, &$currentUUID, &$currentUsername, &$currentPassword) {
        if (!empty($currentUUID) && !empty($currentUsername) && !empty($currentPassword)) {
            $admins[] =[
                'uuid' => $currentUUID,
                'username' => $currentUsername,
                'password' => $currentPassword
            ];
        }
        $currentUUID = '';
        $currentUsername = '';
        $currentPassword = '';
    };

    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#UUID: ') === 0) {
            $finalizeAdmin();
            $currentUUID = trim(substr($line, 7));
        } elseif (strpos($line, '#USERNAME: ') === 0) {
            $currentUsername = trim(substr($line, 11));
        } elseif (strpos($line, '#PASSWORD: ') === 0) {
            $currentPassword = trim(substr($line, 11));
        }
    }
    $finalizeAdmin();
    
    foreach ($admins as &$admin) {
        if ($admin['username'] === 'main_admin') {
            $admin['uuid'] = MAIN_ADMIN_UUID;
        }
    }
    unset($admin); 

    return $admins;
}

function save_admin_users($admins) {
    $output =[];
    foreach ($admins as $admin) {
        $output[] = "#UUID: " . $admin['uuid'];
        $output[] = "#USERNAME: " . $admin['username'];
        $output[] = "#PASSWORD: " . $admin['password'];
    }
    file_put_contents(ADMIN_DB_FILE, implode(PHP_EOL, $output) . PHP_EOL, LOCK_EX);
}

function add_admin_user($username, $password, $uuid = null) {
    $admins = get_admin_users();
    $admins[] =[
        'uuid' => $uuid ?? uniqid('admin_'),
        'username' => $username,
        'password' => $password
    ];
    save_admin_users($admins);
}

function authenticate_admin($username, $password) {
    $admins = get_admin_users();
    foreach ($admins as $admin) {
        if (secure_compare($admin['username'], $username) && secure_compare($admin['password'], $password)) {
            return $admin;
        }
    }
    return false;
}

/* ================= 1. FREE CONFIGS MANAGEMENT ENDPOINT ================= */
if (isset($_GET['free_configs'])) {
    $groups = get_database_groups();
    $freeGroups = array_filter($groups, function($group) { return $group['free']; });

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_free_config'])) {
        $deleteUUID = $_POST['delete_free_config_uuid'];
        $groups = array_filter($groups, function($group) use ($deleteUUID) {
            return $group['uuid'] !== $deleteUUID;
        });
        save_database_groups($groups);
        header("Location: ?free_configs=1");
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_free_config']) && trim($_POST['config']) !== '') {
        $rawConfig = trim($_POST['config']);
        $lines = preg_split("/\r\n|\n|\r/", $rawConfig);
        $newConfigs =[];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^[a-z0-9]+\:\/\//i', $line)) $newConfigs[] = $line;
        }

        if (!empty($newConfigs)) {
            $groups[] =[
                'uuid' => uniqid(),
                'name' => trim($_POST['config_name']) ?: 'Free Config ' . (count($freeGroups) + 1),
                'pass' => '',
                'note' => trim($_POST['config_note']),
                'info' => trim($_POST['config_info']),
                'type' => 'auto',
                'exclude' => false,
                'free' => true,
                'hide_stats' => false,
                'owner' => '',
                'expire_date' => 0,
                'no_time_limit' => true,
                'quota' => 0,
                'configs' => $newConfigs
            ];
            save_database_groups($groups);
        }
        header("Location: ?free_configs=1");
        exit;
    }

    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Free Configs - <?= SERIES_NAME ?></title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0;}
        body{
            min-height:100vh;
            background:linear-gradient(135deg, #0c0c1a 0%, #1a0d2e 50%, #0f172a 100%);
            color:#e2e8f0;font-family:'Segoe UI', -apple-system, sans-serif;
            padding:20px;position:relative;overflow-x:hidden;
        }
        body::before{
            content:'';position:fixed;top:0;left:0;width:100%;height:100%;
            background:radial-gradient(circle at 20% 80%, rgba(120,119,198,0.2) 0%, transparent 50%),
                            radial-gradient(circle at 80% 20%, rgba(34,197,94,0.15) 0%, transparent 50%);
            pointer-events:none;z-index:-1;
        }
        .container{width:100%; max-width:1000px; margin:auto;}
        h1{
            text-align:center;background:linear-gradient(135deg, #22c55e, #4ade80);
            -webkit-background-clip:text;-webkit-text-fill-color:transparent;
            font-size:28px;font-weight:700;margin-bottom:30px;
        }
        .card{
            background:linear-gradient(145deg, rgba(15,23,42,0.95), rgba(30,41,59,0.75));
            backdrop-filter:blur(25px);border:1px solid rgba(34,197,94,0.2);
            border-radius:24px;padding:28px;margin-bottom:24px;
            box-shadow:0 25px 50px rgba(0,0,0,0.4);
        }
        .config-item{
            display:flex;align-items:center;gap:15px;margin-bottom:15px;
            background:rgba(0,0,0,0.3);padding:10px 15px;border-radius:12px;
            border:1px solid rgba(255,255,255,0.1);
        }
        .config-item:last-child{margin-bottom:0;}
        .config-name{flex-grow:1;font-size:15px;color:#4ade80;font-weight:bold; min-width:0; word-break:break-all;}
        .config-delete-btn{
            background:#ef4444;color:white;border:none;width:36px;height:36px;border-radius:8px;
            cursor:pointer;font-size:16px;font-weight:bold;transition:0.3s;
            display:flex; align-items:center; justify-content:center; flex-shrink:0;
        }
        .config-delete-btn:hover{filter:brightness(1.2);}
input, textarea{
width:100%;background:rgba(4,8,20,0.6);border:1px solid rgba(59,130,246,0.3);
border-radius:12px;padding:12px 16px;color:white;font-size:14px;margin-bottom:10px;
text-align:left;outline:none;transition:0.3s;
}
input:focus, textarea:focus{border-color:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,0.15);}
button[type="submit"]{
width:100%;padding:14px;border:none;border-radius:12px;
background:linear-gradient(135deg, #22c55e, #16a34a);color:white;
font-weight:bold;font-size:15px;cursor:pointer;
transition:0.3s;margin-top:10px;
}
button[type="submit"]:hover{transform:translateY(-2px);box-shadow:0 15px 40px rgba(34,197,94,0.4);}
.form-section-title{font-size:18px;color:#3b82f6;margin-bottom:15px;}
</style>
</head>
<body>
    <div class="container">
        <h1>🎁 Free Configs</h1>

<div class="card">
        <h3 class="form-section-title">Current Free Configs</h3>
        <?php if (empty($freeGroups)): ?>
            <p style="color:#cbd5e1;">No free configs available yet.</p>
        <?php else: ?>
            <?php foreach ($freeGroups as $group): ?>
                <div class="config-item">
                    <span class="config-name">🏷️ <?= htmlspecialchars($group['name']) ?></span>
                    <form method="post" style="margin:0; display:flex;">
                        <input type="hidden" name="delete_free_config_uuid" value="<?= htmlspecialchars($group['uuid']) ?>">
                        <button type="submit" name="delete_free_config" class="config-delete-btn" title="Delete">🗑️</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<div class="card" style="margin-top:30px;">
    <h3 class="form-section-title">➕ Add New Free Config</h3>
    <form method="post">
        <input type="text" name="config_name" placeholder="Config Name (Optional)">
        <input type="text" name="config_info" placeholder="Stats Info URL (Marzban/X-UI) (Optional)">
        <textarea name="config" rows="5" placeholder="Paste multiple vmess:// vless:// configs here, one per line..." required></textarea>
        <button type="submit" name="add_free_config">Add Free Config</button>
    </form>
</div>
</div>
</body>
</html>
<?php
exit;
}

/* ================= 1. PUBLIC SHARE ENDPOINT ================= */
if (isset($_GET['share'])) {
    $reqUUID = $_GET['share'];
    $groups = get_database_groups();
    $targetGroup = null;
    $targetIndex = -1;
    foreach ($groups as $idx => $group) {
        if ($group['uuid'] === $reqUUID) {
            $targetGroup = $group;
            $targetIndex = $idx;
            break;
        }
    }

    if (!$targetGroup) {
        header("HTTP/1.0 404 Not Found");
        die("❌ Config not found.");
    }

    if ((!empty($targetGroup['pass']) && isset($_GET['pass']) && secure_compare($_GET['pass'], $targetGroup['pass'])) || ($targetGroup['free'] && isset($_GET['sub']) && $_GET['sub'] === '1')) {
        // Output base64 directly if sub=1 is presented for configs
        if (isset($_GET['sub']) && $_GET['sub'] === '1') {
            error_reporting(0); // Suppress any warnings from breaking Base64 purity
            $exportConfigs = $targetGroup['configs'];

            if (empty($targetGroup['hide_stats'])) {
                header("profile-title: " . SERIES_NAME . " " . $targetGroup['name']);
                $headerParts = ["upload=0"];
                if (empty($targetGroup['info'])) {
                    // No info URL -> Treat as 0 usage and empty total
                    $headerParts[] = "download=0";
                    $headerParts[] = "total=0";
                    $left_gb_raw = '?';
                } else {
                    $left_gb_raw = get_data_from_url($targetGroup['info']);
                    $totalBytes = ($targetGroup['quota'] ?? 0) * 1073741824;
                    $usedBytes = 0;
                    
                    if ($left_gb_raw !== '?' && $totalBytes > 0) {
                        $leftBytes = (float)$left_gb_raw * 1073741824;
                        $usedBytes = $totalBytes - $leftBytes;
                        if ($usedBytes < 0) $usedBytes = 0;
                    }
                    $headerParts[] = "download=" . round($usedBytes);
                    $headerParts[] = "total=" . round($totalBytes);
                }

                // === Dummy Config Injection for Data & Time Readout ===
                $parts = [SERIES_NAME];
                if (!empty($targetGroup['info']) && $left_gb_raw !== '?') {
                    $parts[] = "{$left_gb_raw}G";
                }

                if (empty($targetGroup['no_time_limit'])) {
                    $diff = $targetGroup['expire_date'] - time();
                    $days = floor($diff / 86400);
                    $parts[] = $days . 'D';
                }

                $dummyName = implode(" ", $parts);
                // Valid standard format to ensure Hiddify doesn't ignore the rest of the configs
                $dummyConfig = "vless://00000000-0000-0000-0000-000000000000@1.1.1.1:80?encryption=none&security=none&type=tcp#" . rawurlencode($dummyName);

                array_unshift($exportConfigs, $dummyConfig);

                // Set expiry safely beyond 10 years if no limit
                if (!empty($targetGroup['no_time_limit'])) {
                    $headerParts[] = "expire=" . (time() + 315360000);
                } else {
                    $headerParts[] = "expire=" . ($targetGroup['expire_date'] > 0 ? $targetGroup['expire_date'] : (time() + 315360000));
                }

                header("Subscription-Userinfo: " . implode("; ", $headerParts));
            }
            // If hide_stats is true, we intentionally DO NOT set 'Subscription-Userinfo' or 'profile-title'
            // so that Hiddify imports it strictly as raw configs without erroring.

            // Clean configs to ensure Hiddify doesn't error out on \r\n or empty strings
            $cleanExport =[];
            foreach ($exportConfigs as $c) {
                $c = trim($c);
                if (!empty($c)) {
                    $cleanExport[] = $c;
                }
            }

            ob_clean(); // Force clean buffer exactly before Base64 output
            header("Content-Type: text/plain; charset=utf-8");
            echo base64_encode(implode("\n", $cleanExport));
            exit;
        } else {
            ob_clean();
            header("Content-Type: text/plain; charset=utf-8");
            echo implode("\n", $targetGroup['configs']);
            exit;
        }
    }

    $sessionKey = 'auth_share_' . $reqUUID;
    $shareError = '';
    $shareSuccess = '';

    if (isset($_POST['share_pass'], $targetGroup['pass'])) {
        if (secure_compare($_POST['share_pass'], $targetGroup['pass'])) {
            $_SESSION[$sessionKey] = true;
            header("Location: ?share=" . $reqUUID . '#' . TAG_NAME);
            exit;
        } else {
            $shareError = "❌ Invalid Password";
        }
    }

    if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true && isset($_POST['update_config_pass'])) {
        $newPass = trim($_POST['new_pass']);
        if (strlen($newPass) > 0) {
            $groups[$targetIndex]['pass'] = $newPass;
            save_database_groups($groups);
            $targetGroup['pass'] = $newPass;
            $shareSuccess = "✅ Password updated successfully!";
        } else {
            $shareError = "❌ Password cannot be empty.";
        }
    }

    if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true && isset($_POST['delete_config_val'])) {
        $valToDelete = trim($_POST['delete_config_val']);
        $newConfigs =[];
        foreach ($targetGroup['configs'] as $cfg) {
            if (trim($cfg) !== $valToDelete) {
                $newConfigs[] = $cfg;
            }
        }
        $groups[$targetIndex]['configs'] = $newConfigs;
        save_database_groups($groups);
        $targetGroup['configs'] = $newConfigs;
    }

    if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true && isset($_POST['new_configs'])) {
        $raw = $_POST['new_configs'];
        $lines = preg_split("/\r\n|\n|\r/", $raw);
        $added = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^[a-z0-9]+\:\/\//i', $line)) {
                $groups[$targetIndex]['configs'][] = $line;
                $targetGroup['configs'][] = $line;
                $added = true;
            }
        }
        if ($added) save_database_groups($groups);
    }

    $isUnlocked = isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true;
    if ($targetGroup['free']) {
        $isUnlocked = true;
    }

    // Pre-calculate Time String for User Page View
    $isExpired = false;
    $timeString = "−−";
    if (!empty($targetGroup['no_time_limit'])) {
        $timeString = '∞';
    } else {
        $diff = $targetGroup['expire_date'] - time();
        $days = floor($diff / 86400);
        if ($diff <= 0) {
            $timeString = "Expired";
            $isExpired = true;
        } else {
            $hours = floor(($diff % 86400) / 3600);
            $timeString = $days > 0 ? "$days Days" : "$hours Hours";
        }
    }
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Client Config - <?= SERIES_NAME ?></title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0;}
        body{
            min-height:100vh;
            background:linear-gradient(135deg, #0c0c1a 0%, #1a0d2e 50%, #0f172a 100%);
            color:#e2e8f0;font-family:'Segoe UI', -apple-system, sans-serif;
            display:flex;align-items:center;justify-content:center;padding:20px;
            position:relative;overflow-x:hidden;
        }
        body::before{
            content:'';position:fixed;top:0;left:0;width:100%;height:100%;
            background:radial-gradient(circle at 50% 50%, rgba(34,197,94,0.1) 0%, transparent 60%);
            pointer-events:none;z-index:-1;
        }
        .card{
            background:linear-gradient(145deg, rgba(15,23,42,0.95), rgba(30,41,59,0.75));
            backdrop-filter:blur(25px);border:1px solid rgba(34,197,94,0.2);
            border-radius:24px;padding:30px;width:100%;max-width:500px;
            box-shadow:0 30px 60px rgba(0,0,0,0.5);text-align:center;
        }
        h2{
            background:linear-gradient(135deg, #22c55e, #4ade80);
            -webkit-background-clip:text;-webkit-text-fill-color:transparent;
            font-size:24px;margin-bottom:20px;font-weight:800;
        }
        input{
            width:100%;background:rgba(4,8,20,0.6);border:1px solid rgba(59,130,246,0.3);
            border-radius:16px;padding:14px;color:white;font-size:15px;margin-bottom:15px;
            text-align:center;outline:none;transition:0.3s;
        }
        input:focus{border-color:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,0.15);}
        button{
            width:100%;padding:14px;border:none;border-radius:16px;
            background:linear-gradient(135deg, #22c55e, #16a34a);color:white;
            font-weight:bold;font-size:15px;cursor:pointer;
            transition:0.3s;margin-bottom:10px;display:flex;align-items:center;justify-content:center;gap:8px;
        }
        button:hover{transform:translateY(-2px);box-shadow:0 15px 40px rgba(34,197,94,0.4);}
        button:disabled{opacity:0.6;cursor:not-allowed;transform:none;}
        .btn-update{background:linear-gradient(135deg, #f59e0b, #d97706);color:#000;}
        .btn-share{background:linear-gradient(135deg, #8b5cf6, #6d28d9);}
        .btn-stats{background:linear-gradient(135deg, #0ea5e9, #0369a1);}
        .config-box{
            background:rgba(0,0,0,0.4);padding:15px;border-radius:12px;
            font-family:monospace;font-size:12px;word-break:break-all;
            border:1px solid rgba(255,255,255,0.1);margin-bottom:15px;
            max-height:150px;overflow-y:auto;text-align:left;color: #a7f3d0;
        }
        .message{margin-bottom:15px;font-weight:bold;padding:10px;border-radius:8px;}
        .error{color:#ef4444;background:rgba(239,68,68,0.1);}
        .success{color:#22c55e;background:rgba(34,197,94,0.1);}
        hr{border:0;border-top:1px solid rgba(255,255,255,0.1);margin:20px 0;}
        label{display:block;text-align:left;margin-bottom:8px;font-size:13px;color:#94a3b8;font-weight:bold;}
.config-list { display:flex; flex-direction:column; gap:10px; margin-bottom:20px; }
.config-row { display:flex; gap:8px; background:rgba(0,0,0,0.3); padding:8px; border-radius:10px; align-items:center; }
.config-row .code-input { flex:1; min-width:0; font-family:monospace; font-size:12px; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.1); color:#a7f3d0; padding:8px 12px; border-radius:8px; outline:none; margin-bottom:0; text-align:left; }
.btn-icon { width:36px; height:36px; display:flex; align-items:center; justify-content:center; border:none; border-radius:8px; cursor:pointer; flex-shrink:0; transition:0.3s; font-size:16px; margin-bottom:0; padding:0; }
.btn-copy { background:#3b82f6; color:white; }
.btn-copy:hover { filter:brightness(1.2); }
.btn-del { background:#ef4444; color:white; }
.btn-del:hover { filter:brightness(1.2); }

.add-form textarea { width:100%; background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.1); color:white; padding:10px; border-radius:10px; margin-bottom:10px; }

.stats-grid {
display: none; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;
}
.stat-card {
background: rgba(255,255,255,0.05); padding: 12px; border-radius: 12px;
border: 1px solid rgba(255,255,255,0.1); display:flex; flex-direction:column; justify-content:center; align-items:center;
}
.stat-label { font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
.stat-value { font-size: 16px; font-weight: bold; color: #fff; margin-top: 5px; }
.stat-green { color: #4ade80; }
.stat-red { color: #f87171; }

.spinner {
border: 3px solid rgba(255,255,255,0.3); border-radius: 50%;
border-top: 3px solid #fff; width: 16px; height: 16px;
animation: spin 1s linear infinite; display: none;
}
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>
</head>
<body>
    <div class="card">
        <?php if (!$isUnlocked): ?>
            <h2>🔒 Client Access</h2>
            <?php if($shareError) echo "<div class='message error'>$shareError</div>"; ?>
            <p style="margin-bottom:20px;color:#cbd5e1;">Enter password to unlock <b><?= htmlspecialchars($targetGroup['name']) ?></b></p>
            <form method="post">
                <input type="password" name="share_pass" placeholder="Password" required autofocus>
                <button type="submit" name="unlock_share">Unlock</button>
            </form>
        <?php else: ?>
            <h2>🍁 <?= htmlspecialchars($targetGroup['name']) ?></h2>
            <?php 
                if($shareSuccess) echo "<div class='message success'>$shareSuccess</div>"; 
                if($shareError) echo "<div class='message error'>$shareError</div>"; 
                $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
                $baseUrl = $proto . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
                
                $encodedName = str_replace(' ', '%20', $targetGroup['name']);
                $fullShareLink = $baseUrl . '?share=' . urlencode($targetGroup['uuid']) 
                               . (!empty($targetGroup['owner']) ? '&owner=' . urlencode($targetGroup['owner']) : '') 
                               . '&pass=' . urlencode($targetGroup['pass']) 
                               . '&sub=1' 
                               . '#' . TAG_NAME . '%20' . $encodedName;
            ?>

            <?php if ($targetGroup['free']): ?>
                <?php
                $finalConfigStr = implode("\n", $targetGroup['configs']);
                $encodedNameForSub = str_replace(' ', '%20', $targetGroup['name']);
                $subUrl = $baseUrl . '?share=' . urlencode($targetGroup['uuid']) . '&sub=1#' . TAG_NAME . '%20' . $encodedNameForSub;
                ?>
                <div class="config-list">
                    <?php foreach ($targetGroup['configs'] as $cfg): ?>
                    <div class="config-row">
                        <input type="text" class="code-input" value="<?= htmlspecialchars($cfg) ?>" readonly onclick="this.select();">
                        <button type="button" class="btn-icon btn-copy" onclick="copyText(decodeURIComponent('<?= rawurlencode($cfg) ?>'), this)" title="Copy">📋</button>
                        <form method="post" onsubmit="return confirm('Delete this config?');" style="margin:0; display:flex; flex-shrink:0;">
                            <input type="hidden" name="delete_config_val" value="<?= htmlspecialchars($cfg) ?>">
                            <button type="submit" class="btn-icon btn-del" title="Delete">🗑️</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <form method="post" class="add-form">
                    <input type="hidden" name="add_config_lines" value="1">
                    <textarea name="new_configs" placeholder="Paste multiple vmess:// vless:// configs here..." rows="4"></textarea>
                    <button type="submit">➕ Add Configs</button>
                </form>

                <hr>

                <button class="btn-share" onclick="copyText(decodeURIComponent('<?= rawurlencode($finalConfigStr) ?>'), this)">📋 Copy All Configs</button>
                <button class="btn-share" onclick="copyText('<?= addslashes($subUrl) ?>', this)">🔗 Copy Sub Link</button>

            <?php else: ?>
                <?php
                $finalConfigStr = implode("\n", $targetGroup['configs']);
                ?>

                <?php if (!empty($targetGroup['info']) && !$targetGroup['free'] && empty($targetGroup['hide_stats'])): ?>
                    <button id="checkStatsBtn" class="btn-stats" onclick="fetchClientStats()">
                        <div class="spinner" id="btnSpinner"></div>
                        <span id="btnText">🔄 Check Remaining Data</span>
                    </button>
                    
                    <div id="statsError" class="message error" style="display:none;"></div>
                    
                    <div class="stats-grid" id="statsGrid">
                        <div class="stat-card">
                            <div class="stat-label">Data Left</div>
                            <div class="stat-value" id="valData">-- GB</div>
                            <?php if (!empty($targetGroup['quota']) && $targetGroup['quota'] > 0): ?>
                            <div style="font-size: 11px; color: #94a3b8; margin-top: 4px; font-weight: 600;">of <?= htmlspecialchars($targetGroup['quota']) ?>GB</div>
                            <?php endif; ?>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Time Remaining</div>
                            <div class="stat-value <?= $isExpired ? 'stat-red' : 'stat-green' ?>" id="valTime"><?= $timeString ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="config-box"><?= nl2br(htmlspecialchars($finalConfigStr)) ?></div>

                <button onclick="copyText(decodeURIComponent('<?= rawurlencode($finalConfigStr) ?>'), this)">📋 Copy Config</button>
                <?php if (!$targetGroup['free']): ?>
                <button class="btn-share" onclick="copyText('<?= addslashes($fullShareLink) ?>', this)">🔗 Copy Sub Link</button>
                <?php endif; ?>

                <hr>

                <?php if (!$targetGroup['free']): ?>
                <form method="post">
                    <label>⚙️ Change Password</label>
                    <input type="text" name="new_pass" placeholder="New Password" value="<?= htmlspecialchars($targetGroup['pass']) ?>" required>
                    <button type="submit" name="update_config_pass" class="btn-update">💾 Save</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<script>
function copyText(text, button) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => { showSuccess(button); })
        .catch(() => { fallbackCopy(text, button); });
    } else { fallbackCopy(text, button); }
}
function fallbackCopy(text, button) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.left = '-999999px';
    textarea.style.top = '-999999px';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    try {
        const successful = document.execCommand('copy');
        if(successful) showSuccess(button);
        else alert('Copy failed');
    } catch(e) { }
    document.body.removeChild(textarea);
}
function showSuccess(button) {
    const originalText = button.innerHTML;
    const isIcon = button.classList.contains('btn-icon');
    button.innerHTML = isIcon ? '✅' : '✅ Copied!';
    if (!isIcon) button.style.background = '#22c55e';

    setTimeout(() => {
        button.innerHTML = originalText;
        if (!isIcon) button.style.background = '';
    }, 2000);
}

function fetchClientStats() {
    const btn = document.getElementById('checkStatsBtn');
    const spinner = document.getElementById('btnSpinner');
    const btnText = document.getElementById('btnText');
    const grid = document.getElementById('statsGrid');
    const errBox = document.getElementById('statsError');
    const checkUrl = "<?= isset($targetGroup['info']) ? addslashes($targetGroup['info']) : '' ?>";

    if (!checkUrl) {
        alert("No stats URL configured.");
        return;
    }

    btn.disabled = true;
    spinner.style.display = 'inline-block';
    btnText.innerText = "Checking...";
    grid.style.display = 'none';
    errBox.style.display = 'none';

    // Uses our built-in PHP proxy & bypasses browser caches with a timestamp
    fetch('?ajax_fetch=' + encodeURIComponent(checkUrl) + '&t=' + new Date().getTime())
    .then(r => r.text())
    .then(gb => {
        btn.disabled = false;
        spinner.style.display = 'none';
        btnText.innerText = "🔄 Refresh Data";
        let val = parseFloat(gb);
        if (!isNaN(val)) {
            grid.style.display = 'grid';
            const valData = document.getElementById('valData');
            valData.innerText = gb + " GB";
            valData.className = "stat-value " + (val < 1 ? 'stat-red' : 'stat-green');
        } else {
            errBox.style.display = 'block';
            errBox.innerText = "⚠️ Error parsing data from panel URL.";
        }
    })
    .catch(error => {
        btn.disabled = false;
        spinner.style.display = 'none';
        btnText.innerText = "🔄 Check Remaining Data";
        errBox.style.display = 'block';
        errBox.innerText = "⚠️ Network error trying to connect to the panel.";
    });
}
</script>
</body>
</html>
<?php
exit;
}

/* ================= 2. SUBSCRIPTION VIEW LOGIC ================= */
if ($is_authenticated_sub && (!isset($_GET['sub']) || $_GET['sub'] !== '1')) {
    $sub_error = '';
    if (isset($_POST['unlock_key']) && !secure_compare($_POST['unlock_key'], MAIN_ADMIN_PASSWORD)) {
        $sub_error = "Invalid Key";
    }

    if (isset($_POST['unlock_key']) && secure_compare($_POST['unlock_key'], MAIN_ADMIN_PASSWORD)) {
        header("Location: ?sub=1&pass=" . MAIN_ADMIN_PASSWORD);
        exit;
    }
    
    $reqOwner = $_GET['owner'] ?? '';
    if (!empty($reqOwner) && $reqOwner !== MAIN_ADMIN_UUID) {
        $admins = get_admin_users();
        $admin_pass_for_sub = '';
        foreach($admins as $admin) {
            if ($admin['uuid'] === $reqOwner) {
                $admin_pass_for_sub = $admin['password'];
                break;
            }
        }
        if (!empty($admin_pass_for_sub) && isset($_POST['unlock_key']) && secure_compare($_POST['unlock_key'], $admin_pass_for_sub)) {
            header("Location: ?sub=1&pass=" . urlencode($admin_pass_for_sub) . "&owner=" . urlencode($reqOwner));
            exit;
        }
    }
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Unlock Configs</title>
<style>
body{background:#0f172a;color:white;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
.login-box{background:rgba(0,0,0,0.3);padding:30px;border-radius:12px;width:300px;text-align:center;}
input{width:100%;padding:12px;margin-bottom:15px;border-radius:8px;border:1px solid #334155;background:#1e293b;color:white;}
button{width:100%;padding:12px;border:none;background:#22c55e;color:white;font-weight:bold;border-radius:8px;cursor:pointer;}
.error{color:#ef4444;margin-bottom:15px;}
</style>
</head>
<body>
<div class="login-box">
<h3>Enter Access Key</h3>
<?php if (!empty($sub_error)) echo "<div class='error'>$sub_error</div>"; ?>
<form method="post">
<input type="password" name="unlock_key" placeholder="Enter Access Key" required autofocus>
<button type="submit" name="access_sub">Unlock Configs</button>
</form>
</div>
</body>
</html>
<?php
exit;
}

if ($is_authenticated_sub && isset($_GET['sub']) && $_GET['sub'] === '1') {
    header("Content-Type: text/html; charset=utf-8");
    $allGroups = get_database_groups();
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base  = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $reqOwner = $_GET['owner'] ?? '';
    $groups =[];

    if (empty($reqOwner) || $reqOwner === MAIN_ADMIN_UUID) {
        // Main Admin: see all configs not assigned to other admins
        foreach($allGroups as $group) {
            if (empty($group['owner']) || $group['owner'] === MAIN_ADMIN_UUID) {
                $groups[] = $group;
            }
        }
    } else {
        // Sub Admin: see only their own configs
        foreach ($allGroups as $group) {
            if (($group['owner'] ?? '') === $reqOwner) {
                $groups[] = $group;
            }
        }
    }
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= SERIES_NAME ?> - Individual Configs</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0;}
        body{
            min-height:100vh;
            background:linear-gradient(135deg, #0c0c1a 0%, #1a0d2e 50%, #0f172a 100%);
            color:#e2e8f0;font-family:'Segoe UI', -apple-system, sans-serif;
            padding:20px;position:relative;overflow-x:hidden;
        }
        body::before{
            content:'';position:fixed;top:0;left:0;width:100%;height:100%;
            background:radial-gradient(circle at 20% 80%, rgba(120,119,198,0.2) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(34,197,94,0.15) 0%, transparent 50%);
            pointer-events:none;z-index:-1;
        }
        .container{width:100%; max-width:1000px; margin:auto;}
        h1{
            text-align:center;background:linear-gradient(135deg, #22c55e, #4ade80);
            -webkit-background-clip:text;-webkit-text-fill-color:transparent;
            font-size:28px;font-weight:700;margin-bottom:30px;
        }
        .config-item{
            background:linear-gradient(145deg, rgba(15,23,42,0.95), rgba(30,41,59,0.75));
            backdrop-filter:blur(25px);border:1px solid rgba(34,197,94,0.2);
            border-radius:24px;padding:28px;margin-bottom:24px;
            box-shadow:0 25px 50px rgba(0,0,0,0.4);
            transition:all 0.4s cubic-bezier(0.4,0,0.2,1);
        }
        .config-item:hover{transform:translateY(-6px);box-shadow:0 35px 70px rgba(34,197,94,0.2);}
        .name{
            font-size:16px;font-weight:700;color:#22c55e;
            margin-bottom:20px;display:flex;align-items:center;gap:12px;
        }
        .config-link{
            background:rgba(4,8,20,0.9);border:1px solid rgba(59,130,246,0.4);
            padding:20px;border-radius:16px;font-family:'JetBrains Mono',monospace;
            font-size:13px;word-break:break-all;margin-bottom:20px;line-height:1.6;
            max-height:100px;overflow-y:auto;
        }
        .btn-group{display:flex;gap:16px;flex-wrap:wrap;}
        .btn{
            padding:16px 24px;border:none;border-radius:16px;
            font-size:15px;font-weight:700;cursor:pointer;
            transition:all 0.3s;
            min-width:140px;display:flex;align-items:center;justify-content:center;
            gap:8px;font-family:inherit;
        }
        .btn-config{background:linear-gradient(135deg, #3b82f6, #1d4ed8);color:white;}
        .btn-share{background:linear-gradient(135deg, #eab308, #f59e0b);color:#000;}
        .btn:hover{transform:translateY(-4px);box-shadow:0 20px 50px rgba(0,0,0,0.5);}
        .btn.success{background:linear-gradient(135deg, #22c55e, #16a34a)!important;}
        @media(max-width:480px){.btn-group{flex-direction:column;}.btn{width:100%;}}
    </style>
</head>
<body>
    <div class="container">
        <h1>🍁 <?= SERIES_NAME ?> Configs</h1>
        <?php foreach ($groups as $group): 
            $cfgContent = implode("\n", $group['configs']);
            $shareLink = $proto.'://'.$_SERVER['HTTP_HOST'].$base.'/index.php?share='.urlencode($group['uuid']) . (!empty($group['owner']) ? '&owner=' . urlencode($group['owner']) : '') . '#' . TAG_NAME;
        ?>
        <div class="config-item">
            <div class="name">🏷️ <?= htmlspecialchars($group['name']) ?></div>
            <div class="config-link"><?= htmlspecialchars($cfgContent) ?></div>
            <div class="btn-group">
                <button class="btn btn-config" onclick="copyText(decodeURIComponent('<?= rawurlencode($cfgContent) ?>'), this)">📋 Copy Config</button>
                <?php if (!$group['free']): ?>
                <button class="btn btn-share" onclick="copyText('<?= addslashes($shareLink) ?>', this)">🔗 Share Publicly</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <script>
    function copyText(text, button) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => { showSuccess(button); })
            .catch(() => { fallbackCopy(text, button); });
        } else { fallbackCopy(text, button); }
    }
    function fallbackCopy(text, button) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-999999px';
        textarea.style.top = '-999999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        try {
            const successful = document.execCommand('copy');
            if(successful) showSuccess(button);
            else alert('Copy failed');
        } catch(e) { }
        document.body.removeChild(textarea);
    }
    function showSuccess(button) {
        const originalText = button.innerHTML;
        button.innerHTML = '✅ Copied!';
        button.classList.add('success');
        setTimeout(() => {
            button.innerHTML = originalText;
            button.classList.remove('success');
        }, 2000);
    }
    </script>
</body>
</html>
<?php
exit;
}

// Default subscription output for clients
if ($is_authenticated_sub) {
    if (isset($_GET['raw'])) {
        ob_clean();
        header("Content-Type: text/plain; charset=utf-8");
        echo file_exists(DB_FILE) ? trim(file_get_contents(DB_FILE)) : '';
        exit;
    }

    $cleanLines =[];
    $allGroups = get_database_groups();
    $reqOwner = $_GET['owner'] ?? '';

    foreach ($allGroups as $group) {
        $ownerMatches = false;
        if (!empty($reqOwner)) {
            $ownerMatches = (($group['owner'] ?? '') === $reqOwner);
        } else {
            // Main admin sees their own + unassigned
            $ownerMatches = (empty($group['owner']) || $group['owner'] === MAIN_ADMIN_UUID);
        }

        if ($ownerMatches && !$group['exclude']) {
            foreach ($group['configs'] as $cfg) {
                $cfg = trim($cfg);
                if (!empty($cfg)) {
                    $cleanLines[] = $cfg;
                }
            }
        }
    }

    error_reporting(0);
    ob_clean(); // Prevent Hiddify base64 parse errors
    header("Content-Type: text/plain; charset=utf-8");
    header("profile-title: " . SERIES_NAME);
    echo base64_encode(implode("\n", $cleanLines));
    exit;
}

/* ================= 3. ADMIN PANEL AUTHENTICATION ================= */
$currentAdmin = null;
$isAdminLoggedIn = false;

if (isset($_SESSION['admin_uuid'])) {
    $admins = get_admin_users();
    foreach ($admins as $admin) {
        if ($admin['uuid'] === $_SESSION['admin_uuid']) {
            $currentAdmin = $admin;
            $isAdminLoggedIn = true;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_admin'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Prioritize main admin login check
    if (secure_compare($username, 'main_admin') && secure_compare($password, MAIN_ADMIN_PASSWORD)) {
        $_SESSION['admin_uuid'] = MAIN_ADMIN_UUID;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $authenticatedSubAdmin = authenticate_admin($username, $password);
    if ($authenticatedSubAdmin) {
        $_SESSION['admin_uuid'] = $authenticatedSubAdmin['uuid'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $admin_error = "Invalid Username or Password";
    }
}

// If not logged in, show login page
if (!$isAdminLoggedIn) {
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Login</title>
<style>
body{background:#0f172a;color:white;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
.login-box{background:rgba(0,0,0,0.3);padding:30px;border-radius:12px;width:300px;text-align:center;}
h2{color:#4ade80;margin-bottom:20px;}
input{width:100%;box-sizing:border-box;padding:12px;margin-bottom:15px;border-radius:8px;border:1px solid #334155;background:#1e293b;color:white;}
button{width:100%;padding:12px;border:none;background:#22c55e;color:white;font-weight:bold;border-radius:8px;cursor:pointer;}
.error{color:#ef4444;margin-bottom:15px;background:rgba(239,68,68,0.1);padding:10px;border-radius:8px;}
</style>
</head>
<body>
<div class="login-box">
<h2>Admin Panel</h2>
<?php if (isset($admin_error)) echo "<div class='error'>❌ $admin_error</div>"; ?>
<form method="post">
<input type="text" name="username" placeholder="Username" required autofocus>
<input type="password" name="password" placeholder="Password" required>
<button type="submit" name="login_admin">Login</button>
</form>
</div>
</body>
</html>
<?php
exit;
}

/* ================= 4. ALL POST ACTIONS (Admin Panel) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['admin_uuid'])) {
    // --- 4.1 Admin Management Actions ---
    if ($currentAdmin['uuid'] === MAIN_ADMIN_UUID) {
        if (isset($_POST['add_admin'])) {
            $newAdminUsername = trim($_POST['new_admin_username']);
            $newAdminPassword = trim($_POST['new_admin_password']);
            if (!empty($newAdminUsername) && !empty($newAdminPassword)) {
                add_admin_user($newAdminUsername, $newAdminPassword);
            }
        }
        if (isset($_POST['update_admin'])) {
            $uuid = $_POST['admin_uuid'];
            $username = trim($_POST['admin_username']);
            $password = trim($_POST['admin_password']);
            if (!empty($username) && !empty($password) && $uuid !== MAIN_ADMIN_UUID) {
                $admins = get_admin_users();
                foreach ($admins as &$a) {
                    if ($a['uuid'] === $uuid) {
                        $a['username'] = $username;
                        $a['password'] = $password;
                        break;
                    }
                }
                save_admin_users($admins);
            }
        }
        if (isset($_POST['delete_admin'])) {
            $deleteUUID = $_POST['admin_uuid'];
            if ($deleteUUID !== MAIN_ADMIN_UUID) {
                $admins = get_admin_users();
                $admins = array_filter($admins, function($a) use ($deleteUUID) {
                    return $a['uuid'] !== $deleteUUID;
                });
                save_admin_users($admins);
            }
        }
    }

    // --- 4.2 Config Management Actions ---
    $groups = get_database_groups();

    if (isset($_POST['add']) && trim($_POST['config']) !== '') {
        $rawConfig = trim($_POST['config']);
        $lines = preg_split("/\r\n|\n|\r/", $rawConfig);
        $newConfigs =[];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^[a-z0-9]+\:\/\//i', $line)) $newConfigs[] = $line;
        }

        if (!empty($newConfigs)) {
            $isFree = isset($_POST['config_free_add']);
            $noTime = isset($_POST['config_notime_add']);
            $days = (int)($_POST['config_days'] ?? 0);
            $quota = (float)($_POST['config_quota_add'] ?? 0);
            $hideStats = isset($_POST['config_hide_stats_add']);
            $info = trim($_POST['config_info']);

            if ($isFree) {
                $noTime = true;
                $quota = 0;
                $hideStats = true;
                $info = '';
            }

            $expireDate = $noTime ? 0 : time() + ($days * 86400);

            $groups[] =[
                'uuid' => uniqid(),
                'name' => trim($_POST['config_name']) ?: 'Config ' . (count($groups) + 1),
                'pass' => $isFree ? '' : (trim($_POST['config_pass']) ?: '1234'),
                'note' => trim($_POST['config_note']),
                'info' => $info,
                'type' => 'auto',
                'exclude' => isset($_POST['config_exclude_add']),
                'free' => $isFree,
                'hide_stats' => $hideStats,
                'owner' => $currentAdmin['uuid'] ?? '',
                'expire_date' => $expireDate,
                'no_time_limit' => $noTime,
                'quota' => $quota,
                'configs' => $newConfigs
            ];
            save_database_groups($groups);
        }
    }

    if (isset($_POST['delete_uuid'])) {
        $deleteUUID = $_POST['delete_uuid'];
        foreach ($groups as $index => $group) {
            if ($group['uuid'] === $deleteUUID && ($currentAdmin['uuid'] === MAIN_ADMIN_UUID || ($group['owner'] ?? '') === $currentAdmin['uuid'])) {
                array_splice($groups, $index, 1);
                save_database_groups($groups);
                break;
            }
        }
    }

    if (isset($_POST['updates'])) {
        $needsSave = false;
        foreach ($groups as &$group) {
            if (isset($_POST['updates'][$group['uuid']])) {
                if ($currentAdmin['uuid'] === MAIN_ADMIN_UUID || ($group['owner'] ?? '') === $currentAdmin['uuid']) {
                    $data = $_POST['updates'][$group['uuid']];

                    $group['name'] = trim($data['name']);
                    if(isset($data['note'])) $group['note'] = trim($data['note']);
                    if(isset($data['info'])) $group['info'] = trim($data['info']);
                    
                    $group['exclude'] = isset($data['exclude']);
                    $group['free'] = isset($data['free']);
                    $group['hide_stats'] = isset($data['hide_stats']);
                    $group['no_time_limit'] = isset($data['notime']);
                    $group['quota'] = (float)($data['quota'] ?? 0);

                    // Expiry parsing
                    if (!$group['no_time_limit']) {
                        $old_days = (int)($data['old_days'] ?? 0);
                        $new_days = (int)($data['days'] ?? 0);
                        // Only overwrite the expire_date if the admin explicitly changed the days remaining
                        if ($new_days !== $old_days) {
                            $group['expire_date'] = time() + ($new_days * 86400);
                        }
                    } else {
                        $group['expire_date'] = 0;
                    }

                    if ($group['free']) {
                        $group['pass'] = ''; // Ensure free configs don't store passwords
                        $group['no_time_limit'] = true;
                        $group['hide_stats'] = true;
                        $group['expire_date'] = 0;
                        $group['quota'] = 0;
                        $group['info'] = '';
                    } else {
                        if (isset($data['pass'])) {
                            $group['pass'] = trim($data['pass']);
                        }
                        if (empty($group['pass'])) {
                            $group['pass'] = '1234'; // Ensure paid configs always have a password
                        }
                    }

                    if (isset($data['configs'])) {
                        $rawConfig = trim($data['configs']);
                        $lines = preg_split("/\r\n|\n|\r/", $rawConfig);
                        $newConfigs =[];
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (preg_match('/^[a-z0-9]+\:\/\//i', $line)) $newConfigs[] = $line;
                        }
                        $group['configs'] = $newConfigs;
                    }

                    $needsSave = true;
                }
            }
        }
        unset($group);

        if ($needsSave) {
            save_database_groups($groups);
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ================= 5. ADMIN PANEL RENDER ================= */
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= SERIES_NAME ?> Panel</title>
<style>
/* Base Styles */
*{box-sizing:border-box;margin:0;padding:0;}
body{
    min-height:100vh;background:linear-gradient(135deg, #0c0c1a 0%, #1a0d2e 50%, #0f172a 100%);
    color:#e2e8f0;font-family:'Segoe UI', -apple-system, sans-serif;
    padding:15px;position:relative;overflow-x:hidden;
}
body::before{
    content:'';position:fixed;top:0;left:0;width:100%;height:100%;
    background:radial-gradient(circle at 20% 80%, rgba(120,119,198,0.2) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(34,197,94,0.15) 0%, transparent 50%);
    pointer-events:none;z-index:-1;
}
.container{width:100%; max-width: 1000px; margin:auto; padding:0 5px;}
header{text-align:center;margin-bottom:40px;}
h1{font-size:36px;margin:0 0 10px 0;color:#4ade80;}
.subtitle{font-size:16px;color:#94a3b8;}


.card{
background:linear-gradient(145deg, rgba(15,23,42,0.95), rgba(30,41,59,0.75));
backdrop-filter:blur(30px);border:1px solid rgba(34,197,94,0.2);
border-radius:24px;padding:25px;margin-bottom:25px;
box-shadow:0 20px 40px rgba(0,0,0,0.3);
transition: all 0.3s ease;
}

/* Active Config Highlight */
.card.active-config {
border: 1px solid rgba(234, 179, 8, 0.6);
box-shadow: 0 20px 40px rgba(234, 179, 8, 0.15);
}

input,textarea{
width:100%;background:rgba(4,8,20,0.85);border:1px solid rgba(59,130,246,0.35);
border-radius:12px;padding:12px 16px;color:#e2e8f0;font-size:14px;
font-family:inherit;transition:0.3s;
}
input:focus,textarea:focus{
outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,0.15);
}

/* Custom Input Wrappers with Units inside */
.input-wrapper { position: relative; flex: 1; max-width: 150px; }
.input-wrapper input { padding-right: 48px; }
.input-wrapper .unit { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px; font-weight: bold; pointer-events: none; }

.row-inputs { display:flex; gap: 10px; margin-bottom:12px; }
.name-input { flex: 2; border-color:rgba(34,197,94,0.4); }
.pass-input { flex: 1; border-color:rgba(234,179,8,0.4); }
.note-input { flex:1; border-color:rgba(99,102,241,0.4); }

.info-row { display:flex; gap:10px; align-items:center; margin-bottom:10px; flex-wrap:wrap; }
.info-input { flex:3; border-color:rgba(236, 72, 153, 0.4); }
.btn-check {
flex:1; background:#0ea5e9; color:white; border:none; padding:12px; border-radius:12px;
cursor:pointer; font-weight:bold; display:flex; align-items:center; justify-content:center; gap:5px;
min-width: 90px;
}
.btn-check:hover { background:#0284c7; }
.btn-check:disabled { background:#334155; cursor:wait; }

.config-preview{
background:rgba(0,0,0,0.5);border:1px solid rgba(255,255,255,0.1);
border-radius:12px;padding:15px;font-family:'JetBrains Mono',monospace;
font-size:12px;height:100px;overflow-y:auto;margin-bottom:15px;color:#94a3b8;
}

.btn-group{display:flex;flex-wrap:wrap;gap:10px;}
.btn{
padding:12px 20px;border:none;border-radius:12px;font-size:14px;
font-weight:700;cursor:pointer;transition:0.3s;
flex:1; display:flex; align-items:center; justify-content:center; color:white;
}
.btn-primary{background:#22c55e;}
.btn-secondary{background:#3b82f6;}
.btn-warning{background:#f59e0b; color:black;}
.btn-danger{background:#ef4444;}
.btn:hover{transform:translateY(-2px);filter:brightness(1.1);}

/* Beautiful Checkbox UI */
.checkbox-container {
display: flex;
flex-wrap: nowrap;
align-items: center;
gap: 20px;
background: rgba(255,255,255,0.05);
padding: 16px 20px;
border-radius: 12px;
border: 1px solid rgba(255,255,255,0.1);
margin-bottom: 15px;
}
.checkbox-item {
display: flex;
align-items: center;
gap: 8px;
cursor: pointer;
}
.checkbox-item input[type="checkbox"] {
width: 20px;
height: 20px;
margin: 0;
cursor: pointer;
accent-color: #22c55e;
}
.checkbox-item input[type="checkbox"]:disabled {
cursor: not-allowed;
accent-color: #4b5563;
}
.checkbox-item span {
font-size: 14px;
color: #cbd5e1;
font-weight: 600;
user-select: none;
}

.sub-section{
text-align:center;padding:24px;background:rgba(15,23,42,0.6);
border-radius:18px;margin-top:24px;border:1px solid rgba(34,197,94,0.2);
}

.spinner {
border: 3px solid rgba(255,255,255,0.3); border-radius: 50%;
border-top: 3px solid #fff; width: 14px; height: 14px;
animation: spin 1s linear infinite; display: none; margin-right:5px;
}
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

.admin-actions { display: flex; gap: 8px; flex-shrink: 0; }
.btn-icon { width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; border: none; border-radius: 8px; cursor: pointer; transition: 0.3s; font-size: 16px; padding: 0; margin: 0; flex-shrink: 0;}
.btn-icon.btn-secondary { background: #3b82f6; color: white; }
.btn-icon.btn-secondary:hover { filter: brightness(1.2); }
.btn-icon.btn-danger { background: #ef4444; color: white; }
.btn-icon.btn-danger:hover { filter: brightness(1.2); }

@media (max-width: 768px) {
.row-inputs { flex-direction: column; gap: 10px; }
.info-row { flex-direction: column; align-items: stretch; gap: 10px; border-bottom: none !important; padding-bottom: 0 !important; }
.btn-check, .info-input, .note-input, .input-wrapper { width: 100%; flex: none; max-width: none !important; }
.checkbox-container { flex-direction: column; align-items: flex-start; gap: 15px; }
.btn-group { flex-direction: column; }
header h1 { font-size: 28px; }
.admin-row { gap: 10px; margin-bottom: 20px !important; border-bottom: 1px solid rgba(255,255,255,0.1) !important; padding-bottom: 20px !important; }
.admin-actions { width: 100%; display: flex; gap: 10px; margin-top: 5px; }
.admin-actions .btn-icon { flex: 1; width: auto; height: 42px; }
}
</style>
</head>
<body>
<div class="container">
<header>
    <h1>🍁 <?= SERIES_NAME ?></h1>
    <?php if ($isAdminLoggedIn): ?>
    <p class="subtitle" style="margin-top:5px;">Logged in as: <b style="color:#4ade80;"><?= htmlspecialchars($currentAdmin['username']) ?></b></p>
    <div style="margin-top:20px">
        <a href="?logout=1" style="color:#ef4444;text-decoration:none;font-weight:bold;border:1px solid #ef4444;padding:6px 14px;border-radius:8px;">Logout</a>
    </div>
    <?php endif; ?>
</header>

<?php
if(isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

$allGroups = get_database_groups();
$groups =[];

if ($currentAdmin['uuid'] === MAIN_ADMIN_UUID) {
    $groups = $allGroups;
} else {
    foreach ($allGroups as $group) {
        if (($group['owner'] ?? '') === $currentAdmin['uuid']) {
            $groups[] = $group;
        }
    }
}

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base  = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$adminSubPass = $currentAdmin['password'] ?? MAIN_ADMIN_PASSWORD;
$adminSubUUID = $currentAdmin['uuid'] ?? MAIN_ADMIN_UUID;

// Sub link defaults directly to Base64 Raw Output (WITHOUT &sub=1) for universal app import
$subLink = $proto.'://'.$_SERVER['HTTP_HOST'].$base.'/index.php?pass='.urlencode($adminSubPass) . '&owner=' . urlencode($adminSubUUID) . '#' . TAG_NAME;
?>

<?php 
// ---------------------------------------------------------
// Admin Management Section - ONLY FOR MAIN ADMIN
// ---------------------------------------------------------
if ($currentAdmin['uuid'] === MAIN_ADMIN_UUID) {
    $admins = get_admin_users();
?>
<div class="card">
    <h3 style="margin-bottom:15px;color:#f59e0b;">👥 Admin Users</h3>
    <?php foreach ($admins as $admin): 
        if ($admin['uuid'] === MAIN_ADMIN_UUID) continue; 
    ?>
    <form method="post" class="info-row admin-row" style="margin-bottom:15px; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:15px;">
        <input type="hidden" name="admin_uuid" value="<?= htmlspecialchars($admin['uuid']) ?>">
        <input type="text" name="admin_username" value="<?= htmlspecialchars($admin['username']) ?>" style="flex:2; font-weight:bold;" required>
        <input type="text" name="admin_password" value="<?= htmlspecialchars($admin['password']) ?>" style="flex:1; color:#facc15;" required>
        <div class="admin-actions">
            <button type="submit" name="update_admin" class="btn-icon btn-secondary" title="Save Modifications">💾</button>
            <button type="submit" name="delete_admin" class="btn-icon btn-danger" title="Delete Admin" onclick="return confirm('Are you sure you want to delete this admin?');">🗑️</button>
        </div>
    </form>
    <?php endforeach; ?>

<form method="post" style="margin-top:20px;">
    <div class="row-inputs">
        <input type="text" name="new_admin_username" placeholder="New Admin Username" required>
        <input type="text" name="new_admin_password" placeholder="New Admin Password" required>
        <button type="submit" name="add_admin" class="btn btn-secondary" style="flex:none; width:auto; padding:12px 20px;">➕ Add Admin</button>
    </div>
</form>
</div>
<?php } ?>

<!-- ADD NEW CONFIG CARD -->
<div class="card">
    <h3 style="margin-bottom:15px;color:#3b82f6;">➕ Add New Config</h3>
    <form method="post">
        <div class="row-inputs">
            <input type="text" name="config_name" class="name-input" placeholder="Name" maxlength="100">
            <input type="text" name="config_pass" id="add_config_pass" class="pass-input" placeholder="Password" value="1234">
        </div>

        <div class="info-row">
            <input type="text" name="config_note" class="note-input" placeholder="Private Note">
            <input type="text" name="config_info" id="config_info_add" class="info-input" placeholder="Stats Info URL (For Checking Remaining Data)">
        </div>

        <div class="info-row" style="margin-bottom:15px;">
            <div class="input-wrapper" id="add_days_wrapper">
                <input type="number" name="config_days" id="config_days_add" class="info-input" placeholder="0">
                <span class="unit">Days</span>
            </div>
            <div class="input-wrapper" id="add_quota_wrapper">
                <input type="number" step="0.01" name="config_quota_add" id="config_quota_add" class="info-input" placeholder="0">
                <span class="unit">GB</span>
            </div>
        </div>

        <div class="checkbox-container">
            <label class="checkbox-item">
                <input type="checkbox" name="config_notime_add" id="config_notime_add" onchange="toggleAddNoTime()">
                <span>No Time Limit</span>
            </label>
            <label class="checkbox-item">
                <input type="checkbox" name="config_exclude_add" id="config_exclude_add">
                <span>Exclude from My Sub</span>
            </label>
            <label class="checkbox-item">
                <input type="checkbox" name="config_free_add" id="config_free_add" onchange="toggleAddFreeConfig()">
                <span>Free Config</span>
            </label>
            <label class="checkbox-item">
                <input type="checkbox" name="config_hide_stats_add" id="config_hide_stats_add">
                <span>Hide Stats</span>
            </label>
        </div>

        <textarea name="config" rows="3" placeholder="Paste multiple vmess:// vless:// configs here, one per line..." style="margin-bottom:15px;"></textarea>

        <button type="submit" name="add" class="btn btn-primary" style="width:100%;">Create Group</button>
    </form>
</div>

<!-- MAIN UPDATE FORM FOR LIST OF CONFIGS -->
<form method="post" id="update_form" style="margin-bottom:40px;">

<?php
$allAdmins = get_admin_users();
$adminNames =[];
foreach ($allAdmins as $admin) {
    $adminNames[$admin['uuid']] = $admin['username'];
}

foreach ($groups as $i => $group): 
    $fullConfig = implode("\n", $group['configs']);
    $shareLink = $proto.'://'.$_SERVER['HTTP_HOST'].$base.'/index.php?share='.urlencode($group['uuid']) . (!empty($group['owner']) ? '&owner=' . urlencode($group['owner']) : '') . '#' . TAG_NAME;

    $ownerName = '';
    if (!empty($group['owner']) && $group['owner'] !== MAIN_ADMIN_UUID && isset($adminNames[$group['owner']])) {
        $ownerName = htmlspecialchars($adminNames[$group['owner']]);
    }
?>
<div class="card <?= !$group['exclude'] ? 'active-config' : '' ?>">
    <input type="hidden" name="updates[<?= $group['uuid'] ?>][uuid_marker]" value="1">
    <div class="row-inputs">
        <div style="flex:2;position:relative;">
            <span style="position:absolute;left:10px;top:12px;">🏷️</span>
            <input type="text" name="updates[<?= $group['uuid'] ?>][name]" value="<?= htmlspecialchars($group['name']) ?>" 
                   style="padding-left:35px;font-weight:bold;color:#4ade80;" maxlength="100">
            <?php if (!empty($ownerName)): ?>
                <span style="position:absolute;right:10px;top:12px;font-size:11px;color:#94a3b8;background:rgba(255,255,255,0.1);padding:3px 8px;border-radius:8px;"><?= $ownerName ?></span>
            <?php endif; ?>
        </div>
        <div style="flex:1;position:relative;">
            <span style="position:absolute;left:10px;top:12px;">🔑</span>
            <input type="text" id="pass_<?= $i ?>" name="updates[<?= $group['uuid'] ?>][pass]" value="<?= htmlspecialchars($group['pass']) ?>" 
                   style="padding-left:35px;color:#facc15; <?= $group['free'] ? 'opacity:0.5;' : '' ?>" <?= $group['free'] ? 'disabled' : '' ?>>
        </div>
    </div>

    <!-- Info URL & Check Button -->
    <div class="info-row" id="info_row_<?= $i ?>">
        <input type="text" name="updates[<?= $group['uuid'] ?>][info]" id="url_<?= $i ?>" 
               value="<?= htmlspecialchars($group['info'] ?? '') ?>" 
               placeholder="🌐 Stats URL" class="info-input">
        <button type="button" class="btn-check" id="btn_check_<?= $i ?>" onclick="checkAdminStats(<?= $i ?>)">
            <div class="spinner" id="spin_<?= $i ?>"></div>
            <span id="btn_txt_<?= $i ?>">Check</span>
        </button>
    </div>

    <div class="info-row" style="margin-bottom:15px;">
        <input type="text" name="updates[<?= $group['uuid'] ?>][note]" value="<?= htmlspecialchars($group['note'] ?? '') ?>" 
               placeholder="📝 Private Note" class="note-input" style="flex:auto; width:100%;">
    </div>

    <div class="info-row" style="margin-bottom:15px;">
        <?php 
            $daysLeft = '';
            if (!$group['no_time_limit']) {
                $daysLeft = floor(($group['expire_date'] - time()) / 86400); // Allow counting negatively safely
            }
        ?>
        <input type="hidden" name="updates[<?= $group['uuid'] ?>][old_days]" value="<?= $daysLeft ?>">
        <div class="input-wrapper" id="days_wrapper_<?= $i ?>" style="<?= $group['no_time_limit'] ? 'display:none;' : '' ?>">
            <input type="number" name="updates[<?= $group['uuid'] ?>][days]" id="days_<?= $i ?>" value="<?= $daysLeft ?>" placeholder="0" class="info-input">
            <span class="unit">Days</span>
        </div>
        <div class="input-wrapper" id="quota_wrapper_<?= $i ?>">
            <input type="number" step="0.01" name="updates[<?= $group['uuid'] ?>][quota]" value="<?= !empty($group['quota']) ? htmlspecialchars($group['quota']) : '' ?>" placeholder="0" class="info-input">
            <span class="unit">GB</span>
        </div>
    </div>

    <div class="checkbox-container">
        <label class="checkbox-item">
            <input type="checkbox" name="updates[<?= $group['uuid'] ?>][notime]" id="notime_<?= $i ?>" <?= !empty($group['no_time_limit']) ? 'checked' : '' ?> onchange="toggleEditNoTime(<?= $i ?>)">
            <span>No Time Limit</span>
        </label>
        <label class="checkbox-item">
            <input type="checkbox" name="updates[<?= $group['uuid'] ?>][exclude]" id="exclude_<?= $i ?>" <?= $group['exclude'] ? 'checked' : '' ?>>
            <span>Exclude from My Sub</span>
        </label>
        <label class="checkbox-item">
            <input type="checkbox" name="updates[<?= $group['uuid'] ?>][free]" id="free_<?= $i ?>" <?= $group['free'] ? 'checked' : '' ?> onchange="toggleEditFreeConfig(<?= $i ?>)">
            <span>Free Config</span>
        </label>
        <label class="checkbox-item">
            <input type="checkbox" name="updates[<?= $group['uuid'] ?>][hide_stats]" id="hide_stats_<?= $i ?>" <?= !empty($group['hide_stats']) ? 'checked' : '' ?>>
            <span>Hide Stats</span>
        </label>
    </div>

    <textarea name="updates[<?= $group['uuid'] ?>][configs]" class="config-preview" style="height:150px;width:100%;background:rgba(0,0,0,0.5);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:15px;color:#94a3b8;font-family:'JetBrains Mono',monospace;font-size:12px;resize:vertical;"><?= htmlspecialchars($fullConfig) ?></textarea>

    <div class="btn-group">
        <button type="button" class="btn btn-secondary" onclick="copyText(decodeURIComponent('<?= rawurlencode($fullConfig) ?>'), this)">📋 Copy Config</button>
        <button type="button" class="btn btn-warning" onclick="copyText('<?= addslashes($shareLink) ?>', this)">🔗 Share Link</button>
        <button type="submit" name="updates[<?= $group['uuid'] ?>][save]" class="btn btn-primary">💾 Save Modifications</button>
        <button type="button" class="btn btn-danger" onclick="confirmDelete('<?= $group['uuid'] ?>')">🗑️ Delete</button>
    </div>
</div>
<?php endforeach; ?>
</form>

<div class="sub-section">
    <div style="font-size:18px;font-weight:700;color:#94a3b8;margin-bottom:15px;">📱 App Subscription Link</div>
    <div class="btn-group" style="justify-content:center;">
        <button class="btn btn-secondary" onclick="copyText('<?= addslashes($subLink) ?>', this)">🔗 Copy Subscription URL</button>
    </div>
</div>

<div style="text-align: center; margin-top: 30px; margin-bottom: 20px;">
    <a href="https://github.com/rezasadid753" target="_blank" rel="noopener noreferrer" style="color: #94a3b8; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: color 0.3s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='#94a3b8'">
        <svg height="24" width="24" viewBox="0 0 16 16" fill="currentColor">
            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"></path>
        </svg>
        <span style="font-weight: 600;">GitHub</span>
    </a>
</div>

</div>

<script>
function toggleAddFreeConfig() {
    const isFree = document.getElementById('config_free_add').checked;
    const passInput = document.getElementById('add_config_pass');
    const noTimeCheckbox = document.getElementById('config_notime_add');
    const hideStatsCheckbox = document.getElementById('config_hide_stats_add');
    const quotaWrapper = document.getElementById('add_quota_wrapper');
    const statsUrlInput = document.getElementById('config_info_add');

    // Password field
    passInput.disabled = isFree;
    passInput.style.opacity = isFree ? '0.5' : '1';
    if (isFree) passInput.value = '';

    // Time Limit
    noTimeCheckbox.checked = true;
    noTimeCheckbox.disabled = true;
    toggleAddNoTime(); // Update days field visibility

    // Quota
    if (quotaWrapper) {
        quotaWrapper.style.display = 'none';
        const quotaInput = document.getElementById('config_quota_add');
        if(quotaInput) quotaInput.value = '0';
    }

    // Hide Stats
    hideStatsCheckbox.checked = true;
    hideStatsCheckbox.disabled = true;
    
    // Stats URL
    if (statsUrlInput) {
        statsUrlInput.style.display = 'none';
        statsUrlInput.value = '';
    }
}

function toggleEditFreeConfig(index) {
    const isFree = document.getElementById('free_' + index).checked;
    const passInput = document.getElementById('pass_' + index);
    const noTimeCheckbox = document.getElementById('notime_' + index);
    const hideStatsCheckbox = document.getElementById('hide_stats_' + index);
    const quotaWrapper = document.getElementById('quota_wrapper_' + index);
    const statsUrlRow = document.getElementById('info_row_' + index);

    // Password field
    passInput.disabled = isFree;
    passInput.style.opacity = isFree ? '0.5' : '1';
    
    // Time Limit
    noTimeCheckbox.checked = isFree;
    noTimeCheckbox.disabled = isFree;
    toggleEditNoTime(index);

    // Quota
    if (quotaWrapper) {
        quotaWrapper.style.display = isFree ? 'none' : '';
    }

    // Hide Stats
    hideStatsCheckbox.checked = isFree;
    hideStatsCheckbox.disabled = isFree;

    // Stats URL Section
    if (statsUrlRow) {
        statsUrlRow.style.display = isFree ? 'none' : 'flex';
    }
}

function toggleAddNoTime() {
    const isChecked = document.getElementById('config_notime_add').checked;
    const daysWrapper = document.getElementById('add_days_wrapper');
    const daysInput = document.getElementById('config_days_add');
    if (isChecked) {
        daysWrapper.style.display = 'none';
        daysInput.value = '';
    } else {
        daysWrapper.style.display = 'block';
    }
}

function toggleEditNoTime(index) {
    const isChecked = document.getElementById('notime_' + index).checked;
    const daysWrapper = document.getElementById('days_wrapper_' + index);
    if (isChecked) {
        daysWrapper.style.display = 'none';
    } else {
        daysWrapper.style.display = 'block';
    }
}

function copyText(text, button) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => { showSuccess(button); })
        .catch(() => { fallbackCopy(text, button); });
    } else { fallbackCopy(text, button); }
}
function fallbackCopy(text, button) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.left = '-999999px';
    textarea.style.top = '-999999px';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    try {
        const successful = document.execCommand('copy');
        if(successful) showSuccess(button);
        else alert('Copy failed');
    } catch(e) { }
    document.body.removeChild(textarea);
}
function showSuccess(button) {
    const originalText = button.innerHTML;
    const isIcon = button.classList.contains('btn-icon');
    
    button.innerHTML = isIcon ? '✅' : '✅ Copied!';
    if (!isIcon) button.style.background = '#22c55e';
    
    setTimeout(() => {
        button.innerHTML = originalText;
        if (!isIcon) button.style.background = '';
    }, 2000);
}
function confirmDelete(uuid) {
    if (confirm('Are you sure you want to delete this group?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        form.innerHTML = `<input type="hidden" name="delete_uuid" value="${uuid}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

// Built-in Internal Proxy Fetcher
function checkAdminStats(index) {
    const urlInput = document.getElementById('url_' + index);
    const spinner = document.getElementById('spin_' + index);
    const btnTxt = document.getElementById('btn_txt_' + index);
    const btnCheck = document.getElementById('btn_check_' + index);
    
    if (!urlInput.value) {
        alert("Please enter a URL first.");
        return;
    }

    spinner.style.display = 'inline-block';
    btnTxt.innerText = "";
    btnCheck.disabled = true;

    fetch('?ajax_fetch=' + encodeURIComponent(urlInput.value) + '&t=' + new Date().getTime())
    .then(r => r.text())
    .then(gb => {
        spinner.style.display = 'none';
        btnCheck.disabled = false;
        
        let val = parseFloat(gb);
        if (!isNaN(val)) {
            btnTxt.innerText = gb + " GB";
            btnCheck.style.background = (val < 1) ? '#f87171' : '#22c55e';
        } else {
            btnTxt.innerText = "Error";
            btnCheck.style.background = '#f87171';
        }
    })
    .catch(error => {
        spinner.style.display = 'none';
        btnCheck.disabled = false;
        btnTxt.innerText = "Error";
        btnCheck.style.background = '#f87171';
    });
}

// Logic for Free Config toggles
document.addEventListener('DOMContentLoaded', function() {
    // Run for "Add New" form on page load
    const addFreeCheckbox = document.getElementById('config_free_add');
    if (addFreeCheckbox && addFreeCheckbox.checked) {
        toggleAddFreeConfig();
    }

    // Run for all existing config forms on page load
    const freeCheckboxes = document.querySelectorAll('input[type="checkbox"][id^="free_"]');
    freeCheckboxes.forEach(checkbox => {
        if (checkbox.checked) {
            const index = checkbox.id.split('_')[1];
            toggleEditFreeConfig(index);
        }
    });
});
</script>
</body>
</html>
<?php exit; ?>
