<?php
// /auth/profile.php

session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Redirect guests
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = false;

// Fetch user details (add address columns!)
$stmt = $pdo->prepare("SELECT first_name, last_name, email, address_1, city, state, country, postal_code, phone, created_at FROM " . DB_PREFIX . "users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Handle profile update (if POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    $address_1   = trim($_POST['address_1'] ?? '');
    $city        = trim($_POST['city'] ?? '');
    $state       = trim($_POST['state'] ?? '');
    $country     = trim($_POST['country'] ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');

    // Validate inputs
    if ($first_name === '' || strlen($first_name) < 2) $errors[] = 'First name is required.';
    if ($last_name === '' || strlen($last_name) < 2) $errors[] = 'Last name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if ($address_1 === '' || strlen($address_1) < 3) $errors[] = 'Address Line 1 is required.';
    if ($city === '' || strlen($city) < 2) $errors[] = 'City is required.';
    if ($state === '' || strlen($state) < 2) $errors[] = 'State is required.';
    if ($country === '' || strlen($country) < 2) $errors[] = 'Country is required.';
    if ($postal_code === '') $errors[] = 'Postal code is required.';
    if ($phone === '' || strlen($phone) < 5) $errors[] = 'Phone number is required.';

    if (empty($errors)) {
        try {
            // Also update address!
            $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "users SET 
                first_name = ?, last_name = ?, email = ?, address_1 = ?, city = ?, state = ?, country = ?, postal_code = ?, phone = ?
                WHERE id = ?");
            $stmt->execute([
                $first_name,
                $last_name,
                $email,
                $address_1,
                $city,
                $state,
                $country,
                $postal_code,
                $phone,
                $user_id
            ]);
            $success = true;
            $_SESSION['first_name'] = $first_name;
            // Refetch updated user info for the form
            $user['first_name'] = $first_name;
            $user['last_name'] = $last_name;
            $user['email'] = $email;
            $user['address_1'] = $address_1;
            $user['city'] = $city;
            $user['state'] = $state;
            $user['country'] = $country;
            $user['postal_code'] = $postal_code;
            $user['phone'] = $phone;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'This email is already in use.';
            } else {
                $errors[] = 'An error occurred. Please try again.';
                error_log("Profile update failed: " . $e->getMessage());
            }
        }
    }
}

