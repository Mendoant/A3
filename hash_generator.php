<?php

// The password you want to hash (CHANGE THIS!)
$plaintext_password = 'SCM'; 

// Generate the secure hash using the default PHP algorithm (Bcrypt or Argon2)
$hashed_password = password_hash($plaintext_password, PASSWORD_DEFAULT);

echo "Plaintext Password: " . $plaintext_password . "\n";
echo "Hashed Password:    " . $hashed_password . "\n";

?>
