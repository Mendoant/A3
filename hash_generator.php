<?php
// hash_generator.php - PHP 5.4 compatible version using SHA-256

// The password you want to hash (CHANGE THIS!)
$plaintext_password = 'scm123'; 

// Generate the secure hash using SHA-256 (PHP 5.4 compatible)
$hashed_password = hash('sha256', $plaintext_password);

echo "Plaintext Password: " . $plaintext_password . "\n";
echo "Hashed Password:    " . $hashed_password . "\n";
echo "\nNOTE: This uses SHA-256 hashing for PHP 5.4 compatibility.\n";
echo "Make sure your login.php also uses SHA-256 for password verification.\n";
?>
