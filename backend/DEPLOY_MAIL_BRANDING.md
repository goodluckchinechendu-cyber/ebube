# Fix OTP email showing wrong company name

## 1. Upload these files to **each** site's `api/` folder

- `config.php`
- `config.mail.brands.php`
- `mail_brand.php`
- `mail_util.php`
- `login_otp_util.php`
- `request_pin_reset.php`
- `reset_pin_page.php`

## 2. On **smobileagent.com** only — fix `api/config.mail.php`

Copy from `config.mail.smobileagent.example.php`:

```php
$mailFromName = 'SMobile Agent';
$mailFromEmail = 'no-reply@smobileagent.com';
$mailSmtpHost = 'mail.smobileagent.com';
$mailSmtpUser = 'no-reply@smobileagent.com';
$mailSmtpPass = '...'; // password for no-reply@smobileagent.com
```

## 3. Verify

Open: `https://smobileagent.com/api/api_check.php`

You should see:

- `"from_name": "SMobile Agent"`
- `"brand_slug_header": "smobileagent"` (after login from the new app)
- `"mail_brand_source": "slug:smobileagent"` or `"host:smobileagent.com"`

## 4. If you see “email could not be sent”

SMTP **password** must match the mailbox on that domain.

- On **smobileagent.com**, `config.mail.php` must use `no-reply@smobileagent.com` + its password  
  **not** Starlot’s mailbox password with Agent’s display name.

Or add `api/credentials/config.mail.smobileagent.com.php` (see `api/credentials/README.md`).

Check `https://smobileagent.com/api/api_check.php` → `smtp_pass_set: true`, `smtp_user` ends with `@smobileagent.com`.

## 5. Install the latest **smobileagent** APK

The app must send `X-SMobile-Brand-Slug: smobileagent` on login so the API picks the right name even if `config.mail.php` was copied from another site.
