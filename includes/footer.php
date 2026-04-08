<?php
/**
 * Global Footer Component - Updated Version
 * Dynamic Ecommerce Website - Women's Dresses
 * 
 * Clean, compact footer with essential links only
 * Mobile-optimized with centered policy links
 * 
 * @author Your Name
 * @version 2.1
 * @since 2025-01-31
 */

// Get current year for copyright
$current_year = date('Y');
?>

<!-- Footer -->
<footer class="bg-white border-t border-border-light mt-auto">
    <!-- Main Footer Content -->
    <div class="container mx-auto px-4 py-8 lg:py-12">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            
            <!-- Company Info -->
            <div class="lg:col-span-1">
                <!-- Logo -->
                <div class="flex items-center mb-4">
                    <div class="w-8 h-8 logo-gradient rounded-lg flex items-center justify-center mr-3">
                        <span class="text-white font-bold text-sm">E</span>
                    </div>
                    <span class="text-lg font-poppins font-bold text-text-primary">
                        <?php echo SITE_NAME; ?>
                    </span>
                </div>
                
                <p class="text-text-secondary text-sm mb-4 leading-relaxed">
                    Elegant women's dresses for every occasion. Quality fashion that makes you feel confident and beautiful.
                </p>
                
                <!-- Social Media Links -->
                <div class="flex space-x-4">
                    <a href="#" class="w-8 h-8 bg-gray-light hover:bg-accent-pink/20 rounded-lg flex items-center justify-center text-text-secondary hover:text-accent-pink transition-all duration-200" aria-label="Facebook">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                        </svg>
                    </a>
                    <a href="#" class="w-8 h-8 bg-gray-light hover:bg-accent-pink/20 rounded-lg flex items-center justify-center text-text-secondary hover:text-accent-pink transition-all duration-200" aria-label="Instagram">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 6.62 5.367 11.987 11.988 11.987c6.62 0 11.987-5.367 11.987-11.987C24.004 5.367 18.637.001 12.017.001zM8.449 16.988c-1.297 0-2.448-.49-3.326-1.297-.878-.808-1.297-1.959-1.297-3.256 0-1.297.42-2.448 1.297-3.326.878-.878 2.029-1.297 3.326-1.297 1.297 0 2.448.42 3.326 1.297.878.878 1.297 2.029 1.297 3.326 0 1.297-.42 2.448-1.297 3.256-.878.807-2.029 1.297-3.326 1.297z"/>
                        </svg>
                    </a>
                    <a href="#" class="w-8 h-8 bg-gray-light hover:bg-accent-pink/20 rounded-lg flex items-center justify-center text-text-secondary hover:text-accent-pink transition-all duration-200" aria-label="Twitter">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/>
                        </svg>
                    </a>
                    <a href="#" class="w-8 h-8 bg-gray-light hover:bg-accent-pink/20 rounded-lg flex items-center justify-center text-text-secondary hover:text-accent-pink transition-all duration-200" aria-label="Pinterest">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.174-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.663.967-2.911 2.168-2.911 1.024 0 1.518.769 1.518 1.688 0 1.029-.653 2.567-.992 3.992-.285 1.193.6 2.165 1.775 2.165 2.128 0 3.768-2.245 3.768-5.487 0-2.861-2.063-4.869-5.008-4.869-3.41 0-5.409 2.562-5.409 5.199 0 1.033.394 2.143.889 2.741.099.12.112.225.085.345-.09.375-.293 1.199-.334 1.363-.053.225-.172.271-.402.165-1.495-.69-2.433-2.878-2.433-4.646 0-3.776 2.748-7.252 7.92-7.252 4.158 0 7.392 2.967 7.392 6.923 0 4.135-2.607 7.462-6.233 7.462-1.214 0-2.357-.629-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24.009 12.017 24.009c6.624 0 11.99-5.367 11.99-11.988C24.007 5.367 18.641.001 12.017.001z"/>
                        </svg>
                    </a>
                </div>
            </div>
            
            <!-- Quick Links - Mobile: Side by Side, Desktop: Separate Columns -->
            <div class="lg:col-span-3">
                <!-- Mobile: 2 Column Grid, Desktop: 3 Column Grid -->
                <div class="grid grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
                    
                    <!-- Shop Links -->
                    <div>
                        <h3 class="font-semibold text-text-primary mb-3 text-sm uppercase tracking-wide">Shop</h3>
                        <ul class="space-y-2">
                            <li>
                                <a href="<?php echo BASE_URL; ?>/products/product_list.php" 
                                   class="text-text-secondary hover:text-text-primary text-sm transition-colors duration-200">
                                    All Dresses
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo BASE_URL; ?>/products/product_list.php?category=new" 
                                   class="text-text-secondary hover:text-text-primary text-sm transition-colors duration-200">
                                    New Arrivals
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo BASE_URL; ?>/products/product_list.php?sale=1" 
                                   class="text-text-secondary hover:text-text-primary text-sm transition-colors duration-200">
                                    Sale
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo BASE_URL; ?>/products/product_list.php?featured=1" 
                                   class="text-text-secondary hover:text-text-primary text-sm transition-colors duration-200">
                                    Featured
                                </a>
                            </li>
                        </ul>
                    </div>
                    
                    <!-- Support Links -->
                    <div>
                        <h3 class="font-semibold text-text-primary mb-3 text-sm uppercase tracking-wide">Support</h3>
                        <ul class="space-y-2">
                            <li>
                                <a href="<?php echo BASE_URL; ?>/contact.php" 
                                   class="text-text-secondary hover:text-text-primary text-sm transition-colors duration-200">
                                    Contact Us
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo BASE_URL; ?>/help/faq.php" 
                                   class="text-text-secondary hover:text-text-primary text-sm transition-colors duration-200">
                                    FAQ
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo BASE_URL; ?>/help/shipping.php" 
                                   class="text-text-secondary hover:text-text-primary text-sm transition-colors duration-200">
                                    Shipping Info
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo BASE_URL; ?>/help/returns.php" 
                                   class="text-text-secondary hover:text-text-primary text-sm transition-colors duration-200">
                                    Returns
                                </a>
                            </li>
                        </ul>
                    </div>
                    
                    <!-- Company Links - Hidden on Mobile, Shown on Desktop -->
                    <div class="hidden lg:block">
                        <h3 class="font-semibold text-text-primary mb-3 text-sm uppercase tracking-wide">Company</h3>
                        <ul class="space-y-2">
                            <li>
                                <a href="<?php echo BASE_URL; ?>/about.php" 
                                   class="text-text-secondary hover:text-text-primary text-sm transition-colors duration-200">
                                    About Us
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo BASE_URL; ?>/privacy.php" 
                                   class="text-text-secondary hover:text-text-primary text-sm transition-colors duration-200">
                                    Privacy Policy
                                </a>
                            </li>
                            <li>
                                <a href="<?php echo BASE_URL; ?>/terms.php" 
                                   class="text-text-secondary hover:text-text-primary text-sm transition-colors duration-200">
                                    Terms of Service
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
                
                <!-- Mobile: Company Links in Row Format - NOW CENTERED -->
                <div class="lg:hidden mt-6 pt-4 border-t border-border-light">
                    <div class="flex flex-wrap justify-center gap-4 text-xs">
                        <a href="<?php echo BASE_URL; ?>/about.php" 
                           class="text-text-secondary hover:text-text-primary transition-colors duration-200">
                            About
                        </a>
                        <span class="text-border-light">•</span>
                        <a href="<?php echo BASE_URL; ?>/privacy.php" 
                           class="text-text-secondary hover:text-text-primary transition-colors duration-200">
                            Privacy
                        </a>
                        <span class="text-border-light">•</span>
                        <a href="<?php echo BASE_URL; ?>/terms.php" 
                           class="text-text-secondary hover:text-text-primary transition-colors duration-200">
                            Terms
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Footer Bottom - REMOVED PAYMENT METHODS -->
    <div class="border-t border-border-light bg-gray-light/30">
        <div class="container mx-auto px-4 py-4">
            <div class="flex justify-center">
                <!-- Copyright - Centered -->
                <div class="text-sm text-text-secondary text-center">
                    © <?php echo $current_year; ?> <?php echo SITE_NAME; ?>. All rights reserved.
                </div>
            </div>
        </div>
    </div>
