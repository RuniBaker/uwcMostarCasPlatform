</div> <!-- End of main content wrapper -->
</div> <!-- End of flex container -->

<!-- Footer -->
<footer class="bg-gray-800 text-white py-4 mt-auto">
    <div class="container mx-auto px-4">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="mb-4 md:mb-0">
                <p>&copy; 2025 UWC Mostar CAS Tracking System</p>
            </div>
            <div class="flex space-x-4">
                <a href="../index.php" class="text-gray-300 hover:text-white">Home</a>
                <a href="../about.php" class="text-gray-300 hover:text-white">About</a>
                <a href="../contact.php" class="text-gray-300 hover:text-white">Contact</a>
            </div>
        </div>
    </div>
</footer>

<!-- Add any global scripts here -->
<script>
    // Close any alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        
        alerts.forEach(function(alert) {
            setTimeout(function() {
                alert.classList.add('opacity-0');
                setTimeout(function() {
                    alert.remove();
                }, 300);
            }, 5000);
        });
    });
</script>