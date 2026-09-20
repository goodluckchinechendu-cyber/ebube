# Per-domain SMTP credentials

If `api/config.mail.php` has the wrong domain’s password, OTP email fails with
“verification started but email could not be sent”.

## Fix for smobileagent.com

1. Copy `config.mail.smobileagent.com.example.php` to `config.mail.smobileagent.com.php`
2. Set `$mailSmtpPass` to the cPanel password for `no-reply@smobileagent.com`
3. Upload this folder to the server: `api/credentials/`

**Or** replace the whole `api/config.mail.php` on that server with `config.mail.smobileagent.example.php` values.

## Check

Open `https://smobileagent.com/api/api_check.php` and confirm:

- `smtp_configured`: true
- `smtp_pass_set`: true
- `from_name`: "SMobile Agent"
- `smtp_user`: "no-reply@smobileagent.com"
