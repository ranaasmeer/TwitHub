<?php
require_once __DIR__ . '/../core/init.php';

//  Check if user came from blocked redirect
if(isset($_SESSION['login_error'])) {
    $blockError = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

function sendOTP($email, $code){
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try{
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = '';
        $mail->Password = '';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('','Twitter Clone');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Your Password Reset OTP';
        $mail->Body = "Your OTP is: <b>$code</b>. It expires in 2 minutes.";

        $mail->send();
        return true;
    }catch(Exception $e){
        return false;
    }
}

if(isset($_POST['forgot_submit'])){
    $email = $_POST['email'];
    $conn = Connect::connect();
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$user){
        $_SESSION['fp_error'] = "Email not found!";
    } else {
        $otp = rand(100000,999999);
        $_SESSION['fp_email'] = $email;
        $_SESSION['fp_otp'] = $otp;
        $_SESSION['fp_otp_expiry'] = time() + 120;
        $_SESSION['fp_resend'] = true;

        if(sendOTP($email,$otp)){
            $_SESSION['fp_show_otp'] = true;
        } else {
            $_SESSION['fp_error'] = "Failed to send OTP. Please try again.";
        }
    }
    header("Location: " . BASE_URL . "?show_fp=1");
    exit();
}

if(isset($_POST['resend_otp'])){
    if(isset($_SESSION['fp_resend']) && $_SESSION['fp_resend'] == true){
        $otp = rand(100000,999999);
        $_SESSION['fp_otp'] = $otp;
        $_SESSION['fp_otp_expiry'] = time() + 120;
        $_SESSION['fp_resend'] = false;
        sendOTP($_SESSION['fp_email'], $otp);
        $_SESSION['fp_success'] = "OTP resent successfully!";
    } else {
        $_SESSION['fp_error'] = "You can only resend OTP once!";
    }
    header("Location: " . BASE_URL . "?show_fp=1");
    exit();
}

if(isset($_POST['verify_otp'])){
    $entered = $_POST['otp'];
    if(time() > $_SESSION['fp_otp_expiry']){
        $_SESSION['fp_error'] = "OTP expired! Please request again.";
    } elseif($entered == $_SESSION['fp_otp']){
        $_SESSION['fp_show_new_pass'] = true;
    } else {
        $_SESSION['fp_error'] = "Incorrect OTP!";
    }
    header("Location: " . BASE_URL . "?show_fp=1");
    exit();
}

if(isset($_POST['save_new_password'])){
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if($new_password !== $confirm_password) {
        $_SESSION['fp_error'] = "Passwords do not match!";
        header("Location: " . BASE_URL . "?show_fp=1");
        exit();
    }
    
    if(strlen($new_password) < 6) {
        $_SESSION['fp_error'] = "Password must be at least 6 characters!";
        header("Location: " . BASE_URL . "?show_fp=1");
        exit();
    }
    
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $email = $_SESSION['fp_email'];
    
    $conn = Connect::connect();
    
    $stmt = $conn->prepare("UPDATE users SET password = :password WHERE email = :email");
    $update = $stmt->execute([
        'password' => $hashed_password,
        'email' => $email
    ]);
    
    if($update){
        $stmt = $conn->prepare("SELECT password FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($user && password_verify($new_password, $user['password'])) {
            unset($_SESSION['fp_email'], $_SESSION['fp_otp'], $_SESSION['fp_otp_expiry'], 
                  $_SESSION['fp_resend'], $_SESSION['fp_show_otp'], $_SESSION['fp_show_new_pass']);
            
            $_SESSION['fp_success'] = "Password updated successfully! Please login with your new password.";
        } else {
            $_SESSION['fp_error'] = "Password update failed. Please try again.";
        }
    } else {
        $_SESSION['fp_error'] = "Database error: Could not update password. Please try again.";
    }
    
    header("Location: " . BASE_URL);
    exit();
}

if(isset($_GET['cancel_fp'])){
    unset($_SESSION['fp_email'], $_SESSION['fp_otp'], $_SESSION['fp_otp_expiry'], 
          $_SESSION['fp_resend'], $_SESSION['fp_show_otp'], $_SESSION['fp_show_new_pass'],
          $_SESSION['fp_error'], $_SESSION['fp_success']);
    header("Location: " . BASE_URL);
    exit();
}
?>

<style>
/* Enhanced Login Styles */
.login-box {
    background: transparent;
    width: 100%;
    max-width: 650px;
}

