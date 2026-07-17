<?php 
require_once __DIR__ . '/../core/init.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendVerificationCode($email, $code) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'bebantest@gmail.com';
        $mail->Password = 'cvdp kiir bigt mtcq';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('bebantest@gmail.com', 'Twitter Clone');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Your Verification Code';
        $mail->Body = "Your verification code is: <b>$code</b>. It will expire in 2 minutes.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

if(isset($_POST['send_verification'])) {
    $name = $_POST['name'];
    $username = $_POST['username'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    $conn = Connect::connect();
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    if($stmt->rowCount() > 0){
        $_SESSION['signup_error'] = "Email already exists!";
        header("Location: " . BASE_URL);
        exit();
    }
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    if($stmt->rowCount() > 0){
        $_SESSION['signup_error'] = "Username already taken!";
        header("Location: " . BASE_URL);
        exit();
    }
    
    $code = rand(100000,999999);
    $_SESSION['temp_signup'] = [
        'name' => $name,
        'username' => $username,
        'email' => $email,
        'password' => $password,
        'otp' => $code,
        'expiry' => time() + 120
    ];
    
    if(sendVerificationCode($email, $code)){
        $_SESSION['show_verification'] = true;
        $_SESSION['signup_success'] = "Verification code sent!";
    } else {
        $_SESSION['signup_error'] = "Failed to send code!";
    }
    header("Location: " . BASE_URL . "?show_otp=1");
    exit();
}

if(isset($_POST['verify_signup'])) {
    $entered_code = $_POST['verification_code'];
    
    if(isset($_SESSION['temp_signup'])) {
        if(time() > $_SESSION['temp_signup']['expiry']) {
            $_SESSION['signup_error'] = "Code expired! Please try again.";
            unset($_SESSION['temp_signup'], $_SESSION['show_verification']);
        } elseif($entered_code == $_SESSION['temp_signup']['otp']) {
            $email = $_SESSION['temp_signup']['email'];
            $password = $_SESSION['temp_signup']['password'];
            $name = $_SESSION['temp_signup']['name'];
            $username = $_SESSION['temp_signup']['username'];
            
            User::register($email, $password, $name, $username);
            
            unset($_SESSION['temp_signup'], $_SESSION['show_verification']);
            $_SESSION['signup_success'] = "Registration successful! Please login.";
            header("Location: " . BASE_URL);
            exit();
        } else {
            $_SESSION['signup_error'] = "Incorrect code!";
        }
    } else {
        $_SESSION['signup_error'] = "No verification found!";
    }
    header("Location: " . BASE_URL . "?show_otp=1");
    exit();
}

if(isset($_GET['restart_signup'])){
    unset($_SESSION['temp_signup'], $_SESSION['show_verification'], 
          $_SESSION['signup_error'], $_SESSION['signup_success']);
    header("Location: " . BASE_URL);
    exit();
}
?>

<style>
/* Enhanced Signup Form Styles */
.signup-form-container {
    position: relative;
}

.form-group {
    margin-bottom: 20px;
    position: relative;
}

.form-group input {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e1e8ed;
    border-radius: 10px;
    font-size: 14px;
    transition: all 0.3s ease;
    background: #fff;
}

.form-group input:focus {
    border-color: #1DA1F2;
    outline: none;
    box-shadow: 0 0 0 3px rgba(29, 161, 242, 0.1);
}

.form-group label {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #657786;
    font-size: 14px;
    pointer-events: none;
    transition: all 0.3s ease;
    background: white;
    padding: 0 5px;
}

.form-group input:focus + label,
.form-group input:not(:placeholder-shown) + label {
    top: 0;
    font-size: 11px;
    color: #1DA1F2;
}

.btn-signup {
    background: linear-gradient(135deg, #1DA1F2, #0c85d0);
    color: white;
    border: none;
    padding: 12px;
    border-radius: 30px;
    font-weight: bold;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    width: 100%;
    margin-top: 10px;
}

.btn-signup:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(29, 161, 242, 0.3);
}

.btn-signup:active {
    transform: translateY(0);
}

.verification-box {
    text-align: center;
    padding: 20px;
}

.verification-box .email-display {
    background: #f0f2f5;
    padding: 10px;
    border-radius: 8px;
    margin: 15px 0;
    font-weight: bold;
    color: #1DA1F2;
}

.timer {
    font-size: 14px;
    color: #ff4444;
    margin-top: 15px;
    font-weight: bold;
}

.resend-btn {
    background: #6c757d;
    margin-top: 10px;
}

.resend-btn:hover {
    background: #5a6268;
    transform: translateY(-2px);
}

.restart-link {
    display: inline-block;
    margin-top: 15px;
    color: #1DA1F2;
    text-decoration: none;
    font-size: 14px;
}

.restart-link:hover {
    text-decoration: underline;
}

.alert-custom {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 15px;
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

.input-icon {
    position: relative;
}

.input-icon i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #657786;
}

.input-icon input {
    padding-left: 40px;
}
</style>

<?php if(isset($_SESSION['signup_error'])): ?>
    <div class="alert-custom alert-danger"><?php echo $_SESSION['signup_error']; unset($_SESSION['signup_error']); ?></div>
<?php endif; ?>

<?php if(!isset($_SESSION['show_verification']) && !isset($_SESSION['temp_signup'])): ?>
    <form action="<?php echo BASE_URL; ?>includes/signup-form.php" method="POST" class="signup-form-container">
        <div class="form-group">
            <input type="text" name="name" id="name" class="form-control" placeholder=" " required>
            <label for="name">Full Name</label>
        </div>
        <div class="form-group">
            <input type="text" name="username" id="username" class="form-control" placeholder=" " required>
            <label for="username">Username</label>
        </div>
        <div class="form-group">
            <input type="email" name="email" id="email" class="form-control" placeholder=" " required>
            <label for="email">Email Address</label>
        </div>
        <div class="form-group">
            <input type="password" name="password" id="password" class="form-control" placeholder=" " required>
            <label for="password">Password</label>
        </div>
        <button type="submit" name="send_verification" class="btn-signup">Sign Up</button>
    </form>
<?php else: ?>
    <div class="verification-box">
        <h5 style="color: #1DA1F2; margin-bottom: 20px;">Verify Your Email</h5>
        <p>We've sent a verification code to:</p>
        <div class="email-display">
            <?php echo $_SESSION['temp_signup']['email']; ?>
        </div>
        
        <form action="<?php echo BASE_URL; ?>includes/signup-form.php" method="POST">
            <div class="form-group">
                <input type="text" name="verification_code" class="form-control" placeholder="Enter 6-digit code" maxlength="6" required autofocus>
            </div>
            <button type="submit" name="verify_signup" class="btn-signup">Verify & Complete Registration</button>
        </form>
        
        <div class="timer" id="signupTimer"></div>
        
        <a href="<?php echo BASE_URL; ?>includes/signup-form.php?restart_signup=1" class="restart-link">← Start Over</a>
    </div>
    
    <script>
    let signupCountdown = 120;
    function startSignupTimer() {
        const timerEl = document.getElementById('signupTimer');
        if(timerEl) {
            const interval = setInterval(() => {
                if(signupCountdown <= 0) {
                    clearInterval(interval);
                    timerEl.innerHTML = "⏰ Code Expired! Please start over.";
                    timerEl.style.color = "#dc3545";
                } else {
                    let min = Math.floor(signupCountdown / 60);
                    let sec = signupCountdown % 60;
                    timerEl.innerHTML = "⏰ Code expires in: " + min + ":" + (sec < 10 ? '0' + sec : sec);
                    signupCountdown--;
                }
            }, 1000);
        }
    }
    startSignupTimer();
    </script>
<?php endif; ?>