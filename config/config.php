<?php
/**
 * FileBridge configuration.
 *
 * Every value has a working default, so a fresh install runs as-is.
 * To keep machine specific settings out of version control, copy the keys you
 * want to change into config/config.local.php - that file is git-ignored and
 * its values are merged over the ones below.
 *
 *     <?php
 *     return ['local_roots' => ['/var/www'], 'default_theme' => 'light'];
 */
return [
    // Display name shown in the top bar.
    'app_name' => 'FileBridge',

    // Directories the built-in "Local" connection may browse. Anything outside
    // is refused, symlinks included. Leave empty to allow the directory that
    // contains FileBridge (usually the web root).
    'local_roots' => ['/mnt/Server/htdocs'],

    // Transfer engine
    'chunk_size' => 262144, // 256 KB per read/write chunk
    // Files copied in parallel per job (1 = one at a time). This is only the
    // default: every connection can carry its own limit in its edit dialog, so
    // a server that allows two sessions and one that allows ten can share this
    // install without anyone editing a config file. The slower side of a
    // transfer wins - see TransferManager::workerCount().
    'transfer_workers' => 4,
    'max_edit_size' => 2097152, // 2 MB - largest file openable in the text editor
    'max_upload_size' => 0, // 0 = use PHP's upload_max_filesize
    'job_retention' => 86400, // seconds to keep finished jobs on disk
    'progress_interval' => 0.25, // seconds between progress writes

    // Path to the PHP CLI binary used by the background worker.
    // Empty = detect automatically (matches the running PHP version first).
    'php_binary' => '',

    // Connection defaults
    'timeout' => 20,
    'keepalive' => 30,

    // Absolute path to the libsodium master key that encrypts stored
    // credentials. Empty = config/key.php. Move it to a filesystem that honours
    // chmod if the app directory does not (NTFS/exFAT mounts force 0777).
    // The FILEBRIDGE_KEY_FILE environment variable overrides this.
    'key_file' => '',

    // Security
    'session_lifetime' => 28800, // 8 hours
    'login_max_attempts' => 5,
    'login_lockout' => 900, // 15 minutes
    'ip_allowlist' => [], // e.g. ['127.0.0.1', '192.168.1.0/24']; empty = allow all
    'verify_host_key' => true, // trust-on-first-use SSH fingerprint pinning

    // UI defaults
    'default_language' => 'auto', // auto | en | th - auto follows the browser
    'default_theme' => 'dark', // dark | light
    'show_hidden' => false,
    'date_format' => 'Y-m-d H:i'
];
