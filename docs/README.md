# Hopp fork of Two-Factor

This repository is a fork of [WordPress/two-factor](https://github.com/WordPress/two-factor). Everything not listed here behaves exactly as upstream does; see the root `readme.md` and `AGENTS.md` for the plugin itself.

Keep fork changes in fork-only files (this folder, `tests/class-two-factor-force-all-users.php`, `tests/class-two-factor-second-factor-security.php`) where possible, so syncing with upstream stays a clean merge.

## Fork changes

### Require two-factor for all users

**Settings → Two-Factor → Site-wide Enforcement → "Require for all users"** (option `two_factor_force_all_users`).

- Users with no two-factor method get email codes (`Two_Factor_Email`) by default. This is enforced when providers are read, through the `two_factor_enabled_providers_for_user` filter (`two_factor_maybe_force_email_for_user()` in `two-factor.php`), so it applies immediately.
- A background WP-Cron job (`two_factor_apply_force_all_users_batch`) also saves email as the method for existing users, 100 at a time, paging by user ID. New users are enrolled on `user_register`.
- **Users without a valid email address are not enforced**, because they could never receive a code. They keep password-only login. The settings page shows how many users the last run could not enrol (option `two_factor_force_all_users_skipped`).
- Turning the setting off does not remove methods users already have.

### Validating a code outside wp-login.php

Code that runs its own login flow must call:

```php
$result = Two_Factor_Core::validate_second_factor( $user, 'Two_Factor_Totp' ); // true|WP_Error
```

Do **not** call a provider's `validate_authentication()` directly. The providers do no rate limiting, so a direct call allows unlimited code guessing. `validate_second_factor()` applies the same checks as the login form:

- the provider must be enabled for the user
- attempts are rate limited and counted
- `wp_login_failed` fires on a rejected code
- repeated failures can trigger the compromised-password reset

The provider reads the code from the request field it uses on the login form: `authcode` for TOTP, `two-factor-email-code` for email.

The `Two_Factor_Email` and `Two_Factor_Totp` constructors are public in this fork, because older calling code instantiates them directly. Use `::get_instance()` instead. The constructors can go back to `protected`, as upstream has them, once no calling code uses `new`.

### Removed: skipping 2FA for a headless frontend

Earlier fork versions skipped the second factor when the request's `Origin` or `Referer` header matched a configured frontend URL (`frontend_settings` / `faustwp_settings`), and one version did not register the `wp_login` hook at all. Clients control those headers, so this was a two-factor bypass. It has been removed, and `wp_login()` matches upstream. `test_wp_login_ignores_spoofed_frontend_origin` guards against it coming back.

## Syncing with upstream

1. `git fetch upstream` and merge `upstream/master` into a `sync/…` branch.
2. Keep `.github/workflows/deploy.yml` deleted. It publishes to WordPress.org; this fork releases through `tag-archive.yml`.
3. Rebuild the lockfiles from upstream's, then run `npm audit` and `composer audit`. Scope `package.json` overrides to the package that needs them. Global overrides of `minimatch` / `brace-expansion` broke ESLint 10.
4. Run `npm run env start`, `npm test`, `npm run lint`.
