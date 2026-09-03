<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✓ OPcache cleared successfully!<br>";
} else {
    echo "✗ OPcache not available<br>";
}

if (function_exists('apc_clear_cache')) {
    apc_clear_cache();
    echo "✓ APC cache cleared!<br>";
}

echo "<br>Cache flush attempted at " . date('Y-m-d H:i:s');
?>