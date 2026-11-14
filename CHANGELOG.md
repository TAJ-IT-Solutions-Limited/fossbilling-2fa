# Changelog

## 1.0.1

- Register `manage_settings` so FOSSBilling 0.8.9 shows the settings page, and require the manage permission before settings are returned.
- Open the login code field on any login error, and keep an "I have a 2FA code" link visible on the staff and client login forms.
- Refuse a staff member resetting their own 2FA. Log the acting admin and email the affected account from the company email address.
- Reject a reused authenticator step. Tell the user to wait for the next code, and do not count that reuse toward the lock.
- Keep the failed-attempt counter when locking. Escalate the lock from 15 to 30 to 60 minutes, and count failed codes on the existing per-IP `api_login` limit.
- Add `assertAdminApiAllowed` for a before-admin-API event. FOSSBilling 0.8.9 does not fire that event.
- State that this release is for FOSSBilling 0.8.x and not 2.x.
- Ship `icon.svg` at the module root and point `icon_url` at it. The grey `assets/icon.svg` placeholder stays out of the archive.
- `php bin/package.php` builds `tajhost2fa-<version>.zip` with the module files at the archive root and no wrapping folder.

## 1.0.0

- First release.