.input-box {
    width: 100%;
    padding: 12px 15px;
    margin: 10px 0;
    border: 2px solid #e1e8ed;
    border-radius: 10px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.input-box:focus {
    border-color: #1DA1F2;
    outline: none;
    box-shadow: 0 0 0 3px rgba(29, 161, 242, 0.1);
}

.login-btn {
    width: 100%;
    padding: 12px;
    background: linear-gradient(135deg, #1DA1F2, #0c85d0);
    color: white;
    border: none;
    border-radius: 30px;
    font-weight: bold;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-top: 10px;
}

.login-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(29, 161, 242, 0.3);
}

.login-btn:active {
    transform: translateY(0);
}
.forgot-link {
    cursor: pointer;
    color: #1DA1F2;
    font-size: 14px;
    text-decoration: none;
    display: inline-block;
    margin: 10px 0;
    transition: all 0.3s ease;
}

.forgot-link:hover {
    text-decoration: underline;
    transform: translateX(5px);
}

/* Modal Styles */
.modal-content {
    border-radius: 15px;
    border: none;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    animation: modalFadeIn 0.3s ease;
}

@keyframes modalFadeIn {
    from {
        transform: scale(0.9);
        opacity: 0;
    }
    to {
        transform: scale(1);
        opacity: 1;
    }
}

.modal-header {
    background: linear-gradient(135deg, #1DA1F2, #0c85d0);
    color: white;
    border-radius: 15px 15px 0 0;
    border-bottom: none;
}

.modal-header .close {
    color: white;
    opacity: 1;
    text-shadow: none;
}

.modal-header .close:hover {
    opacity: 0.8;
}

.modal-title {
    font-weight: bold;
}

.fp-timer {
    font-size: 14px;
    color: #ff4444;
    margin-top: 15px;
    text-align: center;
    font-weight: bold;
}

.btn-modal {
    border-radius: 30px;
    padding: 10px;
    font-weight: bold;
    transition: all 0.3s ease;
}

.btn-modal:hover {
    transform: translateY(-2px);
}

.email-highlight {
    background: #f0f2f5;
    padding: 8px;
    border-radius: 8px;
    font-weight: bold;
    color: #1DA1F2;
    text-align: center;
    margin: 10px 0;
}

/* Alert Styles */
.alert-custom {
    padding: 10px 15px;
    border-radius: 8px;
    margin-bottom: 10px;
    font-size: 14px;
    animation: slideIn 0.3s ease;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border-left: 4px solid #28a745;
}

.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border-left: 4px solid #dc3545;
}

@keyframes slideIn {
    from {
        transform: translateY(-20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.password-requirements {
    font-size: 12px;
    color: #657786;
    margin-top: 5px;
    text-align: left;
}

.password-match-error {
    color: #dc3545;
    font-size: 12px;
    margin-top: 5px;
    text-align: left;
    display: none;
}
</style>

<!-- Original Login Form -->
<form action="<?php echo BASE_URL; ?>handle/handlelogin.php" method="POST" class="login-box">
    <input class="input-box" name="email" type="email" placeholder="Email" required>
    <input class="input-box" name="password" type="password" placeholder="Password" required>
    <span class="forgot-link" onclick="openForgotModal()">🔐 Forgot password?</span>
    <input type="submit" name="login" class="login-btn" value="Log In"> 
    
    <div class="con">
        <?php 
        // ✅ Show block error if exists (from init.php redirect)
        if(isset($blockError)) {
            echo '<div class="alert-custom alert-danger" style="margin-top: 10px;">'.$blockError.'</div>';
        }
        
        if(isset($_SESSION['errors'])) {
            foreach ($_SESSION['errors'] as $error) {
                echo '<div class="alert-custom alert-danger" style="margin-top: 10px;">'.$error.'</div>';
            }
            unset($_SESSION['errors']);  
        } 
        
        if(isset($_SESSION['fp_error'])) {
            echo '<div class="alert-custom alert-danger" style="margin-top: 10px;">'.$_SESSION['fp_error'].'</div>';
            unset($_SESSION['fp_error']);
        }
        
        if(isset($_SESSION['fp_success'])) {
            echo '<div class="alert-custom alert-success" style="margin-top: 10px;">'.$_SESSION['fp_success'].'</div>';
            unset($_SESSION['fp_success']);
        }
        ?>
    </div>
</form>

<!-- Forgot Password Modal -->
<div id="forgotModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">🔐 Reset Password</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <?php if(!isset($_SESSION['fp_show_otp']) && !isset($_SESSION['fp_show_new_pass'])): ?>
                    <p>Enter your email address and we'll send you a verification code.</p>
                    <form action="<?php echo BASE_URL; ?>includes/login.php" method="POST">
                        <input type="email" name="email" class="form-control" placeholder="Email address" required autofocus>
                        <button type="submit" name="forgot_submit" class="btn btn-primary btn-modal w-100 mt-3">Send OTP</button>
                    </form>
                    
                <?php elseif(isset($_SESSION['fp_show_otp']) && !isset($_SESSION['fp_show_new_pass'])): ?>
                    <p>We've sent a verification code to:</p>
                    <div class="email-highlight"><?php echo $_SESSION['fp_email']; ?></div>
                    <form action="<?php echo BASE_URL; ?>includes/login.php" method="POST">
                        <input type="text" name="otp" class="form-control" placeholder="Enter 6-digit OTP" maxlength="6" required autofocus>
                        <button type="submit" name="verify_otp" class="btn btn-primary btn-modal w-100 mt-3">Verify OTP</button>
                    </form>
                    <?php if($_SESSION['fp_resend']): ?>
                        <form action="<?php echo BASE_URL; ?>includes/login.php" method="POST">
                            <button type="submit" name="resend_otp" class="btn btn-secondary btn-modal w-100 mt-2">Resend OTP</button>
                        </form>
                    <?php endif; ?>
                    <div class="fp-timer" id="fpTimer"></div>
                    
                <?php elseif(isset($_SESSION['fp_show_new_pass'])): ?>
                    <p>Create a new password for your account.</p>
                    <form action="<?php echo BASE_URL; ?>includes/login.php" method="POST" id="resetPasswordForm">
                        <input type="password" name="new_password" id="new_password" class="form-control mb-2" placeholder="New password" required>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control mb-2" placeholder="Confirm password" required>
                        <div class="password-match-error" id="passwordMatchError">❌ Passwords do not match!</div>
                        <div class="password-requirements">Password must be at least 6 characters</div>
                        <button type="submit" name="save_new_password" id="savePasswordBtn" class="btn btn-success btn-modal w-100 mt-3">Save Changes</button>
                    </form>
                <?php endif; ?>
                
                <a href="<?php echo BASE_URL; ?>includes/login.php?cancel_fp=1" class="d-block text-center mt-3" style="color: #1DA1F2; text-decoration: none;">← Back to Login</a>
            </div>
        </div>
    </div>
</div>

<script>
let fpCountdown = 120;

function startFPTimer() {
    const timerEl = document.getElementById('fpTimer');
    if(timerEl) {
        const interval = setInterval(() => {
            if(fpCountdown <= 0) {
                clearInterval(interval);
                timerEl.innerHTML = "⏰ OTP Expired! Please request again.";
                timerEl.style.color = "#dc3545";
            } else {
                let min = Math.floor(fpCountdown / 60);
                let sec = fpCountdown % 60;
                timerEl.innerHTML = "⏰ OTP expires in: " + min + ":" + (sec < 10 ? '0' + sec : sec);
                fpCountdown--;
            }
        }, 1000);
    }
}

function openForgotModal() {
    $('#forgotModal').modal({
        backdrop: 'static',
        keyboard: false
    });
    $('#forgotModal').modal('show');
    
    <?php if(isset($_SESSION['fp_show_otp']) && !isset($_SESSION['fp_show_new_pass'])): ?>
    startFPTimer();
    <?php endif; ?>
}

<?php if(isset($_GET['show_fp']) || isset($_SESSION['fp_email']) || isset($_SESSION['fp_show_otp']) || isset($_SESSION['fp_show_new_pass'])): ?>
$(document).ready(function() {
    setTimeout(function() {
        openForgotModal();
    }, 100);
});
<?php endif; ?>

$(document).ready(function() {
    // Real-time password match validation
    $('#new_password, #confirm_password').on('keyup', function() {
        const newPass = $('#new_password').val();
        const confirmPass = $('#confirm_password').val();
        
        if(newPass !== confirmPass && confirmPass !== '') {
            $('#passwordMatchError').show();
            $('#savePasswordBtn').prop('disabled', true);
        } else {
            $('#passwordMatchError').hide();
            $('#savePasswordBtn').prop('disabled', false);
        }
    });
    
    // Form submission validation
    $('#resetPasswordForm').on('submit', function(e) {
        const newPass = $('#new_password').val();
        const confirmPass = $('#confirm_password').val();
        
        if(newPass !== confirmPass) {
            e.preventDefault();
            $('#passwordMatchError').show();
            alert("❌ Passwords do not match!");
            return false;
        } else if(newPass.length < 6) {
            e.preventDefault();
            alert("❌ Password must be at least 6 characters!");
            return false;
        }
        return true;
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        $('.alert-custom').fadeOut('slow');
    }, 5000);
});
</script>