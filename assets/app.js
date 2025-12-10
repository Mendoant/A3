// assets/app.js - small helpers
// Variable to track the user's progress through the sequence
let keySequenceIndex = 0;

// The required sequence: Up, Up, Down, Down, Left, Right, 1
const secretCode = [
    'ArrowUp', 
    'ArrowUp', 
    'ArrowDown', 
    'ArrowDown', 
    'ArrowLeft', 
    'ArrowRight', 
    '1'
];

document.addEventListener('keydown', function(event) {
    // Check if the key pressed matches the current expected key in the sequence
    if (event.key === secretCode[keySequenceIndex]) {
        // Move to the next step
        keySequenceIndex++;

        // Check if the full sequence is complete
        if (keySequenceIndex === secretCode.length) {
            // Redirect to the snake game
            window.location.href = "snake.php";
            
            // Reset index (optional, as page will unload)
            keySequenceIndex = 0;
        }
    } else {
        // If they press the wrong key, reset the sequence.
        // If the wrong key is actually 'Up' (the start of the code), start at 1.
        keySequenceIndex = (event.key === secretCode[0]) ? 1 : 0;
    }
});

document.addEventListener('DOMContentLoaded', function () {
    // Placeholder for future AJAX / interactive features
    // e.g., fetch KPI data via fetch('/api/get_kpis.php?start=...&end=...')
});
