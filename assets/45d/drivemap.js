(function () {
  if (typeof $ === 'undefined') {
    return;
  }

  var root = document.getElementById('drive-map-app');
  if (!root) {
    return;
  }

  var apiBase = root.getAttribute('data-api') || '';
  var canvas = document.getElementById('drivemap-canvas');
  var statusEl = document.getElementById('drivemap-status');
  var errorEl = document.getElementById('drivemap-error');
  var detailsEl = document.getElementById('drivemap-details');
  var refreshBtn = document.getElementById('drivemap-refresh');

  function setStatus(text) {
    if (statusEl) {
      statusEl.textContent = text;
    }
  }

  function setError(text) {
    if (!errorEl) {
      return;
    }

    if (text) {
      errorEl.textContent = text;
      errorEl.style.display = 'block';
    } else {
      errorEl.textContent = '';
      errorEl.style.display = 'none';
    }
  }

  function setDetails(text) {
    if (!detailsEl) {
      return;
    }

    detailsEl.textContent = text;
  }

  function layoutFromRows(rows) {
    var rowCount = Array.isArray(rows) ? rows.length : 0;
    var colCount = 0;
    var i;
    var row;

    for (i = 0; i < rowCount; i++) {
      row = rows[i];
      if (Array.isArray(row) && row.length > colCount) {
        colCount = row.length;
      }
    }

    return { rows: rowCount || 1, cols: colCount || 1 };
  }

  function formatDetails(bay) {
    if (!bay) {
      return 'Select a bay to see drive details.';
    }

    var label = bay['bay-id'] || 'Bay';
    if (!bay.occupied) {
      return label + ': Empty';
    }

    var parts = [label + ':'];
    if (bay.dev) {
      parts.push(bay.dev);
    }
    if (bay.serial) {
      parts.push('Serial ' + bay.serial);
    }
    if (bay['model-name']) {
      parts.push(bay['model-name']);
    }
    if (bay.capacity) {
      parts.push(bay.capacity);
    }
    if (bay['temp-c']) {
      parts.push('Temp ' + bay['temp-c']);
    }
    if (bay['power-mode']) {
      parts.push('Power ' + bay['power-mode']);
    }
    if (bay.health) {
      parts.push('Health ' + bay.health);
    }

    return parts.join(' | ');
  }

  function renderMap(data) {
    if (!data || !Array.isArray(data.rows)) {
      setStatus('');
      setError('Drive map data is unavailable.');
      return;
    }

    setError('');

    var statusParts = [];
    var duration;
    if (data.lastUpdated) {
      statusParts.push('Last updated: ' + data.lastUpdated);
    }
    if (data.lsdevDuration) {
      duration = parseFloat(data.lsdevDuration);
      if (!isNaN(duration)) {
        statusParts.push('lsdev: ' + duration.toFixed(2) + 's');
      }
    }
    setStatus(statusParts.join(' | '));

    if (!canvas) {
      return;
    }

    canvas.innerHTML = '';

    var layout = layoutFromRows(data.rows);
    var rows = parseInt(layout.rows, 10) || 1;
    var cols = parseInt(layout.cols, 10) || 1;
    canvas.style.backgroundImage = 'none';

    var grid = document.createElement('div');
    grid.className = 'drivemap-grid';
    grid.style.gridTemplateRows = 'repeat(' + rows + ', minmax(0, 1fr))';
    grid.style.gridTemplateColumns = 'repeat(' + cols + ', minmax(0, 1fr))';

    data.rows.forEach(function (row, rowIndex) {
      if (!Array.isArray(row)) {
        return;
      }

      row.forEach(function (bay, colIndex) {
        var slot = bay || {};
        var bayEl = document.createElement('div');
        bayEl.className = 'drivemap-bay' + (slot.occupied ? ' occupied' : '');
        bayEl.style.gridRow = String(rowIndex + 1);
        bayEl.style.gridColumn = String(colIndex + 1);

        var labelEl = document.createElement('div');
        labelEl.className = 'bay-label';
        labelEl.textContent = slot['bay-id'] || 'Bay';
        bayEl.appendChild(labelEl);

        var deviceEl = document.createElement('div');
        deviceEl.className = 'bay-device';
        if (slot.occupied) {
          deviceEl.textContent = slot.dev || slot['dev-by-path'] || 'Occupied';
        } else {
          deviceEl.textContent = 'Empty';
        }
        bayEl.appendChild(deviceEl);

        bayEl.addEventListener('click', function () {
          setDetails(formatDetails(slot));
        });

        grid.appendChild(bayEl);
      });
    });

    canvas.appendChild(grid);

    if (detailsEl && detailsEl.textContent === '') {
      setDetails('Select a bay to see drive details.');
    }
  }

  function fetchMap() {
    if (!apiBase) {
      setError('Drive map API is not configured.');
      return;
    }

    setStatus('Loading map...');
    setError('');

    $.getJSON(apiBase + '?action=drivemap&ts=' + Date.now())
      .done(function (data) {
        renderMap(data);
      })
      .fail(function () {
        setStatus('');
        setError('Unable to load drive map.');
      });
  }

  function refreshMap() {
    if (!apiBase) {
      return;
    }

    setStatus('Refreshing map...');
    $.post(apiBase + '?action=refresh')
      .always(function () {
        fetchMap();
      });
  }

  if (refreshBtn) {
    refreshBtn.addEventListener('click', refreshMap);
  }

  $(fetchMap);
})();
