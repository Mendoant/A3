<?php
// index.php - Landing page showing group member photos and login form

// 1. Load config to start the specific named session (PHP 5.4 compatible)
require_once 'config.php';

// 2. Redirect if already logged in
if (isset($_SESSION['Username']) && isset($_SESSION['Role'])) {
    if ($_SESSION['Role'] === 'SeniorManager') {
        header('Location: erp/dashboard.php');
        exit;
    } else {
        header('Location: scm/dashboard.php');
        exit;
    }
}

// member images setup
$group_members = array(
    array('name' => 'Anthony Mendoza', 'img' => 'assets/images/Anthony.jpg'),
    array('name' => 'Aytaj Aslanli',   'img' => 'assets/images/Aytaj.jpg'),
    array('name' => 'Elisa Gonzalez',   'img' => 'assets/images/Elisa.jpg'),
    array('name' => 'Faisal Alkhouri',   'img' => 'assets/images/Faisal.jpg'),
    array('name' => 'Mateusz Lisiecki',   'img' => 'assets/images/Mateusz.jpg'),
    array('name' => 'Zach Byington',   'img' => 'assets/images/Zach.jpg')
);

// 3. RETRIEVE SESSION ERROR
// Because we included config.php, $_SESSION['login_error'] is now accessible.
$login_error = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : '';

// 4. CLEAR SESSION ERROR
// We remove it so it doesn't persist if the user refreshes the page.
unset($_SESSION['login_error']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Group ERP / SCM - Landing</title>
    <link rel="stylesheet" href="assets/styles.css">
    <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body>
    
<header class="site-header">
    
    <h1>Excalibur Analytics</h1>
</header>

<main class="container">
    <section class="members-grid" aria-label="Group members">
        <?php foreach ($group_members as $m): ?>
            <figure class="member">
                <img src="<?php echo htmlspecialchars($m['img']) ?>" alt="<?php echo htmlspecialchars($m['name']) ?>">
                <figcaption><?php echo htmlspecialchars($m['name']) ?></figcaption>
            </figure>
        <?php endforeach; ?>
    </section>

    <div class="login-wrapper">
        <img src="assets/images/logo.png" alt="Company Logo" class="login-side-logo">
        
        <aside class="login-box" aria-labelledby="loginHeading">
            <form id="loginForm" method="post" action="login.php" autocomplete="off">
                <h2 id="loginHeading">Login</h2>
                <label>
                    Username
                    <input type="text" name="username" required>
                </label>
                <label>
                    Password
                    <input type="password" name="password" required>
                </label>
                <button type="submit" name="action" value="login">login</button>
                
                <?php if ($login_error): ?>
                    <div class="login-feedback" id="loginFeedback" style="color: red; margin-top: 10px;">
                        <?php echo htmlspecialchars($login_error) ?>
                    </div>
                <?php endif; ?>
                
            </form>
        </aside>
        
        <img src="assets/images/logo.png" alt="Company Logo" class="login-side-logo">
    </div>
</main>

<script src="assets/app.js"></script>
</body>
</html>
