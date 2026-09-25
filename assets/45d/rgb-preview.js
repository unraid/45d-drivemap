(() => {
  const root = document.getElementById('homelab-rgb-preview');
  if (!root || root.dataset.previewInitialized === 'true') return;
  root.dataset.previewInitialized = 'true';

  const nightForm = document.querySelector('.homelab-night-form');
  if (nightForm) {
    const enabled = nightForm.elements.namedItem('enabled');
    const start = nightForm.elements.namedItem('start');
    const end = nightForm.elements.namedItem('end');
    const updateRequiredTimes = () => {
      start.required = enabled.value === '1';
      end.required = enabled.value === '1';
    };
    enabled.addEventListener('change', updateRequiredTimes);
    updateRequiredTimes();
  }

  const canvas = root.querySelector('canvas');
  const context = canvas.getContext('2d');
  const mode = root.querySelector('select');
  const globalPreset = root.elements.namedItem('color');
  const presetColors = JSON.parse(root.dataset.presetColors);
  const globalControls = root.querySelector('[data-global-controls]');
  const patternColors = root.querySelector('[data-pattern-colors]');
  const colorHeading = root.querySelector('[data-color-heading]');
  const colorPickers = root.querySelector('[data-color-pickers]');
  const separateHelp = root.querySelector('[data-separate-help]');
  const tuningControls = root.querySelector('[data-tuning-controls]');
  const primaryLabel = root.querySelector('[data-primary-label]');
  const secondaryLabel = root.querySelector('[data-secondary-label]');
  const pause = root.querySelector('[data-pause]');
  const numbers = root.querySelector('[data-numbers]');
  const description = root.querySelector('[data-description]');
  const reset = root.querySelector('[data-reset]');
  const editor = root.querySelector('[data-custom-editor]');
  const stage = root.querySelector('[data-stage]');
  const popover = root.querySelector('[data-led-popover]');
  const ledLabel = root.querySelector('[data-led-label]');
  const paintColor = root.querySelector('[data-paint-color]');
  const fillColor = root.querySelector('[data-fill-color]');
  const ledIndex = root.querySelector('[data-led-index]');
  const savedColors = root.elements.namedItem('led_colors');
  const fields = Object.fromEntries([
    'period_seconds', 'direction', 'hue_degrees', 'brightness_pct',
    'bottom_phase_steps', 'rainbow_cycles', 'fade_pct', 'palette_mode',
    'color_primary', 'color_secondary', 'tail_leds', 'tail_variation', 'skipped_leds',
    'virtual_gap_steps',
    'middle_enabled', 'middle_color'
  ].map((name) => [name, root.elements.namedItem(name)]));
  const defaults = {
    period_seconds: 6, direction: 'clockwise', hue_degrees: 0,
    brightness_pct: 100, bottom_phase_steps: 0, rainbow_cycles: 1,
    fade_pct: 35, tail_leds: 6, tail_variation: 50, skipped_leds: 4, virtual_gap_steps: 1,
    middle_enabled: '0', middle_color: '#ffffff'
  };
  const patternDefaults = {
    separate: ['#ffffff', '#0000ff', 'rainbow'],
    'pinwheel-rainbow': ['#ff4500', '#0000ff', 'rainbow'],
    'synchronized-rainbow': ['#ff4500', '#0000ff', 'rainbow'],
    'brand-loop': ['#ff4500', '#0000ff', 'rainbow'],
    'comet-loop': ['#ffffff', '#00afff', 'rainbow'],
    'orange-blue-pulse': ['#ff4500', '#0000ff', 'rainbow']
  };
  const colorDrafts = new Map();
  let lastMode = mode.value;
  const perimeter = [...Array(12).keys(), 18, 19, 20, 21, 22, 23, 12, 13, 14, 15, 16, 17];
  const topSkipPriority = [11, 0, 10, 1, 9, 2];
  const bottomSkipPriority = [17, 18, 16, 19, 15, 20];
  // Calibration: LED 1 is near 6:30 and LED 12 near 5:30 on the mounted fans.
  const ledZeroAngle = 255;
  const ledAngle = (local) => (ledZeroAngle - 30 * local) * Math.PI / 180;
  let cachedLoop;
  function loopOrder() {
    const count = Number(fields.skipped_leds.value);
    const gap = Number(fields.virtual_gap_steps.value);
    if (cachedLoop && cachedLoop.count === count && cachedLoop.gap === gap) return cachedLoop;
    const skipped = new Set([...topSkipPriority.slice(0, count), ...bottomSkipPriority.slice(0, count)]);
    const order = perimeter.filter((index) => !skipped.has(index));
    const topCount = order.length / 2;
    cachedLoop = {
      count, gap, order, length: order.length + 2 * gap,
      rank: new Map(order.map((index, step) => [index, step + (step >= topCount ? gap : 0)]))
    };
    return cachedLoop;
  }
  const descriptions = {
    'custom-leds': 'Select an LED in the preview to change its color.',
    global: 'Animated previews are approximate.'
  };
  let customColors;
  try {
    customColors = JSON.parse(root.dataset.ledColors);
    if (!Array.isArray(customColors) || customColors.length !== 24 ||
        customColors.some((color) => !/^[0-9A-Fa-f]{6}$/.test(color))) throw new Error('Invalid palette');
  } catch (_) {
    customColors = Array(24).fill('000000');
  }
  let displayedColors = Array.from({ length: 24 }, () => [0, 0, 0]);
  let running = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let elapsed = 0;
  let lastTime = 0;
  let lastDraw = 0;

  function colorWheel(hue, brightness) {
    const value = ((Math.round(hue) % 1536) + 1536) % 1536;
    const segment = Math.floor(value / 256);
    const step = value % 256;
    const channels = [
      [255, step, 0], [255 - step, 255, 0], [0, 255, step],
      [0, 255 - step, 255], [step, 0, 255], [255, 0, 255 - step]
    ][segment];
    return channels.map((channel) => Math.round(channel * brightness / 100));
  }

  function mixColor(first, second, ratio) {
    return first.map((start, channel) => Math.round(start * (1 - ratio) + second[channel] * ratio));
  }

  function scaleColor(color, level) {
    return color.map((channel) => Math.round(channel * Math.max(0, Math.min(1, level))));
  }

  function chosenColor(name) {
    const hex = fields[name].value.slice(1);
    return [0, 2, 4].map((offset) => parseInt(hex.slice(offset, offset + 2), 16));
  }

  function middleColor(brightness) {
    return fields.middle_enabled.value === '1'
      ? scaleColor(chosenColor('middle_color'), brightness / 100)
      : [0, 0, 0];
  }

  function paletteColor(hue, brightness) {
    if (fields.palette_mode.value === 'two-color') {
      const ratio = 0.5 + 0.5 * Math.cos(2 * Math.PI * hue / 1536);
      return scaleColor(mixColor(chosenColor('color_secondary'), chosenColor('color_primary'), ratio), brightness / 100);
    }
    return colorWheel(hue, brightness);
  }

  function targetColor(index) {
    if (mode.value === 'separate') {
      return chosenColor(index < 12 ? 'color_primary' : 'color_secondary');
    }
    if (mode.value === 'global') {
      const preset = globalPreset.value;
      if (presetColors[preset]) {
        const hex = presetColors[preset];
        return [0, 2, 4].map((offset) => parseInt(hex.slice(offset, offset + 2), 16));
      }
      const phase = Math.floor(elapsed * 1536 / 6);
      const position = (index % 12) * 128;
      if (preset === 'spectrum-cycle') return colorWheel(phase, 100);
      if (preset === 'color-wave') return colorWheel(position + phase, 100);
      return colorWheel(position - phase, 100);
    }
    const brightness = Number(fields.brightness_pct.value);
    if (mode.value === 'custom-leds') {
      const hex = customColors[index];
      return [0, 2, 4].map((offset) => Math.round(parseInt(hex.slice(offset, offset + 2), 16) * brightness / 100));
    }
    const cycles = Number(fields.rainbow_cycles.value);
    const direction = fields.direction.value === 'counterclockwise' ? -1 : 1;
    const time = elapsed * cycles / Number(fields.period_seconds.value);
    const alignment = index >= 12 ? Number(fields.bottom_phase_steps.value) : 0;
    if (mode.value === 'orange-blue-pulse') {
      const offset = index >= 12 ? 0.5 + alignment / 12 : 0;
      const angle = 2 * Math.PI * (direction * time + offset);
      const level = 0.25 + 0.75 * (0.5 + 0.5 * Math.cos(angle));
      const color = chosenColor(index >= 12 ? 'color_secondary' : 'color_primary');
      return scaleColor(scaleColor(color, level), brightness / 100);
    }
    if (mode.value === 'brand-loop' || mode.value === 'comet-loop') {
      const { rank, length } = loopOrder();
      const step = rank.get(index);
      if (step === undefined) return middleColor(brightness);
      const position = (step + alignment) * cycles - direction * time * length;
      let color;
      if (mode.value === 'brand-loop') {
        const ratio = 0.5 + 0.5 * Math.cos(2 * Math.PI * position / length);
        color = mixColor(chosenColor('color_secondary'), chosenColor('color_primary'), ratio);
      } else {
        const distance = ((position % length) + length) % length;
        let level = Math.max(0, 1 - distance / Number(fields.tail_leds.value));
        if (distance >= 1 && level > 0) {
          const tick = Math.floor(elapsed * 5);
          const variation = ((index * 73 + tick * 151 + 37) % 101) / 100;
          level *= 1 - Number(fields.tail_variation.value) / 100 * (0.65 * (1 - variation));
        }
        const head = Math.max(0, 1 - distance);
        color = scaleColor(mixColor(chosenColor('color_secondary'), chosenColor('color_primary'), head), level);
      }
      return scaleColor(color, brightness / 100);
    }
    const phase = Math.floor(elapsed * 1536 * cycles / Number(fields.period_seconds.value)) * direction;
    const hueOffset = Math.round(Number(fields.hue_degrees.value) * 1536 / 360);
    const bottomOffset = index >= 12 ? Number(fields.bottom_phase_steps.value) * 128 * cycles : 0;
    if (mode.value === 'pinwheel-rainbow') {
      const { rank, length } = loopOrder();
      const step = rank.get(index);
      return step === undefined ? middleColor(brightness) : paletteColor(
        Math.round(step * 1536 * cycles / length) + hueOffset + bottomOffset - phase,
        brightness
      );
    }
    return paletteColor((index % 12) * 128 * cycles + hueOffset + bottomOffset - phase, brightness);
  }

  function updateFrameColors(force = false) {
    const fade = force ? 0 : Number(fields.fade_pct.value);
    displayedColors = displayedColors.map((old, index) => {
      const target = targetColor(index);
      return target.map((value, channel) => {
        let blended = Math.round((old[channel] * fade + value * (100 - fade)) / 100);
        if (blended === old[channel] && value !== old[channel]) blended += value > old[channel] ? 1 : -1;
        return blended;
      });
    });
  }

  function setCustomColors(colors) {
    customColors = colors.map((color) => color.toUpperCase());
    savedColors.value = JSON.stringify(customColors);
    updateFrameColors();
    draw();
  }

  function paint(index, color = paintColor.value.slice(1)) {
    const colors = customColors.slice();
    colors[index] = color;
    ledIndex.value = String(index);
    setCustomColors(colors);
  }

  function closeLedEditor() {
    popover.hidden = true;
    canvas.focus();
  }

  function openLedEditor(index) {
    if (mode.value !== 'custom-leds') return;
    ledIndex.value = String(index);
    ledLabel.textContent = `${index < 12 ? 'Top' : 'Bottom'} LED ${index % 12 + 1}`;
    paintColor.value = `#${customColors[index]}`;
    popover.hidden = false;
    const local = index % 12;
    const angle = ledAngle(local);
    const scale = canvas.clientWidth / canvas.width;
    const x = (210 + Math.cos(angle) * 47) * scale;
    const y = ((index < 12 ? 170 : 430) - Math.sin(angle) * 47) * scale;
    popover.style.left = `${Math.max(0, Math.min(x - popover.offsetWidth / 2, stage.clientWidth - popover.offsetWidth))}px`;
    const below = y + 18 + popover.offsetHeight <= stage.clientHeight;
    popover.style.top = `${Math.max(0, below ? y + 18 : y - popover.offsetHeight - 18)}px`;
    paintColor.focus();
    draw();
  }

  function updateValues() {
    const units = {
      period_seconds: ' s', hue_degrees: ' deg', brightness_pct: '%',
      bottom_phase_steps: ' LEDs', fade_pct: '%', tail_leds: ' LEDs',
      tail_variation: '%', skipped_leds: ' LEDs per fan', virtual_gap_steps: ' per crossing'
    };
    for (const [name, unit] of Object.entries(units)) {
      const value = `${fields[name].value}${unit}`;
      root.querySelector(`[data-value-for="${name}"]`).textContent = value;
      fields[name].setAttribute('aria-valuetext', value);
    }
  }

  function updateMode() {
    const custom = mode.value === 'custom-leds';
    const separate = mode.value === 'separate';
    const rainbow = mode.value === 'pinwheel-rainbow' || mode.value === 'synchronized-rainbow';
    const animated = rainbow || mode.value === 'brand-loop' || mode.value === 'comet-loop' ||
      mode.value === 'orange-blue-pulse';
    const streamed = animated || custom;
    if (mode.value !== lastMode) {
      colorDrafts.set(lastMode, [fields.color_primary.value, fields.color_secondary.value, fields.palette_mode.value]);
      const selected = colorDrafts.get(mode.value) || patternDefaults[mode.value];
      if (selected) {
        fields.color_primary.value = selected[0];
        fields.color_secondary.value = selected[1];
        fields.palette_mode.value = selected[2];
      }
      lastMode = mode.value;
    }
    globalControls.hidden = mode.value !== 'global';
    editor.hidden = !custom;
    patternColors.hidden = !animated && !separate;
    colorHeading.textContent = separate ? 'Fan colors' : 'Pattern colors';
    colorPickers.hidden = !separate && (!animated || (rainbow && fields.palette_mode.value === 'rainbow'));
    separateHelp.hidden = !separate;
    tuningControls.hidden = !streamed;
    for (const control of root.querySelectorAll('[data-rainbow-palette]')) control.hidden = !rainbow;
    for (const control of root.querySelectorAll('[data-comet-only]')) control.hidden = mode.value !== 'comet-loop';
    for (const control of root.querySelectorAll('[data-loop-only]')) {
      control.hidden = !['pinwheel-rainbow', 'brand-loop', 'comet-loop'].includes(mode.value) ||
        (control.hasAttribute('data-middle-setting') && Number(fields.skipped_leds.value) === 0);
    }
    for (const control of root.querySelectorAll('[data-middle-color]')) {
      control.hidden = !['pinwheel-rainbow', 'brand-loop', 'comet-loop'].includes(mode.value) ||
        fields.middle_enabled.value !== '1' || Number(fields.skipped_leds.value) === 0;
    }
    primaryLabel.textContent = mode.value === 'comet-loop' ? 'Head' :
      (separate || mode.value === 'orange-blue-pulse' ? 'Top fan' : 'Primary');
    secondaryLabel.textContent = mode.value === 'comet-loop' ? 'Tail' :
      (separate || mode.value === 'orange-blue-pulse' ? 'Bottom fan' : 'Secondary');
    popover.hidden = true;
    for (const label of root.querySelectorAll('[data-animated-only]')) label.hidden = !animated;
    for (const label of root.querySelectorAll('[data-rainbow-only]')) {
      label.hidden = !rainbow || fields.palette_mode.value !== 'rainbow';
    }
    for (const label of root.querySelectorAll('[data-stream-only]')) label.hidden = !streamed;
    description.textContent = descriptions[mode.value] || '';
    description.hidden = !description.textContent;
    elapsed = 0;
    updateFrameColors();
    draw();
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
      const angle = ledAngle(local);
      const ledX = x + Math.cos(angle) * 47;
      const ledY = y - Math.sin(angle) * 47;
      const channels = displayedColors[index];
      const lit = channels.some((value) => value >= 25) ? `rgb(${channels.join(',')})` : null;
      context.shadowBlur = lit ? 20 : 0;
      context.shadowColor = lit || 'transparent';
      circle(ledX, ledY, 13, lit || palette.off, palette.border);
      context.shadowBlur = 0;
      if (mode.value === 'custom-leds' && Number(ledIndex.value) === index) {
        circle(ledX, ledY, 17, null, palette.text);
      }
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
    if (['pinwheel-rainbow', 'brand-loop', 'comet-loop'].includes(mode.value)) {
      const { order, gap } = loopOrder();
      const point = (index) => {
        const angle = ledAngle(index % 12);
        return [210 + Math.cos(angle) * 47,
          (index < 12 ? 170 : 430) - Math.sin(angle) * 47];
      };
      const topCount = order.length / 2;
      for (const [fromIndex, toIndex] of [[order[topCount - 1], order[topCount]],
        [order[order.length - 1], order[0]]]) {
        const from = point(fromIndex);
        const to = point(toIndex);
        for (let step = 1; step <= gap; step++) {
          const fraction = step / (gap + 1);
          context.globalAlpha = 0.65;
          context.setLineDash([3, 3]);
          circle(from[0] + (to[0] - from[0]) * fraction,
            from[1] + (to[1] - from[1]) * fraction, 8, null, palette.text);
          context.setLineDash([]);
          context.globalAlpha = 1;
        }
      }
    }
    circle(210, 300, 4, palette.text, null);
  }

  function frame() {
    const now = performance.now();
    if (running && !document.hidden && lastTime) elapsed += (now - lastTime) / 1000;
    lastTime = now;
    if (!document.hidden && now - lastDraw >= 65) {
      updateFrameColors();
      draw();
      lastDraw = now;
    }
    window.setTimeout(frame, document.hidden ? 250 : 67);
  }

  mode.addEventListener('change', updateMode);
  for (const field of [globalPreset]) {
    field.addEventListener('change', () => { updateFrameColors(true); draw(); });
  }
  for (const field of Object.values(fields)) {
    field.addEventListener('input', () => { updateValues(); if ([fields.palette_mode, fields.middle_enabled, fields.skipped_leds].includes(field)) updateMode(); updateFrameColors(); draw(); });
    field.addEventListener('change', () => { updateValues(); if ([fields.palette_mode, fields.middle_enabled, fields.skipped_leds].includes(field)) updateMode(); updateFrameColors(); draw(); });
  }
  ledIndex.addEventListener('change', draw);
  root.querySelector('[data-open-led]').addEventListener('click', () => openLedEditor(Number(ledIndex.value)));
  root.querySelector('[data-close-led]').addEventListener('click', closeLedEditor);
  root.querySelector('[data-apply-led]').addEventListener('click', () => {
    paint(Number(ledIndex.value));
    closeLedEditor();
  });
  root.querySelector('[data-off-led]').addEventListener('click', () => {
    paint(Number(ledIndex.value), '000000');
    closeLedEditor();
  });
  root.querySelector('[data-fill-top]').addEventListener('click', () => {
    setCustomColors(customColors.map((color, index) => index < 12 ? fillColor.value.slice(1) : color));
  });
  root.querySelector('[data-fill-bottom]').addEventListener('click', () => {
    setCustomColors(customColors.map((color, index) => index >= 12 ? fillColor.value.slice(1) : color));
  });
  root.querySelector('[data-fill-all]').addEventListener('click', () => {
    setCustomColors(Array(24).fill(fillColor.value.slice(1)));
  });
  root.querySelector('[data-split]').addEventListener('click', () => {
    setCustomColors([...Array(12).fill('FF4500'), ...Array(12).fill('0000FF')]);
  });
  root.querySelector('[data-clear]').addEventListener('click', () => {
    setCustomColors(Array(24).fill('000000'));
  });
  canvas.addEventListener('click', (event) => {
    if (mode.value !== 'custom-leds') return;
    const bounds = canvas.getBoundingClientRect();
    const x = (event.clientX - bounds.left) * canvas.width / bounds.width;
    const y = (event.clientY - bounds.top) * canvas.height / bounds.height;
    for (let index = 0; index < 24; index++) {
      const local = index % 12;
      const angle = ledAngle(local);
      const ledX = 210 + Math.cos(angle) * 47;
      const ledY = (index < 12 ? 170 : 430) - Math.sin(angle) * 47;
      if ((x - ledX) ** 2 + (y - ledY) ** 2 <= 20 ** 2) {
        openLedEditor(index);
        break;
      }
    }
  });
  canvas.addEventListener('keydown', (event) => {
    if (mode.value !== 'custom-leds') return;
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      openLedEditor(Number(ledIndex.value));
    } else if (event.key === 'ArrowRight' || event.key === 'ArrowDown' ||
               event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
      event.preventDefault();
      ledIndex.value = String((Number(ledIndex.value) +
        (event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : 23)) % 24);
      draw();
    }
  });
  popover.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeLedEditor();
  });
  document.addEventListener('pointerdown', (event) => {
    if (!popover.hidden && !popover.contains(event.target) && event.target !== canvas) popover.hidden = true;
  });
  reset.addEventListener('click', () => {
    for (const [name, value] of Object.entries(defaults)) {
      fields[name].value = String(value);
    }
    const selected = patternDefaults[mode.value];
    if (selected) {
      fields.color_primary.value = selected[0];
      fields.color_secondary.value = selected[1];
      fields.palette_mode.value = selected[2];
    } else if (mode.value === 'global') {
      globalPreset.value = 'white';
    }
    elapsed = 0;
    updateValues();
    updateMode();
  });
  pause.addEventListener('click', () => {
    running = !running;
    pause.textContent = running ? 'Pause preview' : 'Play preview';
    pause.setAttribute('aria-pressed', String(!running));
  });
  numbers.addEventListener('change', draw);
  if (!running) pause.textContent = 'Play preview';
  updateValues();
  savedColors.value = JSON.stringify(customColors);
  updateMode();
  updateFrameColors(true);
  draw();
  window.setTimeout(frame, 67);
})();
