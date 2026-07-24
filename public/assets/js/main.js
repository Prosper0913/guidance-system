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
});

/**
 * Fetch available slots for a counselor on a date via the availability API,
 * and render them as selectable buttons inside the given container.
 */
function loadAvailableSlots(counselorId, date, containerId, hiddenInputId) {
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
    })
    .catch(() => {
      container.innerHTML = '<span class="text-danger">Error loading slots. Please try again.</span>';
    });
}
