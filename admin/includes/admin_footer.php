            </div>
        </main>
    </div>

    <!-- Loading overlay -->
    <div id="loadingOverlay" class="fixed inset-0 bg-gray-600 bg-opacity-50 z-50 hidden">
        <div class="flex items-center justify-center h-full">
            <div class="bg-white p-4 rounded-lg">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
            </div>
        </div>
    </div>

    <!-- Global JavaScript -->
    <script>
        // Auto-hide flash messages
        setTimeout(function() {
            const messages = document.querySelectorAll('.bg-green-50, .bg-red-50, .bg-yellow-50');
            messages.forEach(function(message) {
                message.style.transition = 'opacity 0.5s';
                message.style.opacity = '0';
                setTimeout(function() {
                    message.remove();
                }, 500);
            });
        }, 5000);

        // Show loading overlay for form submissions
        document.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function() {
                document.getElementById('loadingOverlay').classList.remove('hidden');
            });
        });

        // Confirm dangerous actions
        document.querySelectorAll('[onclick*="delete"], [onclick*="remove"]').forEach(function(element) {
            element.addEventListener('click', function(e) {
                if (!confirm('Are you sure? This action cannot be undone.')) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            });
        });

        // Auto-resize textareas
        document.querySelectorAll('textarea').forEach(function(textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        });

        // Format numbers in currency inputs
        document.querySelectorAll('input[type="number"][step="0.01"]').forEach(function(input) {
            input.addEventListener('blur', function() {
                if (this.value) {
                    this.value = parseFloat(this.value).toFixed(2);
                }
            });
        });
    </script>
</body>
</html>