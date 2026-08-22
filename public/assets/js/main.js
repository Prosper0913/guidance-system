// ===== Guidance Appointment System - shared front-end logic =====

document.addEventListener('DOMContentLoaded', function () {
  // Auto-dismiss alerts after 5s
  document.querySelectorAll('.alert').forEach(function (el) {
    setTimeout(function () {
      if (window.bootstrap) {
        const alert = bootstrap.Alert.getOrCreateInstance(el);
        alert.close();
      }
    }, 5000);
  });

  initNotificationsSidebar();
  initGoogleCalendarWidget();
});

/**
 * Notifications sidebar: opens as an offcanvas instead of a full page. Loads a page of
 * notifications at a time and fetches more as the user scrolls near the bottom of the
 * list (infinite scroll), rather than dumping everything in at once.
 */
function initNotificationsSidebar() {
  const sidebar = document.getElementById('notificationsSidebar');
  const list = document.getElementById('notifList');
  if (!sidebar || !list) return; // not logged in / not on a page with the sidebar

  const PAGE_SIZE = 15;
  let offset = 0;
  let hasMore = true;
  let loading = false;

  function notifIcon(n) {
    if (n.appointment_id) return '🗓️';
    if (n.referral_id) return '📋';
    return '🔔';
  }

  function isImportant(message) {
    return /urgent|crisis|⚠/i.test(message);
  }

  function notifTargetUrl(n) {
    const role = window.CURRENT_USER_ROLE;
    if (n.referral_id && (role === 'counselor' || role === 'admin')) {
      return `${window.BASE_URL}/counselor/referral-view.php?id=${n.referral_id}`;
    }
    if (n.referral_id && role === 'student') {
      return `${window.BASE_URL}/student/my-appointments.php?tab=referrals`;
    }
    if (n.appointment_id && (role === 'counselor' || role === 'admin')) {
      return `${window.BASE_URL}/counselor/appointments.php?tab=appointments`;
    }
    if (n.appointment_id && role === 'student') {
      return `${window.BASE_URL}/student/my-appointments.php?tab=appointments`;
    }
    return null; // general notification — nothing to navigate to
  }

  function timeAgo(dateStr) {
    const then = new Date(dateStr.replace(' ', 'T'));
    const diffMs = Date.now() - then.getTime();
    const mins = Math.floor(diffMs / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    const days = Math.floor(hrs / 24);
    if (days < 7) return `${days}d ago`;
    return then.toLocaleDateString([], { month: 'short', day: 'numeric' });
  }

  function renderItem(n) {
    const item = document.createElement('div');
    item.className = 'notif-item p-2 mb-2 rounded border' + (n.is_read == 0 ? ' notif-unread' : '') + (isImportant(n.message) ? ' notif-important' : '');
    item.dataset.id = n.id;
    item.style.cursor = 'pointer';
    item.innerHTML = `
      <div class="d-flex justify-content-between align-items-start">
        <span class="me-2">${notifIcon(n)}</span>
        <div class="flex-grow-1">
          <div class="small">${n.message.replace(/</g, '&lt;')}</div>
          <div class="text-muted" style="font-size:.75rem;">${timeAgo(n.sent_at)}</div>
        </div>
        ${n.is_read == 0 ? '<span class="badge bg-primary rounded-circle p-1 ms-1">&nbsp;</span>' : ''}
      </div>`;
    item.addEventListener('click', function () {
      handleNotifClick(n, item);
    });
    return item;
  }

  function handleNotifClick(n, item) {
    const target = notifTargetUrl(n);
    if (n.is_read == 0) {
      const fd = new FormData();
      fd.append('csrf_token', window.CSRF_TOKEN);
      fd.append('id', n.id);
      fetch(`${window.BASE_URL}/api/mark-notification-read.php`, { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
          if (data.success) updateBadge(data.unread);
        });
      item.classList.remove('notif-unread');
      const dot = item.querySelector('.badge.rounded-circle');
      if (dot) dot.remove();
    }
    if (target) {
      window.location.href = target;
    }
  }

  function updateBadge(count) {
    const badge = document.getElementById('notifBadge');
    if (!badge) return;
    if (count > 0) {
      badge.textContent = count;
      badge.style.display = '';
    } else {
      badge.style.display = 'none';
    }
  }

  function loadPage(reset) {
    if (loading || (!hasMore && !reset)) return;
    loading = true;
    if (reset) {
      offset = 0;
      hasMore = true;
      list.innerHTML = '<p class="text-muted small text-center mt-3">Loading...</p>';
    }
    fetch(`${window.BASE_URL}/api/get-notifications.php?limit=${PAGE_SIZE}&offset=${offset}`)
      .then(res => res.json())
      .then(data => {
        if (reset) list.innerHTML = '';
        if (!data.success) {
          list.innerHTML = '<p class="text-danger small">Unable to load notifications.</p>';
          return;
        }
        if (data.notifications.length === 0 && offset === 0) {
          list.innerHTML = '<p class="text-muted small text-center mt-3">No notifications yet.</p>';
        }
        data.notifications.forEach(n => list.appendChild(renderItem(n)));
        offset += data.notifications.length;
        hasMore = data.has_more;
        updateBadge(data.unread);
      })
      .catch(() => {
        if (reset) list.innerHTML = '<p class="text-danger small">Unable to load notifications.</p>';
      })
      .finally(() => { loading = false; });
  }

  sidebar.addEventListener('shown.bs.offcanvas', function () {
    loadPage(true);
  });

  list.addEventListener('scroll', function () {
    if (list.scrollTop + list.clientHeight >= list.scrollHeight - 40) {
      loadPage(false);
    }
  });

  const markAllBtn = document.getElementById('notifMarkAllReadBtn');
  if (markAllBtn) {
    markAllBtn.addEventListener('click', function () {
      const fd = new FormData();
      fd.append('csrf_token', window.CSRF_TOKEN);
      fd.append('all', '1');
      fetch(`${window.BASE_URL}/api/mark-notification-read.php`, { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            updateBadge(0);
            list.querySelectorAll('.notif-unread').forEach(el => {
              el.classList.remove('notif-unread');
              const dot = el.querySelector('.badge.rounded-circle');
              if (dot) dot.remove();
            });
          }
        });
    });
  }
}

