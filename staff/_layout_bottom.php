  </div>
</div>
<style>
  .topbar {
    background: linear-gradient(135deg, var(--brand-topbar-start), var(--brand-topbar-end)) !important;
  }

  .sidebar {
    background: rgba(4, 10, 24, 0.84) !important;
    border-right: 1px solid rgba(148, 163, 184, 0.12) !important;
    backdrop-filter: blur(16px);
  }

  .sidebar h3 {
    color: #7f93b0 !important;
  }

  .sidebar a {
    color: #d7e3f4 !important;
  }

  .sidebar a.active,
  .sidebar a:hover {
    background: linear-gradient(135deg, var(--brand-primary-soft-strong), var(--brand-primary-soft)) !important;
    color: #f8fbff !important;
  }
</style>
<script>
  (function () {
    var trigger = document.getElementById('profileMenuTrigger');
    var modal = document.getElementById('profileMenuModal');
    var backdrop = document.getElementById('profileMenuBackdrop');
    var closeButton = document.getElementById('profileMenuClose');

    if (!trigger || !modal || !backdrop) {
      return;
    }

    function openModal() {
      modal.hidden = false;
      backdrop.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      document.body.classList.add('profile-modal-open');
    }

    function closeModal() {
      modal.hidden = true;
      backdrop.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('profile-modal-open');
    }

    trigger.addEventListener('click', function () {
      if (modal.hidden) {
        openModal();
      } else {
        closeModal();
      }
    });

    if (closeButton) {
      closeButton.addEventListener('click', closeModal);
    }

    backdrop.addEventListener('click', closeModal);

    modal.querySelectorAll('[data-profile-nav]').forEach(function (link) {
      link.addEventListener('click', function (event) {
        var href = link.getAttribute('data-profile-nav');
        if (!href) {
          return;
        }
        event.preventDefault();
        window.location.href = href;
      });
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !modal.hidden) {
        closeModal();
      }
    });
  }());
</script>
</body>
</html>
