<?php
session_start();
if (isset($_SESSION['user_email'])) {
    header("Location: dashboard.php");
    exit();
}

// Define the database configuration path
$database_path = __DIR__ . '/../config/database.php';

// Include database connection file
if (file_exists($database_path)) {
    include $database_path;
} else {
    echo "Error: Could not find database connection file at: " . $database_path;
    exit();
}

// Include PHPMailer for email sending
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php'; // Adjust path if PHPMailer is installed manually

$error = "";
$success = "";
$show_setup = false;
$show_login = true;
$show_reset_verify = false;
$reset_email = '';
$reset_user_id = 0;

// Utility function to generate OTP
function generateOTP() {
    return rand(100000, 999999);
}

// Function to send OTP via Email using PHPMailer
function sendOTPViaEmail($email, $otp) {
    $mail = new PHPMailer(true);
    try {
        // Disable debugging for production
        $mail->SMTPDebug = 0; // 0 = off (no debug output)

        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; // Gmail SMTP server
        $mail->SMTPAuth = true;
        $mail->Username = 'mdsaminyasarsaadat@gmail.com'; // Your Gmail address
        $mail->Password = '123'; // Replace with your Gmail App Password (NOT your regular password)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('mdsaminyasarsaadat@gmail.com', 'Your App Name');
        $mail->addAddress($email);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your OTP for Password Reset';
        $mail->Body = "Dear User,<br><br>Your OTP for password reset is <b>$otp</b>. It expires in 10 minutes. Do not share this code.<br><br>Best regards,<br>Your App Team";
        $mail->AltBody = "Your OTP for password reset is $otp. It expires in 10 minutes. Do not share this code.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return "Failed to send email: {$mail->ErrorInfo}";
    }
}

