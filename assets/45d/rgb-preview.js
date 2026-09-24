(() => {
  const root = document.getElementById('homelab-rgb-preview');
  if (!root) return;

  const canvas = root.querySelector('canvas');
  const context = canvas.getContext('2d');
  const mode = root.querySelector('select');
  const pause = root.querySelector('[data-pause]');
  const numbers = root.querySelector('[data-numbers]');
  const description = root.querySelector('[data-description]');
  const order = [2, 3, 4, 5, 6, 7, 8, 20, 21, 22, 23, 12, 13, 14];
  const rank = new Map(order.map((index, step) => [index, step]));
  const descriptions = {
    'pinwheel-rainbow': 'One rainbow moves around the outward-facing hub LEDs.',
    'synchronized-rainbow': 'Both fans show the same color at the same clock position.'
  };
  let running = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let elapsed = 0;
  let lastTime = 0;
  let lastDraw = 0;

  function colorWheel(hue) {
    const value = ((Math.round(hue) % 1536) + 1536) % 1536;
    const segment = Math.floor(value / 256);
    const step = value % 256;
    const channels = [
      [255, step, 0], [255 - step, 255, 0], [0, 255, step],
      [0, 255 - step, 255], [step, 0, 255], [255, 0, 255 - step]
    ][segment];
    return `rgb(${channels.join(',')})`;
  }

  function ledColor(index) {
    const phase = Math.floor(elapsed * 256);
    if (mode.value === 'pinwheel-rainbow') {
      const step = rank.get(index);
      return step === undefined ? null : colorWheel(Math.round(step * 1536 / order.length) - phase);
    }
    return colorWheel((index % 12) * 128 - phase);
  }

  function circle(x, y, radius, fill, stroke) {
    context.beginPath();
    context.arc(x, y, radius, 0, Math.PI * 2);
    if (fill) { context.fillStyle = fill; context.fill(); }
    if (stroke) { context.strokeStyle = stroke; context.lineWidth = 1.5; context.stroke(); }
  }

  function fan(y, fanIndex, palette) {
    const x = 210;
    circle(x, y, 128, palette.body, palette.border);
    circle(x, y, 116, palette.surface, palette.border);
    for (let blade = 0; blade < 7; blade++) {
      const angle = blade * Math.PI * 2 / 7 + 0.2;
      context.beginPath();
      context.moveTo(x + Math.cos(angle) * 54, y - Math.sin(angle) * 54);
      context.quadraticCurveTo(x + Math.cos(angle + 0.45) * 105, y - Math.sin(angle + 0.45) * 105,
        x + Math.cos(angle + 0.85) * 117, y - Math.sin(angle + 0.85) * 117);
      context.strokeStyle = palette.border;
      context.lineWidth = 2;
      context.stroke();
    }
    circle(x, y, 57, palette.body, palette.border);
    for (let local = 0; local < 12; local++) {
      const index = fanIndex * 12 + local;
      const angle = (240 - 30 * local) * Math.PI / 180;
      const ledX = x + Math.cos(angle) * 47;
      const ledY = y - Math.sin(angle) * 47;
      const lit = ledColor(index);
      context.shadowBlur = lit ? 20 : 0;
      context.shadowColor = lit || 'transparent';
      circle(ledX, ledY, 13, lit || palette.off, null);
      context.shadowBlur = 0;
      if (numbers.checked) {
        context.fillStyle = lit ? '#111' : palette.text;
        context.font = '12px sans-serif';
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillText(String(index + 1), ledX, ledY);
      }
    }
    circle(x, y, 28, palette.surface, palette.border);
    context.fillStyle = palette.text;
    context.font = '13px sans-serif';
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    context.fillText(fanIndex === 0 ? 'TOP' : 'BOTTOM', x, y);
  }

  function draw() {
    const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const palette = dark
      ? { body: '#25313b', surface: '#111b23', border: '#5a6c78', off: '#3b4a55', text: '#e5edf3' }
      : { body: '#dde3e8', surface: '#f7f9fa', border: '#798b99', off: '#aab6c0', text: '#25313b' };
    context.clearRect(0, 0, canvas.width, canvas.height);
    fan(170, 0, palette);
    fan(430, 1, palette);
    circle(210, 300, 4, palette.text, null);
  }

  function frame(now) {
    if (running && lastTime) elapsed += (now - lastTime) / 1000;
    lastTime = now;
    if (now - lastDraw >= 180) {
      draw();
      lastDraw = now;
    }
    window.requestAnimationFrame(frame);
  }

  mode.addEventListener('change', () => {
    description.textContent = descriptions[mode.value];
    elapsed = 0;
    draw();
  });
  pause.addEventListener('click', () => {
    running = !running;
    pause.textContent = running ? 'Pause preview' : 'Play preview';
    pause.setAttribute('aria-pressed', String(!running));
  });
  numbers.addEventListener('change', draw);
  if (!running) pause.textContent = 'Play preview';
  description.textContent = descriptions[mode.value];
  draw();
  window.requestAnimationFrame(frame);
})();
