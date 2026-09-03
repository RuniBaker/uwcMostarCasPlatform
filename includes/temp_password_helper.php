<?php
/**
 * Generate a genuinely random one-time password.
 *
 * Deliberately does NOT derive anything from the person's name - that was
 * the source of the bug this replaces (diacritics like čćžđš getting
 * silently stripped instead of transliterated, and multi-word names like
 * "Ana Marija" collapsing unpredictably). A random password sidesteps the
 * problem entirely rather than trying to patch name-handling edge cases.
 *
 * Uses a charset that avoids visually ambiguous characters (0/O, 1/l/I)
 * since these are typically read off a printed sheet or an email and
 * typed in once - legibility matters even though it's one-time-use.
 */
function generateTemporaryPassword() {
    $charset = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $length = 10;
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $charset[random_int(0, strlen($charset) - 1)];
    }

    return $password;
}

/**
 * Create a user with a temporary password.
 *
 * Stores the plaintext password in temp_password_plaintext (in addition to
 * the properly hashed password_hash used for actual authentication) so it
 * can be reliably shown again later - via the export page or an email -
 * without needing to guess or re-derive it. This column gets cleared the
 * moment the user sets their real password (see change_password.php).
 */
function createUserWithTempPassword($conn, $username, $first_name, $last_name, $email, $user_status, $student_id = null) {
    try {
        // Generate temporary password
        $temporary_password = generateTemporaryPassword();
        
        // Hash the temporary password for actual authentication use
        $password_hash = password_hash($temporary_password, PASSWORD_DEFAULT);
        
        // Insert new user with temporary password flag set to 1, and the
        // plaintext password stored alongside the hash for later retrieval
        $stmt = $conn->prepare("
            INSERT INTO users 
                (username, password_hash, temp_password_plaintext, first_name, last_name, email, user_status, student_id, is_temporary_password, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("sssssssi", $username, $password_hash, $temporary_password, $first_name, $last_name, $email, $user_status, $student_id);
        
        if ($stmt->execute()) {
            $new_user_id = $conn->insert_id;
            $stmt->close();
            
            return [
                'success' => true,
                'user_id' => $new_user_id,
                'temporary_password' => $temporary_password,
                'message' => 'User created successfully with temporary password'
            ];
        } else {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
    } catch (Exception $e) {
        error_log("Error in createUserWithTempPassword: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error creating user: ' . $e->getMessage()
        ];
    }
}