// Function to send OTP (only via email)
function sendOTP($email, $otp) {
    $emailResult = sendOTPViaEmail($email, $otp);
    if ($emailResult === true) {
        return true;
    } else {
        return "Failed to send OTP: $emailResult";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['check_user'])) {
        if (isset($_POST['email'])) {
            $email = mysqli_real_escape_string($conn, $_POST['email']);
        } else {
            $email = "";
        }

        $sql = "SELECT * FROM users WHERE email='$email'";
        $result = mysqli_query($conn, $sql);

        if ($result === false) {
            $error = "Database error: " . mysqli_error($conn);
            $show_login = true;
        } elseif (mysqli_num_rows($result) == 0) {
            $show_setup = true;
            $show_login = false;
        } else {
            $show_login = true;
        }
    } elseif (isset($_POST['setup'])) {
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        $password = mysqli_real_escape_string($conn, $_POST['password']);

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format.";
            $show_setup = true;
            $show_login = false;
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $sql = "INSERT INTO users (name, email, mobile_number, password, role) VALUES ('$name', '$email', '$phone', '$hashed_password', 'user')";

            if (mysqli_query($conn, $sql)) {
                $success = "Account created successfully! Please login.";
                $show_login = true;
                $show_setup = false;
            } else {
                $error = "Error creating account: " . mysqli_error($conn);
                $show_setup = true;
                $show_login = false;
            }
        }
    } elseif (isset($_POST['login'])) {
        if (isset($_POST['email'])) {
            $email = mysqli_real_escape_string($conn, $_POST['email']);
        } else {
            $email = "";
        }

        $password = mysqli_real_escape_string($conn, $_POST['password']);

        $sql = "SELECT * FROM users WHERE email='$email'";
        $result = mysqli_query($conn, $sql);

        if ($result === false) {
            $error = "Database error: " . mysqli_error($conn);
            $show_login = true;
        } else if (mysqli_num_rows($result) == 1) {
            $row = mysqli_fetch_assoc($result);
            if (password_verify($password, $row['password'])) {
                $_SESSION['user_email'] = $row['email'];
                $_SESSION['user_role'] = $row['role'];
                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "Account not found. Please check your email.";
        }
    } elseif (isset($_POST['forgot_password'])) {
        // Get the email from the login form
        if (isset($_POST['email']) && !empty($_POST['email'])) {
            $reset_email = mysqli_real_escape_string($conn, $_POST['email']);
        } else {
            $error = "Please enter your email to reset your password.";
            $show_login = true;
        }

        if (!empty($reset_email)) {
            // Validate email format
            if (!filter_var($reset_email, FILTER_VALIDATE_EMAIL)) {
                $error = "Invalid email format.";
                $show_login = true;
            } else {
                $sql = "SELECT * FROM users WHERE email='$reset_email'";
                $result = mysqli_query($conn, $sql);

                if ($result === false) {
                    $error = "Database error: " . mysqli_error($conn);
                    $show_login = true;
                } else if (mysqli_num_rows($result) == 1) {
                    $row = mysqli_fetch_assoc($result);
                    $otp = generateOTP();
                    $_SESSION['reset_otp'] = $otp;
                    $_SESSION['reset_user_id'] = $row['id'];
                    $_SESSION['otp_expiry'] = time() + 600; // OTP expires in 10 minutes

                    $sendResult = sendOTP($row['email'], $otp);
                    if ($sendResult === true) {
                        $success = "OTP sent to your email. Please check your inbox (and spam/junk folder).";
                        $show_login = false;
                        $show_reset_verify = true;
                        $reset_email = $row['email'];
                    } else {
                        $error = $sendResult;
                        $show_login = true;
                    }
                } else {
                    $error = "Email not found. Please check your email or sign up.";
                    $show_login = true;
                }
            }
        }
    } elseif (isset($_POST['reset_verify'])) {
        $otp_entered = mysqli_real_escape_string($conn, $_POST['otp']);
        $new_password = mysqli_real_escape_string($conn, $_POST['new_password']);

        if (!isset($_SESSION['reset_otp']) || !isset($_SESSION['otp_expiry'])) {
            $error = "Session expired. Please request a new OTP.";
            $show_login = true;
            $show_reset_verify = false;
        } elseif (time() > $_SESSION['otp_expiry']) {
            $error = "OTP has expired. Please request a new OTP.";
            $show_login = true;
            $show_reset_verify = false;
            unset($_SESSION['reset_otp']);
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['otp_expiry']);
        } elseif ($_SESSION['reset_otp'] == $otp_entered) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $user_id = $_SESSION['reset_user_id'];
            $sql = "UPDATE users SET password='$hashed_password' WHERE id=$user_id";
            if (mysqli_query($conn, $sql)) {
                $success = "Password reset successfully! Please login with your new password.";
                unset($_SESSION['reset_otp']);
                unset($_SESSION['reset_user_id']);
                unset($_SESSION['otp_expiry']);
                $show_login = true;
                $show_reset_verify = false;
            } else {
                $error = "Error resetting password: " . mysqli_error($conn);
                $show_reset_verify = true;
            }
        } else {
            $error = "Invalid OTP. Please try again.";
            $show_reset_verify = true;
        }
    } elseif (isset($_POST['resend_otp'])) {
        if (isset($_SESSION['reset_user_id'])) {
            $user_id = $_SESSION['reset_user_id'];
            $sql = "SELECT * FROM users WHERE id=$user_id";
            $result = mysqli_query($conn, $sql);
            if ($result && mysqli_num_rows($result) == 1) {
                $row = mysqli_fetch_assoc($result);
                $otp = generateOTP();
                $_SESSION['reset_otp'] = $otp;
                $_SESSION['otp_expiry'] = time() + 600;

                $sendResult = sendOTP($row['email'], $otp);
                if ($sendResult === true) {
                    $success = "OTP resent to your email. Please check your inbox (and spam/junk folder).";
                    $show_reset_verify = true;
                } else {
                    $error = $sendResult;
                    $show_reset_verify = true;
                }
            } else {
                $error = "User not found. Please request a new OTP.";
                $show_login = true;
                $show_reset_verify = false;
            }
        } else {
            $error = "Session expired. Please request a new OTP.";
            $show_login = true;
            $show_reset_verify = false;
        }
    } elseif (isset($_POST['back_to_login'])) {
        $show_login = true;
        $show_reset_verify = false;
        unset($_SESSION['reset_otp']);
        unset($_SESSION['reset_user_id']);
        unset($_SESSION['otp_expiry']);
    }
}

