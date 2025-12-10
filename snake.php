<?php
// snake.php - A retro Snake game easter egg
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retro Snake</title>
    <style>
        body {
            background-color: #202124; /* Dark gray background */
            color: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            font-family: 'Courier New', Courier, monospace;
        }
        h1 {
            margin-top: 0;
            text-shadow: 2px 2px #000;
        }
        canvas {
            border: 2px solid #555;
            background-color: #000;
            box-shadow: 0 0 20px rgba(0,0,0,0.5);
        }
        .score-board {
            margin-bottom: 10px;
            font-size: 20px;
        }
        .controls {
            margin-top: 15px;
            color: #888;
            font-size: 14px;
        }
        .back-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            color: #888;
            text-decoration: none;
            border: 1px solid #555;
            padding: 5px 10px;
            border-radius: 4px;
            transition: all 0.2s;
        }
        .back-btn:hover {
            color: #fff;
            border-color: #fff;
        }
    </style>
</head>
<body>

    <a href="index.php" class="back-btn">&larr; Back to Login</a>

    <h1>SNAKE</h1>
    <div class="score-board">Score: <span id="score">0</span></div>
    
    <canvas id="gameCanvas" width="400" height="400"></canvas>
    
    <div class="controls">Use Arrow Keys to Move</div>

    <script>
        window.onload = function() {
            canvas = document.getElementById("gameCanvas");
            ctx = canvas.getContext("2d");
            document.addEventListener("keydown", keyPush);
            setInterval(game, 1000 / 15); // Run game at 15 frames per second
        }

        // Game State Variables
        px = py = 10;   // Player Position (starts in middle of 20x20 grid)
        gs = 20;        // Grid Size (size of each tile in pixels)
        tc = 20;        // Tile Count (number of tiles per row/col)
        ax = ay = 15;   // Apple Position
        xv = yv = 0;    // X and Y Velocity
        trail = [];     // Snake body segments
        tail = 5;       // Initial tail length
        score = 0;

        function game() {
            px += xv;
            py += yv;

            // Wrap around screen edges
            if (px < 0) { px = tc - 1; }
            if (px > tc - 1) { px = 0; }
            if (py < 0) { py = tc - 1; }
            if (py > tc - 1) { py = 0; }

            // Draw Background
            ctx.fillStyle = "black";
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            // Draw Snake
            ctx.fillStyle = "lime";
            for (var i = 0; i < trail.length; i++) {
                ctx.fillRect(trail[i].x * gs, trail[i].y * gs, gs - 2, gs - 2);
                
                // Collision Detection: Did we eat ourself?
                if (trail[i].x == px && trail[i].y == py) {
                    tail = 5; // Reset tail size
                    score = 0; // Reset score
                    updateScore();
                }
            }

            // Move Snake Data
            trail.push({ x: px, y: py });
            while (trail.length > tail) {
                trail.shift();
            }

            // Draw Apple
            ctx.fillStyle = "red";
            ctx.fillRect(ax * gs, ay * gs, gs - 2, gs - 2);

            // Eat Apple Logic
            if (ax == px && ay == py) {
                tail++;
                score += 10;
                updateScore();
                // Respawn apple in random position
                ax = Math.floor(Math.random() * tc);
                ay = Math.floor(Math.random() * tc);
            }
        }

        function updateScore() {
            document.getElementById("score").innerText = score;
        }

        function keyPush(evt) {
            switch (evt.keyCode) {
                case 37: // Left
                    if (xv !== 1) { xv = -1; yv = 0; }
                    break;
                case 38: // Up
                    if (yv !== 1) { xv = 0; yv = -1; }
                    break;
                case 39: // Right
                    if (xv !== -1) { xv = 1; yv = 0; }
                    break;
                case 40: // Down
                    if (yv !== -1) { xv = 0; yv = 1; }
                    break;
            }
        }
    </script>
</body>
</html>