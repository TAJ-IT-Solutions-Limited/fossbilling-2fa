# TAJHOST Two-Factor Authentication for FOSSBilling

Two-factor authentication (2FA) for [FOSSBilling](https://fossbilling.org) staff and clients, using standard authenticator apps. It also generates one-time backup codes.

Built and maintained by [TAJHOST](https://tajhost.com), the web hosting and IT services brand of TAJ IT Solutions Limited, a company registered in Malta. TAJHOST runs this extension on its own FOSSBilling installation.

## Features

- **TOTP (RFC 6238).** Works with Google Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden, and any other TOTP app. Setup uses a QR code or a manually entered key.
- **Backup codes.** Each account gets 10 single-use recovery codes. They are stored hashed with `password_hash()` and shown only once. Users can regenerate them.
- **Staff.** 2FA can be made **mandatory** (the default). A staff member without 2FA is redirected to the setup page until they enable it, and cannot turn it off. The module also refuses other admin API calls when FOSSBilling fires `onBeforeAdminApiCall`. Version 0.8.9 does not fire that event, so the page redirect remains the enforcement that actually runs there.
- **Clients.** 2FA is **optional**. It is turned on from the client area, and a card on the dashboard suggests enabling it.
- **Brute-force protection:**
  - After 5 invalid codes the account's 2FA is locked for 15 minutes. The counter is kept. At 10 failures the lock is 30 minutes, and at 15 or more it is 60 minutes.
  - An older or already used time step is rejected. A reused code tells the user to wait for the next code, and it does not count toward the lock. A code that is still inside the clock window is never old enough to be treated as a new login.
  - Each failed code also counts against FOSSBilling's existing per-IP `api_login` limit.
- **Secrets encrypted at rest** with FOSSBilling's built-in `crypt` service.
- **Administration.** Staff with the `manage` permission can see who has 2FA and reset it for a staff member or client who lost their phone.
- **No core changes.** The extension uses only FOSSBilling's official extension points:
  - login events (`onAfterAdminLogin` and `onAfterClientLogin`)
  - widget slots
  - the module API
- **No external services, no Composer dependencies.** It works fully offline, including QR code generation.

## Requirements

- FOSSBilling **0.8.x only, not 2.x yet.** The minimum is **0.8.3** because the login field uses the widget system. The extension directory does not accept a maximum version, so the ceiling is this document, not `manifest.json`.
- PHP 8.2 or newer.
- This package does not ship an icon. The grey file under `src/assets/` is a placeholder for a later directory release and is left out of the release archive.

## Installation

### From the FOSSBilling admin panel

1. Go to **Extensions**.
2. Find **TAJHOST Two-Factor Authentication**.
3. Click **Install**, then **Activate**.

### Manually

1. Download the latest `tajhost2fa-x.y.z.zip` from the [Releases](../../releases) page.
2. Extract it to `modules/Tajhost2fa/` in your FOSSBilling installation. `manifest.json` must end up at `modules/Tajhost2fa/manifest.json`.
3. In the admin panel, go to **Extensions → Tajhost2fa → Activate**.

After activation, every staff member is asked to set up 2FA on their next page load. Set up your own account first.

## How it works

1. The user enters email and password as usual.
2. FOSSBilling verifies the password and fires `onAfterAdminLogin` or `onAfterClientLogin`, before it creates the session.
3. If the account has 2FA enabled, the module checks the `tajhost2fa_code` field. If the field is missing, it aborts the login with error `2001`.
4. The login form starts with the code field hidden. It opens on any login error, and an **I have a 2FA code** link is always visible, so a change in the error text cannot hide the field. Error `2001` still opens the field without an error toast.
5. If the code is wrong, the login is aborted with error `2002`. After too many attempts it returns `2003` (locked).

Because the check runs **after** the password check, the login form never reveals whether an account has 2FA enabled to someone who doesn't know the password.

### API logins

Integrations that call the login API directly must send `tajhost2fa_code` with `email` and `password`:

```json
POST /api/guest/client/login
{ "email": "user@example.com", "password": "…", "tajhost2fa_code": "123456" }
```

API **tokens** (HTTP Basic auth with an API key) are machine credentials and are not affected.

## Settings

Go to **System → Two-Factor Authentication**. The page is also linked as the module's settings page under Extensions.

| Setting | Default | Description |
|---|---|---|
| Require 2FA for all staff members | On | Forces every staff member to enrol, and prevents them from disabling it. |
| Allow clients to enable 2FA | On | When off, clients cannot enrol, and existing client 2FA is no longer checked. |
| Name shown in the authenticator app | Company name | The "issuer" label shown in the app. |

## Recovery

- **A client or staff member lost their phone:** they can sign in with a backup code. Otherwise, a staff member with the `manage` permission opens **System → Two-Factor Authentication** and clicks **Reset**. A staff member cannot reset their own 2FA. The reset is logged with the acting admin and emailed to the account. Saving settings also requires the `manage` permission.
- **The only administrator is locked out:** remove their 2FA from the database, then set it up again:

  ```sql
  DELETE FROM tajhost2fa WHERE user_type = 'admin' AND user_id = 1;
  ```

## Module API

| Role | Method | Description |
|---|---|---|
| admin / client | `tajhost2fa_status` | Own 2FA status |
| admin / client | `tajhost2fa_setup_start` | Start enrolment (returns the secret and `otpauth://` URI) |
| admin / client | `tajhost2fa_setup_confirm` (`code`) | Finish enrolment (returns the backup codes) |
| admin / client | `tajhost2fa_disable` (`code`) | Disable own 2FA |
| admin / client | `tajhost2fa_backup_codes_regenerate` (`code`) | New backup codes |
| admin | `tajhost2fa_settings` | Current settings |
| admin (manage) | `tajhost2fa_staff_overview` | 2FA status of all staff |
| admin (manage) | `tajhost2fa_client_list` | Clients with 2FA enabled |
| admin (manage) | `tajhost2fa_staff_reset` (`id`) / `tajhost2fa_client_reset` (`id`) | Remove 2FA from an account |
| admin (manage) | `tajhost2fa_client_status` (`id`) | 2FA status of one client |

## Known limitations

- **Login history.** FOSSBilling's login history is written when the password is verified. A login attempt that fails the 2FA step can therefore still appear in *Activity → Login history*.
- **Custom themes.** A theme that removes the `admin.staff.login.form.after` or `client.page.login.form.after` widget slots from its login templates also removes the code field. Custom login forms can instead add an input named `tajhost2fa_code`.

## About TAJHOST

[TAJHOST](https://tajhost.com) provides cPanel hosting, domain registration, SSL certificates and business email, together with IT infrastructure and security services. TAJHOST is a brand of TAJ IT Solutions Limited (registered in Malta, company no. C 117054). For questions, bug reports or custom FOSSBilling development, open an issue or contact us through [tajhost.com](https://tajhost.com).

## License

Apache License 2.0. See [LICENSE](LICENSE).

This product includes [QR Code Generator for JavaScript](https://github.com/kazuhikoarase/qrcode-generator) by Kazuhiko Arase, licensed under the MIT license (`src/assets/qrcode.min.js`).
