(function () {
  const counts = document.querySelectorAll('[data-live-count]');
  const indicator = document.querySelector('[data-live-indicator]');
  const liveToggle = document.querySelector('[data-live-toggle]');
  const table = document.querySelector('[data-request-table]');
  const liveStatus = document.querySelector('[data-live-status]');
  const themeSelect = document.querySelector('[data-theme-select]');
  let liveEnabled = localStorage.getItem('statington.live') !== 'off';
  let pollTimer = null;

  function applyTheme(theme) {
    const selected = ['light', 'dark', 'ubuntu', 'oceancity'].includes(theme) ? theme : 'light';
    document.documentElement.setAttribute('data-theme', selected);
    if (themeSelect) {
      themeSelect.value = selected;
    }
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function statusClass(status) {
    const code = Number(status) || 0;
    if (code >= 500) return 'bad';
    if (code >= 400) return 'warn';
    if (code >= 300) return 'redirect';
    return 'ok';
  }

  function bytesHuman(bytes) {
    const value = Number(bytes) || 0;
    if (value <= 0) return '-';
    if (value >= 1048576) return `${Math.round((value / 1048576) * 100) / 100} MB`;
    return `${Math.round((value / 1024) * 10) / 10} KB`;
  }

  function renderRows(requests) {
    if (!table) {
      return;
    }

    const body = table.querySelector('tbody');
    if (!requests.length) {
      body.innerHTML = '<tr><td colspan="10" class="empty">No requests yet. Run your app and refresh this page.</td></tr>';
      return;
    }

    body.innerHTML = requests.map((request) => {
      const slow = Number(request.duration_ms) > 200 || Number(request.is_slow) === 1;
      const error = Number(request.error_count) > 0 || Number(request.status) >= 500;
      const rowClass = error ? ' class="row-error"' : (slow ? ' class="row-slow"' : '');
      const errors = error ? `<span class="chip chip-error">${escapeHtml(request.error_count || 0)}</span>` : escapeHtml(request.error_count || 0);
      const slowChip = Number(request.is_slow) === 1 ? '<span class="chip chip-slow">slow</span>' : '';
      const time = new Date(request.ended_at || request.created_at).toLocaleTimeString();
      const status = request.status || '-';

      return `<tr${rowClass}>
        <td class="muted">${escapeHtml(time)}</td>
        <td><span class="method">${escapeHtml(request.method || '-')}</span></td>
        <td>
          <a class="request-link" href="/request.php?id=${encodeURIComponent(request.request_id)}">
            ${escapeHtml(request.path || request.uri || '-')}
          </a>
          <div class="subtle">${escapeHtml(request.request_id)}</div>
        </td>
        <td><span class="code code-${statusClass(status)}">${escapeHtml(status)}</span></td>
        <td><strong>${escapeHtml(request.duration_ms || '-')} ms</strong>${slow ? slowChip || '<span class="chip chip-slow">slow</span>' : ''}</td>
        <td>${escapeHtml(bytesHuman(request.memory_peak))}</td>
        <td>${escapeHtml(request.log_count || 0)}</td>
        <td>${errors}</td>
        <td>${escapeHtml(request.db_query_count || 0)}${Number(request.db_slow_count || 0) > 0 ? ` <span class="chip chip-slow">${escapeHtml(request.db_slow_count)} slow</span>` : ''}${Number(request.db_error_count || 0) > 0 ? ` <span class="chip chip-error">${escapeHtml(request.db_error_count)} failed</span>` : ''}</td>
        <td><a class="view-link" href="/request.php?id=${encodeURIComponent(request.request_id)}">View</a></td>
      </tr>`;
    }).join('');
  }

  async function poll() {
    if (!liveEnabled) {
      return;
    }

    try {
      const response = await fetch('/api/requests' + window.location.search, { headers: { Accept: 'application/json' } });
      if (!response.ok) {
        return;
      }

      const data = await response.json();
      counts.forEach((node) => {
        const key = node.getAttribute('data-live-count');
        if (key === 'requests') {
          node.textContent = data.total || (data.requests || []).length;
        }
        if (key === 'errors') {
          node.textContent = (data.requests || []).reduce((sum, request) => sum + Number(request.error_count || 0), 0);
        }
      });
      renderRows(data.requests || []);
      if (liveStatus) {
        liveStatus.textContent = 'updated ' + new Date().toLocaleTimeString();
      }
      if (indicator) {
        indicator.classList.add('flash');
        setTimeout(() => indicator.classList.remove('flash'), 300);
      }
    } catch (error) {
      if (liveStatus) {
        liveStatus.textContent = 'collector unavailable';
      }
    }
  }

  function updateLiveToggle() {
    if (liveToggle) {
      liveToggle.textContent = liveEnabled ? 'Live On' : 'Live Off';
      liveToggle.classList.toggle('is-off', !liveEnabled);
    }

    if (liveStatus && !liveEnabled) {
      liveStatus.textContent = 'paused';
    }
  }

  async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    textarea.remove();
  }

  document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
      const target = document.getElementById(button.getAttribute('data-copy-target'));
      if (!target) {
        return;
      }

      const original = button.textContent;
      try {
        await copyText(target.value || target.textContent || '');
        button.textContent = 'Copied';
        button.classList.add('copy-success');
        button.setAttribute('aria-live', 'polite');
      } catch (error) {
        button.textContent = 'Copy failed';
        button.classList.add('copy-error');
      }

      setTimeout(() => {
        button.textContent = original;
        button.classList.remove('copy-success', 'copy-error');
      }, 1200);
    });
  });

  const dbSearch = document.querySelector('[data-db-query-search]');
  if (dbSearch) {
    dbSearch.addEventListener('input', () => {
      const term = dbSearch.value.trim().toLowerCase();
      document.querySelectorAll('[data-db-query-text]').forEach((item) => {
        const text = item.getAttribute('data-db-query-text') || '';
        item.hidden = term !== '' && !text.includes(term);
      });
    });
  }

  if (liveToggle) {
    liveToggle.addEventListener('click', () => {
      liveEnabled = !liveEnabled;
      localStorage.setItem('statington.live', liveEnabled ? 'on' : 'off');
      updateLiveToggle();
      if (liveEnabled) {
        poll();
      }
    });
  }

  if (themeSelect) {
    themeSelect.addEventListener('change', () => {
      localStorage.setItem('statington.theme', themeSelect.value);
      applyTheme(themeSelect.value);
    });
  }

  applyTheme(localStorage.getItem('statington.theme') || 'light');
  updateLiveToggle();
  poll();
  pollTimer = setInterval(poll, 2000);
})();
