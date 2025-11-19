<?php
// index.php - Landing page showing group member photos and login form
session_start();

// If already logged in, redirect based on role
if (isset($_SESSION['Username']) && isset($_SESSION['Role'])) {
    if ($_SESSION['Role'] === 'SeniorManager') {
        header('Location: dashboard_erp.php');
        exit;
    } else {
        header('Location: dashboard_scm.php');
        exit;
    }
}

// member images in assets/images/
$group_members = array(
    array('name' => 'Anthony Mendoza', 'img' => 'assets/images/Anthony.jpg'),
    array('name' => 'Aytaj Aslanli',   'img' => 'assets/images/Aytaj.jpg'),
    array('name' => 'Elisa Gonzalez',   'img' => 'assets/images/Elisa.jpg'),
    array('name' => 'Faisal Alkhouri',   'img' => 'assets/images/Faisal.jpg'),
    array('name' => 'Mateusz Lisiecki',   'img' => 'assets/images/Mateusz.jpg'),
    array('name' => 'Zach Byington',   'img' => 'assets/images/Zach.jpg')
);


// Show any login error stored in session
$login_error = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : '';
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
    <h1>Group Project ERP / SCM</h1>
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
                <div class="login-feedback" id="loginFeedback"><?php echo htmlspecialchars($login_error) ?></div>
            <?php else: ?>
                <div class="login-feedback" id="loginFeedback"></div>
            <?php endif; ?>
        </form>
    </aside>
</main>

<script src="assets/app.js"></script>
</body>
</html>
