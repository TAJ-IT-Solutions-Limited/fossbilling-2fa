# Changelog

## 1.0.1

- Register `manage_settings` so FOSSBilling 0.8.9 shows the settings page, and require the manage permission before settings are returned.
- Open the login code field on any login error, and keep an "I have a 2FA code" link visible on the staff and client login forms.
- Refuse a staff member resetting their own 2FA. Log the acting admin and email the affected account.
- Accept the same authenticator step for a new login after 90 seconds. Reject reuse of that step within 90 seconds, and still reject older steps.
- Keep the failed-attempt counter when locking. Escalate the lock from 15 to 30 to 60 minutes, and count failed codes on the existing per-IP `api_login` limit.
- Add `assertAdminApiAllowed` for a before-admin-API event. FOSSBilling 0.8.9 does not fire that event.
- State that this release is for FOSSBilling 0.8.x and not 2.x.
- Point `icon_url` at a placeholder `assets/icon.svg`.

## 1.0.0

- First release.