if (isset($conn)) {
    mysqli_close($conn);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
            color: #1f2937;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }

        .container {
            width: 100%;
            max-width: 400px;
            padding: 2rem;
            background-color: #fff;
            border-radius: 0.75rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 1px solid #e5e7eb;
        }

        .container:hover {
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            transform: translateY(-4px);
        }

        h2 {
            text-align: center;
            margin-bottom: 2rem;
            color: #1e3a8a;
            font-weight: 700;
            font-size: 2.25rem;
            letter-spacing: -0.025em;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #4b5563;
            font-weight: 600;
            font-size: 0.875rem;
            transition: color 0.3s ease;
        }

        label:focus-within {
            color: #1e40af;
        }

        input {
            width: 100%;
            padding: 0.8rem;
            border-radius: 0.5rem;
            border: 1px solid #d1d5db;
            font-size: 1rem;
            transition: all 0.3s ease;
            outline: none;
            background-color: #f9fafb;
            color: #1f2937;
        }

        input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
            background-color: #eff6ff;
        }

        .btn-primary {
            width: 100%;
            padding: 0.8rem;
            border-radius: 0.5rem;
            background-color: #3b82f6;
            color: #fff;
            font-size: 1.1rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .btn-primary:hover {
            background-color: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-primary:active {
            background-color: #1e40af;
            transform: translateY(0);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .btn-secondary {
            width: 100%;
            padding: 0.8rem;
            border-radius: 0.5rem;
            background-color: #e5e7eb;
            color: #374151;
            font-size: 1.1rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .btn-secondary:hover {
            background-color: #d1d5db;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-secondary:active {
            background-color: #b0b5ba;
            transform: translateY(0);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .alert {
            margin-bottom: 1.5rem;
            padding: 1rem;
            border-radius: 0.5rem;
            font-size: 0.9rem;
            font-weight: 400;
            border: 1px solid transparent;
            background-color: transparent;
            color: #1f2937;
        }

        .alert-danger {
            background-color: #fee2e2;
            border-color: #fecaca;
            color: #b91c1c;
        }

        .alert-success {
            background-color: #f0fdf4;
            border-color: #d1fae5;
            color: #0f766e;
        }

        .text-center {
            text-align: center;
        }

        .mt-3 {
            margin-top: 1rem;
        }

        .forgot-password {
            display: block;
            text-align: center;
            margin-top: 1rem;
            color: #3b82f6;
            font-size: 0.9rem;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .forgot-password:hover {
            color: #2563eb;
        }

        .setup-提示 {
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 0.5rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($show_login): ?>
        <h2>Login</h2>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" placeholder="Enter your Email" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" class="form-control" required>
            </div>
            <button type="submit" name="login" class="btn btn-primary">Login</button>
            <button type="submit" name="forgot_password" class="btn btn-secondary mt-3">Forgot Password</button>
        </form>
        <form method="post" class="mt-3">
            <button type="submit" name="check_user" class="btn btn-secondary">First Time Login</button>
        </form>
        <?php endif; ?>

        <?php if ($show_setup): ?>
        <h2>First Time Setup</h2>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" name="name" id="name" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="phone">Phone Number (Optional)</label>
                <input type="text" name="phone" id="phone" class="form-control" placeholder="e.g., 01712345678">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" class="form-control" required>
            </div>
            <button type="submit" name="setup" class="btn btn-primary">Setup Account</button>
        </form>
        <?php endif; ?>

        <?php if ($show_reset_verify): ?>
            <h2>Verify OTP</h2>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="form-group">
                    <label for="otp">Enter OTP sent to <?php echo htmlspecialchars($reset_email); ?></label>
                    <input type="text" name="otp" id="otp" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" name="new_password" id="new_password" class="form-control" required>
                </div>
                <button type="submit" name="reset_verify" class="btn btn-primary">Reset Password</button>
            </form>
            <form method="post" class="mt-3">
                <button type="submit" name="resend_otp" class="btn btn-secondary">Resend OTP</button>
            </form>
            <form method="post" class="mt-3">
                <button type="submit" name="back_to_login" class="btn btn-secondary">Back to Login</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>