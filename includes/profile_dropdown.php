<?php
// Profile Dropdown Menu Component
// Include this file where you want the profile dropdown to appear
// Replace the existing .box-user div with this include

if (!isset($user) || !isset($user_id)) {
    // If $user is not set, try to get it
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $user = User::getData($user_id);
    }
}
?>

<style>
/* ========== PROFILE DROPDOWN MENU STYLES ========== */
.profile-dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-menu-custom {
    position: absolute;
    bottom: 100%;
    left: 0;
    background: white;
    min-width: 220px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    border-radius: 16px;
    overflow: hidden;
    z-index: 10000;
    display: none;
    margin-bottom: 10px;
    border: 1px solid #e6ecf0;
}

.dropdown-menu-custom.show {
    display: block;
    animation: fadeInUp 0.2s ease;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.dropdown-item-custom {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: #14171a;
    text-decoration: none;
    transition: background 0.2s;
    font-size: 14px;
}

.dropdown-item-custom:hover {
    background: #f5f8fa;
    text-decoration: none;
    color: #1DA1F2;
}

.dropdown-item-custom.danger {
    color: #e0245e;
}

.dropdown-item-custom.danger:hover {
    background: #fce8e8;
    color: #e0245e;
}

.dropdown-item-custom i {
    width: 20px;
    font-size: 16px;
}

/* Make the arrow clickable */
.mt-arrow {
    cursor: pointer;
}

.box-user {
    position: fixed;
    right: 0;
    bottom: 10px;
    left: 60px;
    cursor: pointer;
}

/* Mobile responsive */
@media only screen and (max-width: 800px) {
    .dropdown-menu-custom {
        bottom: auto;
        top: 100%;
        margin-bottom: 0;
        margin-top: 10px;
    }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
}


/* ============================================================
   FINAL PROFILE ARROW ALIGNMENT FIX
   Keeps arrow beside profile info even when zooming/zooming out.
   Preserves dropdown functionality.
   ============================================================ */

.profile-dropdown.box-user,
.box-user.profile-dropdown{
    width:230px !important;
    max-width:230px !important;
    min-width:0 !important;
    right:auto !important;
    left:60px !important;
    bottom:10px !important;
    box-sizing:border-box !important;
}

.profile-dropdown .grid-user{
    display:grid !important;
    grid-template-columns:44px minmax(0, 1fr) 24px !important;
    align-items:center !important;
    column-gap:10px !important;
    width:100% !important;
    max-width:100% !important;
    min-width:0 !important;
    padding:10px 12px !important;
    box-sizing:border-box !important;
}

.profile-dropdown .grid-user > div{
    min-width:0 !important;
}

.profile-dropdown .img-user{
    width:44px !important;
    height:44px !important;
    min-width:44px !important;
    border-radius:50% !important;
    object-fit:cover !important;
}

.profile-dropdown .grid-user .name,
.profile-dropdown .grid-user .username{
    max-width:100% !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    white-space:nowrap !important;
    margin:0 !important;
}

.profile-dropdown .mt-arrow{
    width:24px !important;
    min-width:24px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    margin-left:0 !important;
    justify-self:end !important;
}

.profile-dropdown .mt-arrow i{
    display:block !important;
    font-size:14px !important;
    line-height:1 !important;
}

@media only screen and (max-width:1200px){
    .profile-dropdown.box-user,
    .box-user.profile-dropdown{
        width:210px !important;
        max-width:210px !important;
    }

    .profile-dropdown .grid-user{
        grid-template-columns:40px minmax(0, 1fr) 22px !important;
        column-gap:8px !important;
        padding:9px 10px !important;
    }

    .profile-dropdown .img-user{
        width:40px !important;
        height:40px !important;
        min-width:40px !important;
    }

    .profile-dropdown .mt-arrow{
        width:22px !important;
        min-width:22px !important;
    }
}

@media only screen and (max-width:800px){
    .profile-dropdown.box-user,
    .box-user.profile-dropdown{
        position:relative !important;
        left:auto !important;
        right:auto !important;
        bottom:auto !important;
        width:100% !important;
        max-width:100% !important;
    }

    .profile-dropdown .grid-user{
        grid-template-columns:40px minmax(0, 1fr) 22px !important;
    }
}

</style>

<!-- Profile box with dropdown for Logout -->
<div class="box-user profile-dropdown">
    <div class="grid-user" id="profileDropdownBtn" style="cursor: pointer;">
        <div>
            <img src="assets/images/users/<?php echo $user->img; ?>" alt="user" class="img-user" />
        </div>
        <div>
            <p class="name"><strong><?php echo $user->name; ?></strong></p>
            <p class="username">@<?php echo $user->username; ?></p>
        </div>
        <div class="mt-arrow" id="profileDropdownArrow">
            <i class="fas fa-chevron-down" style="font-size: 14px; color: #657786;"></i>
        </div>
    </div>
    
    <!-- Dropdown menu -->
    <div class="dropdown-menu-custom" id="profileDropdownMenu">
        <a href="<?php echo BASE_URL . $user->username; ?>" class="dropdown-item-custom">
            <i class="fas fa-user"></i> Profile
        </a>
        <a href="<?php echo BASE_URL . "account.php"; ?>" class="dropdown-item-custom">
            <i class="fas fa-cog"></i> Settings
        </a>
        <div class="dropdown-divider" style="height: 1px; background: #e6ecf0; margin: 4px 0;"></div>
        <a href="<?php echo BASE_URL . "includes/logout.php"; ?>" class="dropdown-item-custom danger">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</div>

<script>
// Dropdown functionality for profile menu - Using pure JavaScript to avoid jQuery conflicts
(function() {
    // Wait for DOM to be ready
    function initDropdown() {
        var dropdownBtn = document.getElementById('profileDropdownBtn');
        var dropdownArrow = document.getElementById('profileDropdownArrow');
        var dropdownMenu = document.getElementById('profileDropdownMenu');
        
        if (!dropdownBtn || !dropdownMenu) {
            // If elements not found, try again after a short delay
            setTimeout(initDropdown, 100);
            return;
        }
        
        // Toggle dropdown ONLY when clicking arrow
        if (dropdownArrow) {
            dropdownArrow.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdownMenu.classList.toggle('show');
            });
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!dropdownBtn.contains(e.target) && !dropdownMenu.contains(e.target)) {
                dropdownMenu.classList.remove('show');
            }
        });
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && dropdownMenu.classList.contains('show')) {
                dropdownMenu.classList.remove('show');
            }
        });
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDropdown);
    } else {
        initDropdown();
    }
})();
</script>