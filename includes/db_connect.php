<?php
// Database connection settings
define('DB_HOST', 'localhost');
define('DB_USER', 'uwcmosta_admin');
define('DB_PASS', '=Kw-VE.#v?)@=q$@'); // Change this for production
define('DB_NAME', 'uwcmosta_cas_attendance_tracking');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    // Log error to file instead of displaying it (for security)
    error_log("Database connection failed: " . $conn->connect_error);
    
    // Display a user-friendly message
    die("We're experiencing technical difficulties. Please try again later or contact the system administrator.");
}

// Set charset
$conn->set_charset("utf8mb4");

/**
 * Safely close the database connection
 * Call this function when you're done with the database operations
 */
function close_connection() {
    global $conn;
    if ($conn) {
        $conn->close();
    }
}

/**
 * Helper function to handle SQL errors
 * Logs the error and returns a user-friendly message
 */
function handle_sql_error($sql, $error) {
    // Log the error with the query for debugging
    error_log("SQL Error: " . $error . " in query: " . $sql);
    
    // Return a generic error message to the user
    return "A database error occurred. Please try again later.";
}

// Register a shutdown function to ensure the connection is closed
register_shutdown_function('close_connection');