</footer>

<!-- Back to Top Button -->
<button id="back-to-top" 
        class="fixed bottom-6 right-6 w-12 h-12 bg-gradient-to-r from-accent-pink to-accent-lavender text-text-primary rounded-full shadow-lg hover:shadow-xl transform hover:scale-110 transition-all duration-300 opacity-0 invisible z-40"
        aria-label="Back to top">
    <svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path>
    </svg>
</button>

<style>
/* Additional footer styles */
.logo-gradient {
    background: linear-gradient(135deg, #F8BBD9, #E7F5FF);
}

/* Smooth hover transitions */
footer a {
    position: relative;
}

footer a::before {
    content: '';
    position: absolute;
    width: 0;
    height: 1px;
    bottom: -2px;
    left: 0;
    background: linear-gradient(90deg, #F8BBD9, #E7F5FF);
    transition: width 0.3s ease;
}

footer a:hover::before {
    width: 100%;
}

/* Back to top button animations */
#back-to-top.show {
    opacity: 1;
    visibility: visible;
}

/* Mobile optimizations */
@media (max-width: 768px) {
    footer h3 {
        font-size: 0.875rem;
        margin-bottom: 0.75rem;
    }
    
    footer ul li {
        margin-bottom: 0.5rem;
    }
    
    footer ul li a {
        font-size: 0.875rem;
    }
}
</style>

<script>
// Back to top functionality
document.addEventListener('DOMContentLoaded', function() {
    const backToTopButton = document.getElementById('back-to-top');
    
    // Show/hide back to top button based on scroll position
    window.addEventListener('scroll', function() {
        if (window.pageYOffset > 300) {
            backToTopButton.classList.add('show');
        } else {
            backToTopButton.classList.remove('show');
        }
    });
    
    // Smooth scroll to top when clicked
    backToTopButton.addEventListener('click', function() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
});

// Footer link tracking (optional - for analytics)
document.addEventListener('DOMContentLoaded', function() {
    const footerLinks = document.querySelectorAll('footer a');
    
    footerLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            // Add analytics tracking here if needed
            const linkText = this.textContent.trim();
            const linkHref = this.getAttribute('href');
            
            // Example: Track with Google Analytics
            // gtag('event', 'click', {
            //     event_category: 'Footer',
            //     event_label: linkText,
            //     value: linkHref
            // });
        });
    });
});

console.log('✨ Footer loaded successfully!');
</script>

<!-- Close body and html tags -->
</body>
</html>