// Fetch user orders
$orderStmt = $pdo->prepare("SELECT id, created_at, status FROM " . DB_PREFIX . "orders WHERE user_id = ? ORDER BY created_at DESC");
$orderStmt->execute([$user_id]);
$orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>
<!DOCTYPE html>
<html lang="en" class="font-sans">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css?family=Nunito:700,800,400&display=swap" rel="stylesheet">
    <style>
      body {
        font-family: 'Nunito', 'Inter', sans-serif;
        background: linear-gradient(135deg, #f9fafc 0%, #fbe7f9 60%, #efeaff 100%);
        min-height: 100vh;
      }
      .glass-card {
        background: rgba(255,255,255,0.92);
        backdrop-filter: blur(16px);
        border-radius: 1.25rem;
        box-shadow: 0 8px 40px 0 rgba(200,175,220,0.12);
        border: 1px solid #f3e6ff33;
      }
      .btn-primary {
        background: linear-gradient(90deg, #d86990 0%, #e995b5 100%);
        color: #fff;
        font-weight: 600;
        transition: all 0.3s cubic-bezier(.4,0,.2,1);
        box-shadow: 0 2px 16px 0 #e995b544;
        border-radius: 0.75rem;
        border: none;
      }
      .btn-primary:hover:not(:disabled) {
        filter: brightness(1.08);
        transform: scale(1.025);
        box-shadow: 0 10px 32px #eac1f4b0;
      }
      .input-style {
        background: rgba(255,255,255,0.92);
        border: 1.5px solid #ece4fa;
        color: #181830;
        transition: border 0.2s, box-shadow 0.2s;
        border-radius: 0.75rem;
      }
      .input-style:focus {
        border-color: #d86990;
        box-shadow: 0 0 0 2px #fbe7f9a0;
      }
      .alert-success {
        background: #b2f2bb;
        border-left: 4px solid #40c057;
        color: #21703c;
      }
      .alert-error {
        background-color: #fbe7f9;
        border-left: 4px solid #ea699b;
        color: #812a63;
      }
      h1, h2, h3, h4, h5 { color: #27273b; }
    </style>
</head>
<body>
<main class="min-h-screen pt-6 pb-16">
  <div class="max-w-3xl mx-auto px-4 space-y-10">

    <!-- Profile card -->
    <section class="glass-card p-7 shadow-xl mb-10">
      <div class="mb-7 flex items-center gap-4">
        <span class="inline-flex items-center justify-center rounded-full bg-[#E7F5FF] w-16 h-16 shadow">
          <!-- You can use your own icon here -->
          <svg width="40" height="40" fill="none"><circle cx="20" cy="20" r="20" fill="#B2F2BB"/><path d="M20 18a5 5 0 100-10 5 5 0 000 10zM10 32c0-5.523 4.477-10 10-10s10 4.477 10 10" stroke="#6C757D" stroke-width="2"/></svg>
        </span>
        <div>
          <h1 class="text-2xl font-bold"><?=htmlspecialchars($user['first_name']." ".$user['last_name'])?></h1>
          <div class="text-gray-400 text-sm">Member since <?=date('F Y', strtotime($user['created_at']))?></div>
        </div>
      </div>
      <hr class="mb-5 border-[#e9ecef]">

      <?php if ($success): ?>
        <div class="alert-success rounded-lg px-4 py-3 mb-6 text-center font-semibold">
          Profile updated successfully.
        </div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="alert-error rounded-lg px-4 py-3 mb-6">
          <?php foreach ($errors as $error): ?>
              <div><?=htmlspecialchars($error)?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form action="profile.php" method="POST" class="space-y-8 max-w-xl">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
          <div>
            <label for="first_name" class="block mb-1 text-gray-500 font-medium">First Name</label>
            <input type="text" id="first_name" name="first_name" required
                   class="input-style w-full px-4 py-3 focus:outline-none"
                   value="<?=htmlspecialchars($_POST['first_name'] ?? $user['first_name'])?>">
          </div>
          <div>
            <label for="last_name" class="block mb-1 text-gray-500 font-medium">Last Name</label>
            <input type="text" id="last_name" name="last_name" required
                   class="input-style w-full px-4 py-3 focus:outline-none"
                   value="<?=htmlspecialchars($_POST['last_name'] ?? $user['last_name'])?>">
          </div>
          <div class="sm:col-span-2">
            <label for="email" class="block mb-1 text-gray-500 font-medium">Email Address</label>
            <input type="email" id="email" name="email" required
                   class="input-style w-full px-4 py-3 focus:outline-none"
                   value="<?=htmlspecialchars($_POST['email'] ?? $user['email'])?>">
          </div>
          <div class="sm:col-span-2">
            <label for="address_1" class="block mb-1 text-gray-500 font-medium">Address Line 1</label>
            <input type="text" id="address_1" name="address_1" required
                   class="input-style w-full px-4 py-3 focus:outline-none"
                   value="<?=htmlspecialchars($_POST['address_1'] ?? $user['address_1'] ?? '')?>">
          </div>
          <div>
            <label for="city" class="block mb-1 text-gray-500 font-medium">City</label>
            <input type="text" id="city" name="city" required
                   class="input-style w-full px-4 py-3 focus:outline-none"
                   value="<?=htmlspecialchars($_POST['city'] ?? $user['city'] ?? '')?>">
          </div>
          <div>
            <label for="state" class="block mb-1 text-gray-500 font-medium">State/Province</label>
            <input type="text" id="state" name="state" required
                   class="input-style w-full px-4 py-3 focus:outline-none"
                   value="<?=htmlspecialchars($_POST['state'] ?? $user['state'] ?? '')?>">
          </div>
          <div>
            <label for="country" class="block mb-1 text-gray-500 font-medium">Country</label>
            <input type="text" id="country" name="country" required
                   class="input-style w-full px-4 py-3 focus:outline-none"
                   value="<?=htmlspecialchars($_POST['country'] ?? $user['country'] ?? '')?>">
          </div>
          <div>
            <label for="postal_code" class="block mb-1 text-gray-500 font-medium">Postal Code</label>
            <input type="text" id="postal_code" name="postal_code" required
                   class="input-style w-full px-4 py-3 focus:outline-none"
                   value="<?=htmlspecialchars($_POST['postal_code'] ?? $user['postal_code'] ?? '')?>">
          </div>
          <div class="sm:col-span-2">
            <label for="phone" class="block mb-1 text-gray-500 font-medium">Phone Number</label>
            <input type="text" id="phone" name="phone" required
                   class="input-style w-full px-4 py-3 focus:outline-none"
                   value="<?=htmlspecialchars($_POST['phone'] ?? $user['phone'] ?? '')?>">
          </div>
        </div>
        <div class="flex gap-3">
          <button type="submit"
            class="btn-primary px-7 py-3 rounded-xl shadow font-bold text-base transition hover:-translate-y-1">
            Update Profile
          </button>
          <a href="logout.php" class="ml-auto underline text-gray-400 hover:text-[#d86990] font-medium px-2 py-3">Logout</a>
        </div>
      </form>
    </section>

    <!-- Order history (unchanged, but improved look) -->
    <section class="glass-card p-7 shadow">
      <h2 class="text-lg font-bold mb-5 text-gray-900">My Orders</h2>
      <?php if (empty($orders)): ?>
        <div class="py-8 text-center text-gray-400">
          <p>You have not placed any orders yet.</p>
<a href="<?php echo BASE_URL; ?>/products/product_list.php"
   class="btn-primary inline-block mt-5 px-6 py-3 rounded-xl font-semibold shadow hover:-translate-y-1 transition">
   Shop Now
</a>
        </div>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="min-w-full border-0 text-left">
            <thead>
              <tr class="text-gray-500 bg-[#F8F9FA]">
                <th class="px-4 py-2 rounded-tl-xl">Order #</th>
                <th class="px-4 py-2">Date</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2 rounded-tr-xl">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($orders as $o): ?>
              <tr class="border-b border-[#DEE2E6] hover:bg-[#F8F9FA]">
                <td class="px-4 py-3 font-medium text-gray-800">#<?=htmlspecialchars($o['id'])?></td>
                <td class="px-4 py-3"><?=date('d M Y', strtotime($o['created_at']))?></td>
                <td class="px-4 py-3">
                  <span class="inline-block rounded-full px-3 py-1 text-xs font-semibold 
                  <?php
                    switch(strtolower($o['status'] ?? '')) {
                      case 'pending': echo 'bg-[#FFF3CD] text-gray-500'; break;
                      case 'shipped': echo 'bg-[#E7F5FF] text-[#339AF0]'; break;
                      case 'delivered': echo 'bg-[#B2F2BB] text-[#21a179]'; break;
                      case 'cancelled': echo 'bg-[#fff0f3] text-[#B02A37]'; break;
                      default: echo 'bg-[#F8F9FA] text-gray-500';
                    }
                  ?>">
                    <?=htmlspecialchars(ucfirst($o['status'] ?? 'Processing'))?>
                  </span>
                </td>
                <td class="px-4 py-3">
                  <a href="/cart/payment_success.php?order=<?=intval($o['id'])?>"
                     class="text-[#d86990] underline font-medium hover:text-[#e995b5]">View</a>
                </td>
              </tr>
              <?php endforeach;?>
            </tbody>
          </table>
        </div>
      <?php endif;?>
    </section>
  </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