/**
 * Google Calendar mini-widget for the counselor dashboard: a small month grid plus an
 * agenda panel for the selected day, backed by api/google-calendar-events.php. Lets the
 * counselor glance at their real Google Calendar without leaving the app.
 */
function initGoogleCalendarWidget() {
  const grid = document.getElementById('gcalGrid');
  const weekdaysEl = document.getElementById('gcalWeekdays');
  const agenda = document.getElementById('gcalAgenda');
  const monthLabel = document.getElementById('gcalMonthLabel');
  if (!grid || !agenda) return; // not connected / not on a page with the widget

  const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
  const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  let viewDate = new Date();
  let selectedDateStr = formatDate(new Date());
  let eventsByDay = {};

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function formatDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  function renderWeekdayHeader() {
    if (weekdaysEl) weekdaysEl.innerHTML = WEEKDAYS.map(w => `<div class="gcal-weekday">${w}</div>`).join('');
  }

  function loadMonth() {
    const year = viewDate.getFullYear();
    const month = viewDate.getMonth();
    if (monthLabel) monthLabel.textContent = `${MONTHS[month]} ${year}`;

    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startStr = formatDate(firstDay);
    const endStr = formatDate(lastDay);

    grid.innerHTML = '<div class="text-muted small p-2">Loading…</div>';

    fetch(`${window.BASE_URL}/api/google-calendar-events.php?start=${startStr}&end=${endStr}`)
      .then(res => res.json())
      .then(data => {
        eventsByDay = {};
        if (data.success) {
          data.events.forEach(ev => {
            const dayKey = ev.all_day ? ev.start : ev.start.slice(0, 10);
            if (!eventsByDay[dayKey]) eventsByDay[dayKey] = [];
            eventsByDay[dayKey].push(ev);
          });
        }
        renderGrid(firstDay, lastDay);
        renderAgenda(selectedDateStr);
      })
      .catch(() => {
        grid.innerHTML = '<div class="text-danger small p-2">Unable to load calendar.</div>';
      });
  }

  function renderGrid(firstDay, lastDay) {
    const todayStr = formatDate(new Date());
    let html = '';
    const leadingBlanks = firstDay.getDay();
    for (let i = 0; i < leadingBlanks; i++) html += '<div class="gcal-day gcal-blank"></div>';

    for (let day = 1; day <= lastDay.getDate(); day++) {
      const d = new Date(firstDay.getFullYear(), firstDay.getMonth(), day);
      const dStr = formatDate(d);
      const hasEvents = !!eventsByDay[dStr];
      const classes = ['gcal-day'];
      if (dStr === todayStr) classes.push('gcal-today');
      if (dStr === selectedDateStr) classes.push('gcal-selected');
      if (hasEvents) classes.push('gcal-has-events');
      html += `<div class="${classes.join(' ')}" data-date="${dStr}">${day}${hasEvents ? '<span class="gcal-dot"></span>' : ''}</div>`;
    }
    grid.innerHTML = html;

    grid.querySelectorAll('.gcal-day:not(.gcal-blank)').forEach(cell => {
      cell.addEventListener('click', function () {
        selectedDateStr = this.dataset.date;
        grid.querySelectorAll('.gcal-day').forEach(c => c.classList.remove('gcal-selected'));
        this.classList.add('gcal-selected');
        renderAgenda(selectedDateStr);
      });
    });
  }

  function renderAgenda(dateStr) {
    const label = document.getElementById('gcalAgendaLabel');
    const todayStr = formatDate(new Date());
    const d = new Date(dateStr + 'T00:00:00');
    if (label) label.textContent = dateStr === todayStr ? 'Today' : d.toLocaleDateString([], { weekday: 'long', month: 'short', day: 'numeric' });

    const events = eventsByDay[dateStr] || [];
    if (events.length === 0) {
      agenda.innerHTML = '<p class="text-muted small">No events.</p>';
      return;
    }
    agenda.innerHTML = events.map(ev => {
      const timeLabel = ev.all_day ? 'All day' : new Date(ev.start).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
      const linkOpen = ev.html_link ? `<a href="${ev.html_link}" target="_blank" rel="noopener">` : '<span>';
      const linkClose = ev.html_link ? '</a>' : '</span>';
      return `<div class="gcal-agenda-item mb-2 p-2 border rounded small">
        <div class="fw-semibold">${linkOpen}${escapeHtml(ev.title)}${linkClose}</div>
        <div class="text-muted">${timeLabel}${ev.location ? ' · ' + escapeHtml(ev.location) : ''}</div>
      </div>`;
    }).join('');
  }

  const prevBtn = document.getElementById('gcalPrevBtn');
  const nextBtn = document.getElementById('gcalNextBtn');
  if (prevBtn) prevBtn.addEventListener('click', function () {
    viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() - 1, 1);
    loadMonth();
  });
  if (nextBtn) nextBtn.addEventListener('click', function () {
    viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 1);
    loadMonth();
  });

  renderWeekdayHeader();
  loadMonth();
}

