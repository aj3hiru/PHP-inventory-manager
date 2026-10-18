<div class="header-user-wrap">
    <button class="header-user" type="button" onclick="event.stopPropagation(); this.nextElementSibling.classList.toggle('show');">
        <div class="avatar"><?= strtoupper(substr($username, 0, 1)) ?></div>
        <div class="info">
            <div class="name"><?= htmlspecialchars($username) ?></div>
            <div class="role"><?= ucfirst(htmlspecialchars($_SESSION['role'] ?? '')) ?></div>
        </div>
    </button>
    <ul class="header-user-menu">
        <li><a class="dropdown-item" href="/admin/my-profile.php"><i class="fas fa-user-edit"></i> Edit Profile</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="/admin/api/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
    </ul>
</div>
<script>
// Self-contained close-on-outside-click — safe to run multiple times if this
// component appears more than once accidentally (guarded by a flag).
if (!window.__headerUserMenuBound) {
    window.__headerUserMenuBound = true;
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.header-user-menu.show').forEach(function (menu) {
            if (!menu.parentElement.contains(e.target)) menu.classList.remove('show');
        });
    });
}
</script>
