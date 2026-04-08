<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$page_title = "Our Story | " . SITE_NAME;
$page_description = "Discover the ElegantDresses journey—mission, inspiration, ethics, and our commitment to quality, affordability, and sustainable fashion.";

// Global header (meta/navigation)
include __DIR__ . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($page_title) ?></title>
  <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css?family=Nunito:700,800,400&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Nunito', 'Inter', sans-serif;
      background: linear-gradient(135deg, #f9fafc 0%, #fbe7f9 60%, #efeaff 100%);
      min-height: 100vh;
      color: #4a4a4a;
    }
    .glass-card {
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(12px);
      border-radius: 1.25rem;
      border: 1px solid rgba(255, 255, 255, 0.6);
      box-shadow: 0 8px 32px 0 rgba(200, 175, 220, 0.1);
    }
    /* Slimmer Button Gradient */
    .btn-primary {
        background: linear-gradient(90deg, #d86990 0%, #e995b5 100%);
        color: #fff;
        transition: all 0.3s cubic-bezier(.4, 0, .2, 1);
        box-shadow: 0 2px 10px 0 #e995b544;
        border: none;
    }
    .btn-primary:hover {
        filter: brightness(1.05);
        transform: translateY(-1px);
        box-shadow: 0 4px 15px 0 #e995b566;
    }
    /* Soft text gradient for headings */
    .text-gradient {
        background: linear-gradient(90deg, #a53860, #d86990);
        background-clip: text;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
  </style>
</head>
<body class="font-sans antialiased">
<main class="pb-16">
  <section class="flex justify-center pt-16 pb-10">
    <div class="text-center px-4">
      <h1 class="text-4xl md:text-5xl font-bold mb-3 tracking-tight text-gray-800">Our Story</h1>
      <p class="text-lg text-[#d86990] font-medium">Crafting Elegance, One Dress at a Time</p>
      <div class="w-24 h-1 bg-gradient-to-r from-pink-200 to-purple-200 mx-auto mt-6 rounded-full"></div>
    </div>
  </section>

  <section class="py-8">
    <div class="container px-4 max-w-4xl mx-auto">
      <div class="glass-card p-8 md:p-10">
        <h2 class="text-2xl font-bold text-center mb-10 text-gray-800">About the Company</h2>
        
        <div class="space-y-10">
            <article class="flex flex-col md:flex-row gap-4 md:gap-8 items-start">
                <div class="w-12 h-12 rounded-full bg-pink-50 flex items-center justify-center flex-shrink-0 text-2xl">✨</div>
                <div>
                    <h3 class="text-lg font-bold mb-2 text-gray-800">How We Began</h3>
                    <p class="text-gray-600 leading-relaxed text-sm md:text-base">
                        ElegantDresses was founded in 2015 with a simple yet powerful idea—to help every woman feel confident, beautiful, and celebrated in elegant fashion without compromise.
                    </p>
                </div>
            </article>

            <article class="flex flex-col md:flex-row gap-4 md:gap-8 items-start">
                 <div class="w-12 h-12 rounded-full bg-purple-50 flex items-center justify-center flex-shrink-0 text-2xl">🎯</div>
                <div>
                    <h3 class="text-lg font-bold mb-2 text-gray-800">Our Mission</h3>
                    <p class="text-gray-600 leading-relaxed text-sm md:text-base">
                        Our mission is to deliver elegant, affordable fashion for all. From day one, we've believed that true elegance belongs not to the select few, but to every woman who values confidence and authenticity.
                    </p>
                </div>
            </article>

            <article class="flex flex-col md:flex-row gap-4 md:gap-8 items-start">
                 <div class="w-12 h-12 rounded-full bg-green-50 flex items-center justify-center flex-shrink-0 text-2xl">🌿</div>
                <div>
                    <h3 class="text-lg font-bold mb-2 text-gray-800">Quality & Customer Care</h3>
                    <p class="text-gray-600 leading-relaxed text-sm md:text-base">
                        Our journey is shaped by you—our customers. We listen, adapt, and strive to set new standards for satisfaction.
                    </p>
                </div>
            </article>

            <article class="flex flex-col md:flex-row gap-4 md:gap-8 items-start">
                 <div class="w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center flex-shrink-0 text-2xl">🚀</div>
                <div>
                    <h3 class="text-lg font-bold mb-2 text-gray-800">Growth, Ethics & Inspiration</h3>
                    <p class="text-gray-600 leading-relaxed text-sm md:text-base">
                        As we've grown into a global women's fashion destination, our inspiration remains steadfast: empower women with style that makes a difference.
                    </p>
                </div>
            </article>
        </div>
      </div>
    </div>
  </section>

  <section class="py-12">
    <div class="container px-4 max-w-5xl mx-auto">
      <h2 class="text-2xl font-bold text-center mb-10 text-gray-800">The Journey</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        
        <div class="glass-card p-6 hover:shadow-lg transition-all duration-300">
          <h3 class="text-lg font-bold text-[#d86990] mb-3">Inspiration</h3>
          <p class="text-sm text-gray-600 leading-relaxed">Each collection draws inspiration from the confidence and spirit of modern women.</p>
        </div>

        <div class="glass-card p-6 hover:shadow-lg transition-all duration-300">
          <h3 class="text-lg font-bold text-[#d86990] mb-3">Growth</h3>
          <p class="text-sm text-gray-600 leading-relaxed">What started as a small, passionate project is now a thriving brand loved by women around the world.</p>
        </div>

        <div class="glass-card p-6 hover:shadow-lg transition-all duration-300">
          <h3 class="text-lg font-bold text-[#d86990] mb-3">Commitment</h3>
          <p class="text-sm text-gray-600 leading-relaxed">We champion ethical manufacturing, sustainable materials, and fair partnerships.</p>
        </div>

      </div>
    </div>
  </section>

  <section class="py-8">
    <div class="container px-4 max-w-4xl mx-auto text-center">
      <h2 class="text-2xl font-bold mb-8 text-gray-800">Our Values</h2>
      <div class="flex flex-wrap justify-center gap-4">
        <span class="px-5 py-2 bg-white border border-pink-100 rounded-full text-sm font-semibold text-gray-600 shadow-sm flex items-center gap-2">
           <span class="w-2 h-2 rounded-full bg-pink-400"></span> Quality & Craftsmanship
        </span>
        <span class="px-5 py-2 bg-white border border-green-100 rounded-full text-sm font-semibold text-gray-600 shadow-sm flex items-center gap-2">
           <span class="w-2 h-2 rounded-full bg-green-400"></span> Sustainability
        </span>
        <span class="px-5 py-2 bg-white border border-blue-100 rounded-full text-sm font-semibold text-gray-600 shadow-sm flex items-center gap-2">
           <span class="w-2 h-2 rounded-full bg-blue-300"></span> Customer Joy
        </span>
        <span class="px-5 py-2 bg-white border border-red-100 rounded-full text-sm font-semibold text-gray-600 shadow-sm flex items-center gap-2">
           <span class="w-2 h-2 rounded-full bg-red-300"></span> Ethical Practices
        </span>
      </div>
    </div>
  </section>

  <section class="py-12 mt-8">
    <div class="container px-4 max-w-xl mx-auto">
        <div class="glass-card p-8 text-center">
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Stay in Touch</h2>
            <p class="text-sm text-gray-500 mb-6">Get updates on new collections, exclusive offers, and style tips.</p>

            <form method="post" action="<?= BASE_URL ?>/subscribe.php" class="flex flex-col sm:flex-row gap-3">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= htmlspecialchars($_SESSION[CSRF_TOKEN_NAME] ?? '') ?>">
                
                <input
                type="email"
                name="email"
                placeholder="Your email address"
                required
                class="flex-1 px-5 py-3 rounded-xl border border-gray-200 bg-white/50 focus:outline-none focus:ring-2 focus:ring-pink-200 focus:bg-white transition-all text-sm shadow-inner"
                >

                <button
                type="submit"
                class="btn-primary px-8 py-3 rounded-xl font-semibold text-sm shadow-md whitespace-nowrap"
                >
                Subscribe
                </button>
            </form>
        </div>
    </div>
  </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>