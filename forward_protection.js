//Detect back button and logout
window.addEventListener('pageshow', function(event) {
    if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
        // Page was loaded from cache (back/forward button was used)
        window.location.href = '../logout.php';
    }
});// Also prevent using back button with history manipulation
history.pushState(null, null, location.href);
window.onpopstate = function () {
    window.location.href = '../logout.php';
};