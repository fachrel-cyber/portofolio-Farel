<?php
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$name    = trim($input['name'] ?? '');
$email   = trim($input['email'] ?? '');
$subject = trim($input['subject'] ?? '');
$message = trim($input['message'] ?? '');

// Validation
$errors = [];
if (empty($name)) {
    $errors[] = 'Nama wajib diisi';
}
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email tidak valid';
}
if (empty($message)) {
    $errors[] = 'Pesan tidak boleh kosong';
}
if (count($errors) > 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit;
}

// Anti-spam: rate limit - max 3 emails per minute from same IP
$ip = $_SERVER['REMOTE_ADDR'];
$rateFile = __DIR__ . '/cache/ratelimit_' . md5($ip) . '.txt';
if (!is_dir(__DIR__ . '/cache')) {
    mkdir(__DIR__ . '/cache', 0755, true);
}
$now = time();
$rateData = ['count' => 0, 'time' => $now];
if (file_exists($rateFile)) {
    $rateData = json_decode(file_get_contents($rateFile), true) ?: $rateData;
    if ($now - $rateData['time'] > 60) {
        $rateData = ['count' => 0, 'time' => $now];
    }
}
$rateData['count']++;
file_put_contents($rateFile, json_encode($rateData));
if ($rateData['count'] > 3) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Terlalu banyak permintaan. Silakan coba lagi nanti.']);
    exit;
}

// Anti-spam: honeypot field
if (!empty($input['website'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Spam detected']);
    exit;
}

// Build email body
$emailBody = "
<html>
<head><style>
body{font-family:'Inter',sans-serif;background:#0B1026;color:#fff;padding:2rem}
.container{max-width:600px;margin:0 auto;background:rgba(255,255,255,0.03);border:1px solid rgba(0,229,255,0.1);border-radius:16px;padding:2rem}
.header{font-family:'Space Grotesk',sans-serif;font-size:1.5rem;font-weight:700;background:linear-gradient(135deg,#00E5FF,#FF5DA2);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:1px solid rgba(0,229,255,0.1)}
.field{margin-bottom:1rem}
.field-label{color:#00E5FF;font-size:0.8rem;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-bottom:0.3rem}
.field-value{color:rgba(255,255,255,0.85);font-size:1rem;padding:0.5rem 0.8rem;background:rgba(255,255,255,0.03);border-radius:8px;border-left:2px solid rgba(0,229,255,0.2)}
.footer{margin-top:1.5rem;padding-top:1rem;border-top:1px solid rgba(0,229,255,0.1);font-size:0.75rem;color:rgba(255,255,255,0.4)}
</style></head>
<body>
<div class='container'>
<div class='header'>" . htmlspecialchars($subject ?: 'Pesan dari Portfolio') . "</div>
<div class='field'>
<div class='field-label'>Nama</div>
<div class='field-value'>" . htmlspecialchars($name) . "</div>
</div>
<div class='field'>
<div class='field-label'>Email</div>
<div class='field-value'>" . htmlspecialchars($email) . "</div>
</div>
<div class='field'>
<div class='field-label'>Subjek</div>
<div class='field-value'>" . htmlspecialchars($subject ?: '(Tidak ada subjek)') . "</div>
</div>
<div class='field'>
<div class='field-label'>Pesan</div>
<div class='field-value'>" . nl2br(htmlspecialchars($message)) . "</div>
</div>
<div class='footer'>
Pesan ini dikirim dari Portfolio Fachrel Amrillah
</div>
</div>
</body>
</html>
";

$plainText = "Nama: $name\nEmail: $email\nSubjek: " . ($subject ?: '-') . "\n\nPesan:\n$message";

// Send email via Gmail SMTP
$mail = new PHPMailer(true);

$smtpPassword = ''; // ISI APP PASSWORD GMAIL ANDA DI SINI

if (empty($smtpPassword)) {
    // Fallback: try PHP mail() if SMTP not configured
    $headers = "From: $email\r\n";
    $headers .= "Reply-To: $email\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    $mailSent = @mail('fachrel07juli@gmail.com', '[Portfolio] ' . ($subject ?: 'Pesan Baru dari ' . $name), $emailBody, $headers);

    if ($mailSent) {
        echo json_encode(['success' => true, 'message' => 'Pesan berhasil dikirim ✅']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal mengirim pesan ❌. Silakan coba lagi.']);
    }
    exit;
}

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'fachrel07juli@gmail.com';
    $mail->Password   = $smtpPassword;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('fachrel07juli@gmail.com', 'Portfolio Contact');
    $mail->addAddress('fachrel07juli@gmail.com');
    $mail->addReplyTo($email, $name);

    $mail->isHTML(true);
    $mail->Subject = '[Portfolio] ' . ($subject ?: 'Pesan Baru dari ' . $name);
    $mail->Body    = $emailBody;
    $mail->AltBody = $plainText;

    $mail->send();
    echo json_encode(['success' => true, 'message' => 'Pesan berhasil dikirim ✅']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal mengirim pesan ❌. Error: ' . $e->getMessage()]);
}
