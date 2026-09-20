<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail_util.php';

// config.php defaults to JSON; this endpoint serves HTML.
header('Content-Type: text/html; charset=UTF-8');

$brandName = htmlspecialchars(app_brand_display_name(), ENT_QUOTES, 'UTF-8');
$token = htmlspecialchars($_GET['token'] ?? '', ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars($_GET['email'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reset <?php echo $brandName; ?> PIN</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f3f4f6; margin: 0; }
    .card { max-width: 420px; margin: 48px auto; background: #fff; padding: 24px; border-radius: 12px; box-shadow: 0 6px 20px rgba(0,0,0,.08); }
    h1 { margin: 0 0 8px; font-size: 22px; color: #1f2937; }
    p { color: #4b5563; font-size: 14px; margin: 0 0 16px; }
    label { display: block; margin: 10px 0 6px; color: #374151; font-weight: 600; font-size: 14px; }
    input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
    button { width: 100%; margin-top: 16px; background: #004A8F; color: #fff; border: 0; border-radius: 8px; padding: 12px; font-size: 15px; cursor: pointer; }
    .msg { margin-top: 12px; font-size: 14px; }
    .ok { color: #166534; }
    .err { color: #b91c1c; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Reset your PIN</h1>
    <p>Choose a new 4-digit PIN for your <?php echo $brandName; ?> account.</p>

    <input id="token" type="hidden" value="<?php echo $token; ?>">

    <label for="email">Email</label>
    <input id="email" type="email" value="<?php echo $email; ?>" placeholder="you@example.com">

    <label for="pin">New 4-digit PIN</label>
    <input id="pin" type="password" maxlength="4" inputmode="numeric" placeholder="••••">

    <label for="confirmPin">Confirm PIN</label>
    <input id="confirmPin" type="password" maxlength="4" inputmode="numeric" placeholder="••••">

    <button id="submitBtn" type="button">Reset PIN</button>
    <div id="msg" class="msg"></div>
  </div>

  <script>
    const submitBtn = document.getElementById('submitBtn');
    const msg = document.getElementById('msg');

    function setMessage(text, ok) {
      msg.textContent = text;
      msg.className = `msg ${ok ? 'ok' : 'err'}`;
    }

    submitBtn.addEventListener('click', async () => {
      const token = document.getElementById('token').value.trim();
      const email = document.getElementById('email').value.trim();
      const pin = document.getElementById('pin').value.trim();
      const confirmPin = document.getElementById('confirmPin').value.trim();

      if (!token) {
        setMessage('Reset token is missing from this link.', false);
        return;
      }

      submitBtn.disabled = true;
      setMessage('Submitting...', true);

      try {
        const res = await fetch('reset_pin.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            token: token,
            email: email,
            pin: pin,
            confirm_pin: confirmPin
          })
        });
        const json = await res.json();
        setMessage(json.message || (json.success ? 'PIN updated' : 'Failed'), !!json.success);
      } catch (e) {
        setMessage('Network error. Please try again.', false);
      } finally {
        submitBtn.disabled = false;
      }
    });
  </script>
</body>
</html>
