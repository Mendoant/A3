let keys = [];
const konamiCode = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a'];

document.addEventListener('keydown', (e) => {
    keys.push(e.key);
    keys = keys.slice(-konamiCode.length);
    
    if (keys.join(',') === konamiCode.join(',')) {
        activateEasterEgg();
    }
});

function activateEasterEgg() {
    alert('🎉 You found the easter egg!');
}
