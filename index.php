<?php 
 
  
    include 'core/init.php' ;
    
    if (isset($_SESSION['user_id'])) {
      header('location: home.php');
    }
    // Clear signup restart
if(isset($_GET['restart'])){
    unset($_SESSION['temp_signup'], $_SESSION['show_verification'], 
          $_SESSION['signup_error'], $_SESSION['signup_success']);
    header("Location: " . BASE_URL);
    exit();
}
   
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>TwitterClone</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.css"/>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/index_style.css?v=<?php echo time(); ?>">
    <link rel="shortcut icon" type="image/png" href="assets/images/twitter.svg"> 
</head>
<body>
<main class="twt-main">
    <section class="twt-login">
        <div class="above-login-text">
            <span class="front-para">See what's happening in the world right now</span>
            <span class="join">Join TwitterClone Today.</span>
        </div>

        <?php include 'includes/login.php';  ?>

        <div class="slow-login">
            <img class="login-bird-source" src="<?php echo BASE_URL . "/assets/images/twitter-logo.png"; ?>" alt="bird">
            <button class="login-small-display signin-btn pri-btn" onclick="openLoginModal()">Log in</button>
            
            <!-- Signup Modal -->
            <div class="modal fade" id="exampleModalCenter" tabindex="-1" role="dialog" aria-labelledby="exampleModalCenterTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 style="font-weight: 700;" class="modal-title" id="exampleModalLongTitle">Sign Up For Free</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <?php include 'includes/signup-form.php'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <section class="twt-features">
        <div class="features-div">
            <p><img class="twt-icon" src='https://image.ibb.co/bzvrkp/search_icon.png'> Follow your interests.</p>
            <p><img class="twt-icon" src="https://image.ibb.co/mZPTWU/heart_icon.png"> Hear what people are talking about.</p>
            <p><img class="twt-icon" src="https://image.ibb.co/kw2Ad9/conv_icon.png"> Join the conversation.</p>
        </div>
    </section>
    
    <footer>
        <ul>
            <li><a href="#">About</a></li>
            <li><a href="#">Help Center</a></li>
            <li><a href="#">Terms</a></li>
            <li><a href="#">Privacy Policy</a></li>
            <li><a href="#">Cookies</a></li>
            <li><a href="#">Ads info</a></li>
            <li><a href="#">Brand</a></li>
            <li><a href="#">Advertise</a></li>
            <li><a href="#">Developers</a></li>
            <li><a href="#">Settings</a></li>
            <li>© 2021 - Twitter Clone</li>
            <li style="color:#1DA1F2;"><b>- Developed By Sam & Hannan -</b></li>
        </ul>
    </footer>
</main>

<script src="assets/js/jquery-3.5.1.min.js"></script>
<script src="assets/js/popper.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>

<script>
/**
 * 1. MODAL OPENER FUNCTIONS
 */
function openForgotModal() {
    $('#forgotModal').modal({
        backdrop: 'static',
        keyboard: false
    });
    $('#forgotModal').modal('show');
    
    // Start timer if on OTP step
    <?php if(isset($_SESSION['fp_show_otp']) && !isset($_SESSION['fp_show_new_pass'])): ?>
        if(typeof startFPTimer === "function") {
            startFPTimer();
        }
    <?php endif; ?>
}

function openSignupModal() {
    $('#exampleModalCenter').modal({
        backdrop: 'static',
        keyboard: false
    });
    $('#exampleModalCenter').modal('show');
}

/**
 * 2. AUTO-OPEN LOGIC (Handles Page Refreshes)
 */
$(document).ready(function() {
    
    // A. Check for Forgot Password states
    <?php if(isset($_GET['show_fp']) || isset($_SESSION['fp_show_otp']) || isset($_SESSION['fp_show_new_pass']) || isset($_SESSION['fp_error'])): ?>
        setTimeout(function() {
            openForgotModal();
        }, 300);
    <?php endif; ?>

    // B. Check for Signup states (This fixes your signup issue)
    <?php if(isset($_GET['show_otp']) || isset($_SESSION['show_verification']) || isset($_SESSION['signup_error'])): ?>
        setTimeout(function() {
            openSignupModal();
        }, 300);
    <?php endif; ?>

    // C. Clean URL parameters after 1.5 seconds
    if(window.location.href.indexOf('show_fp') > -1 || window.location.href.indexOf('show_otp') > -1) {
        setTimeout(function() {
            window.history.replaceState({}, document.title, "<?php echo BASE_URL; ?>");
        }, 1500);
    }
});

/**
 * 3. TWITTER UI FIX (Injects Logo and Signup Button)
 */
$(document).ready(function() {
    const loginBox = $('.login-box').first();
    if (loginBox.length) {
        // Inject Sparrow Logo
        if (!loginBox.find('.login-box-bird').length) {
            loginBox.prepend('<img class="login-box-bird" src="<?php echo BASE_URL . "/assets/images/twitter-logo.png"; ?>" alt="Twitter">');
        }
        // Inject Signup Button
        if (!loginBox.find('.login-box-signup').length) {
            loginBox.append('<button type="button" class="login-box-signup" onclick="openSignupModal()">Sign up</button>');
        }
    }
});
</script>