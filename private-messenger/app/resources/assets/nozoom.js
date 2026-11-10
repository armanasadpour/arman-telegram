// Locks page zoom on every screen (the viewport meta alone is ignored by iOS Safari and desktop browsers).
// Text stays readable: the app has its own text-size setting.
(() => {
  const stop = (e) => e.preventDefault();
  ['gesturestart', 'gesturechange', 'gestureend'].forEach((t) => document.addEventListener(t, stop, { passive: false })); // iOS pinch
  document.addEventListener('touchmove', (e) => { if (e.touches && e.touches.length > 1) e.preventDefault(); }, { passive: false }); // two-finger zoom
  let last = 0;
  document.addEventListener('touchend', (e) => { const n = Date.now(); if (n - last < 300 && !e.target.closest('input, textarea, select, [contenteditable]')) e.preventDefault(); last = n; }, { passive: false }); // double-tap
  window.addEventListener('wheel', (e) => { if (e.ctrlKey) e.preventDefault(); }, { passive: false }); // ctrl+wheel / trackpad pinch
  window.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && ['+', '-', '=', '0'].includes(e.key)) e.preventDefault(); });
})();
