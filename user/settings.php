<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = false;

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fetch user info (need first_name, last_name, email)
$user = $db->fetchRow("
    SELECT first_name, last_name, email
    FROM " . DB_PREFIX . "users
    WHERE id = ?
", [$user_id]);

if (!$user) {
    session_destroy();
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = 'Invalid CSRF token. Please reload and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }

        // Password change (optional)
        $password_change_requested = ($current_password !== '' || $new_password !== '' || $confirm_password !== '');
        if ($password_change_requested) {
            if ($current_password === '') $errors[] = 'Current password is required.';
            if ($new_password === '') $errors[] = 'New password cannot be empty.';
            elseif (strlen($new_password) < 8) $errors[] = 'New password must be at least 8 characters.';
            if ($new_password !== $confirm_password) $errors[] = 'New password and confirmation do not match.';
        }

        // If changing password, check correctness
        if (empty($errors) && $password_change_requested) {
            $stored_user = $db->fetchRow("SELECT password_hash FROM " . DB_PREFIX . "users WHERE id = ?", [$user_id]);
            if (!$stored_user || !password_verify($current_password, $stored_user['password_hash'])) {
                $errors[] = 'Current password is incorrect.';
            }
        }

        // Check duplicate email
        if (empty($errors)) {
            $email_exists = $db->exists(
                "users",
                "email = :email AND id != :id",
                ['email' => $email, 'id' => $user_id]
            );
            if ($email_exists) {
                $errors[] = 'This email address is already registered with another account.';
            }
        }

        // Do the update if clean
        if (empty($errors)) {
            $db->beginTransaction();
            try {
                $update_data = [
                    'email'      => $email,
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                if ($password_change_requested) {
                    $update_data['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
                }
                $rows_updated = $db->update(
                    'users',
                    $update_data,
                    'id = :id',
                    ['id' => $user_id]
                );
                if ($rows_updated !== false) {
                    $success = true;
                    $user = array_merge($user, $update_data);
                } else {
                    throw new Exception('Failed to update user settings.');
                }
                $db->commit();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $csrf_token = $_SESSION['csrf_token'];
            } catch (Exception $e) {
                $db->rollback();
                $errors[] = 'Failed to update settings. Please try again.';
                error_log("Account settings error: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html class="font-sans">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Account Settings') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css?family=Nunito:700,800,400&display=swap" rel="stylesheet">
    <style>
      body { font-family: 'Nunito', 'Inter', sans-serif; background: linear-gradient(135deg, #f9fafc 0%, #fbe7f9 60%, #efeaff 100%); min-height: 100vh;}
      .glass-card { background: rgba(255,255,255,0.92); backdrop-filter: blur(16px); border-radius: 1.25rem; box-shadow: 0 8px 40px 0 rgba(200,175,220,0.12); border: 1px solid #f3e6ff33;}
      .btn-primary {background: linear-gradient(90deg,#d86990 0%, #e995b5 100%); color: #fff; font-weight: 600; transition: all 0.3s cubic-bezier(.4,0,.2,1); box-shadow: 0 2px 16px 0 #e995b544; border-radius: 0.75rem; border: none;}
      .btn-primary:hover:not(:disabled) {filter: brightness(1.07); transform: scale(1.025); box-shadow: 0 12px 32px #eac1f4b0;}
      .input-style {background: rgba(255,255,255,0.92); border: 1.5px solid #ece4fa; color: #181830; transition: border 0.2s, box-shadow 0.2s; border-radius: 0.75rem;}
      .input-style:focus {border-color: #d86990; box-shadow: 0 0 0 3px #fbe7f9a0;}
      .alert-error {background-color: #fbe7f9; border-left: 4px solid #ea699b; color: #812a63;}
      .alert-success {background: #b2f2bb; border-left: 4px solid #40c057; color: #21703c;}
      h1, h2, h3, h4, h5 { color: #27273b; }
      .rounded-2xl { border-radius: 1.25rem; }
      .shadow-xl { box-shadow: 0 14px 48px 0 rgba(200,175,220,0.16); }
    </style>
</head>
<body class="font-sans">
<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="min-h-screen pt-6 pb-16">
  <div class="max-w-2xl mx-auto px-4 space-y-10">
    <section class="glass-card p-7 md:p-8 shadow-xl">
      <h1 class="text-3xl font-bold mb-1">Account Settings</h1>
      <!-- Show full name at the top -->
      <h2 class="text-xl font-semibold mb-5">
        Hi, <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
      </h2>

      <?php if ($success): ?>
        <div class="alert-success rounded-lg px-4 py-3 mb-6 text-center font-semibold">
          Your settings have been successfully updated.
        </div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="alert-error rounded-lg px-4 py-3 mb-6">
          <?php foreach ($errors as $error): ?>
            <p><?= htmlspecialchars($error) ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form action="settings.php" method="POST" novalidate autocomplete="off" class="space-y-8">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <!-- Only Email Address field -->
        <div>
          <label for="email" class="block mb-1 text-gray-500 font-medium">Email Address</label>
          <input
            type="email" id="email" name="email" required
            class="input-style w-full px-4 py-3 rounded-2xl focus:outline-none"
            value="<?= htmlspecialchars($user['email']) ?>"
          >
        </div>

        <hr class="border-gray-200">

        <!-- Password Change Section -->
        <div>
          <h2 class="text-lg font-semibold text-gray-800 mb-4">Change Password</h2>
          <p class="text-gray-500 mb-4">Leave blank if you do not want to change your password.</p>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            <div>
              <label for="current_password" class="block mb-1 text-gray-500 font-medium">Current Password</label>
              <input
                type="password" id="current_password" name="current_password" autocomplete="current-password"
                class="input-style w-full px-4 py-3 rounded-2xl focus:outline-none">
            </div>
            <div>
              <label for="new_password" class="block mb-1 text-gray-500 font-medium">New Password</label>
              <input
                type="password" id="new_password" name="new_password" autocomplete="new-password"
                class="input-style w-full px-4 py-3 rounded-2xl focus:outline-none" minlength="8">
            </div>
            <div>
              <label for="confirm_password" class="block mb-1 text-gray-500 font-medium">Confirm New Password</label>
              <input
                type="password" id="confirm_password" name="confirm_password" autocomplete="new-password"
                class="input-style w-full px-4 py-3 rounded-2xl focus:outline-none" minlength="8">
            </div>
          </div>
        </div>

        <button
          type="submit"
          class="btn-primary w-full px-6 py-4 rounded-xl text-base font-semibold transition duration-200 shadow-sm hover:shadow-md"
        >
          Save Changes
        </button>
      </form>
    </section>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
