<?php
// includes/email_config.php
// Configuration for outgoing email via authenticated SMTP through the
// real casplatform@uwcmostar.ba Google Workspace account.
//
// SETUP STEPS:
// 1. Sign into casplatform@uwcmostar.ba, go to myaccount.google.com/security
// 2. Turn on 2-Step Verification
// 3. Go to myaccount.google.com/apppasswords, create one, copy the
//    16-character password (NOT the account's real login password)
// 4. Paste it below as SMTP_PASSWORD
//
// If step 3 doesn't work even with 2-Step Verification on, your Workspace
// admin has app passwords disabled org-wide and needs to enable them in
// the Admin Console (Security > Authentication > App passwords).

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'casplatform@uwcmostar.ba');
define('SMTP_PASSWORD', 'pnch gtqp zazb apxc');

// The address students/admins will see mail arrive from - should match
// SMTP_USERNAME for a Gmail/Workspace account.
define('EMAIL_FROM_ADDRESS', 'casplatform@uwcmostar.ba');
define('EMAIL_FROM_NAME', 'UWC Mostar CAS Platform');

// Simple rate limit for the self-service transcript button: minimum seconds
// that must pass before the same student can trigger another transcript
// email. Prevents someone repeatedly clicking a classmate's name to spam
// their inbox.
define('TRANSCRIPT_RATE_LIMIT_SECONDS', 300); // 5 minutes