/**
 * Fetch available slots for a counselor on a date via the availability API,
 * and render them as selectable buttons inside the given container.
 */
function loadAvailableSlots(counselorId, date, containerId, hiddenInputId, preferredValue) {
  const container = document.getElementById(containerId);
  const hiddenInput = document.getElementById(hiddenInputId);
  if (!container) return;

  container.innerHTML = '<span class="text-muted">Loading available slots...</span>';
  hiddenInput.value = '';

  fetch(`${window.BASE_URL}/api/check-availability.php?counselor_id=${encodeURIComponent(counselorId)}&date=${encodeURIComponent(date)}`)
    .then(res => res.json())
    .then(data => {
      container.innerHTML = '';
      if (!data.success) {
        container.innerHTML = `<span class="text-danger">${data.message || 'Unable to load slots.'}</span>`;
        return;
      }
      if (data.slots.length === 0) {
        container.innerHTML = '<span class="text-muted">No available slots for this date. Try another date or counselor.</span>';
        return;
      }
      data.slots.forEach(slot => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-dark btn-sm slot-btn me-2 mb-2';
        btn.textContent = slot.label;
        btn.dataset.value = slot.value;
        btn.addEventListener('click', function () {
          container.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
          btn.classList.add('selected');
          hiddenInput.value = slot.value;
        });
        container.appendChild(btn);
      });

      // Auto-select the slot matching the student's preferred time, if it's still available.
      if (preferredValue) {
        const prefix = preferredValue.slice(0, 5); // compare HH:MM, ignore seconds
        const match = Array.from(container.querySelectorAll('.slot-btn'))
          .find(b => b.dataset.value.slice(0, 5) === prefix);
        if (match) {
          match.click();
          match.scrollIntoView({ block: 'nearest' });
        }
      }
    })
    .catch(() => {
      container.innerHTML = '<span class="text-danger">Error loading slots. Please try again.</span>';
    });
}