# Michicast

## Local setup

Requires PHP 8.4+, Composer, Node and MySQL 8+ (or MariaDB 10.6+). The application and the test suite both run on MySQL, so development matches production; there is no SQLite fallback.

1. Create a schema for development and one for tests:

```sql
CREATE DATABASE michicast;
CREATE DATABASE michicast_test;
```

2. Install dependencies and create the environment file:

```sh
composer install
php -r "file_exists('.env') || copy('.env.example', '.env');"
php artisan key:generate
```

3. Set `DB_USERNAME` and `DB_PASSWORD` in `.env` to match your MySQL user, then migrate:

```sh
php artisan migrate
```

4. Build the frontend and run the suite. Tests use the `michicast_test` schema configured in `phpunit.xml`:

```sh
npm install
npm run build
php artisan test
```

5. Point Git at the project's hooks so Cursor attribution trailers are stripped from commit messages:

```sh
git config core.hooksPath .githooks
```

## Scheduler

Production uses a single cron entry. The Laravel scheduler ticks every minute and runs due projects inline; there is no queue worker.

```cron
* * * * * cd /path/to/michicast && php artisan schedule:run >> /dev/null 2>&1
```

That cron entry is the hand-installed path, and it stays. Droplet deploy: [docs/deploy.md](docs/deploy.md).


## Checks

Run these before committing; CI enforces the same three. The workflow in `.github/workflows/ci.yml` runs them on every pull request against a MySQL service container.

```sh
composer test      # PHPUnit against the michicast_test schema
composer lint      # Pint in check mode, reports violations without fixing
composer format    # Pint, fixes style in place
composer analyse   # PHPStan with the Larastan extension at level 5
```

`composer update` does not run `boost:update`. When you deliberately want Boost's stock guidelines or skills refreshed, run `php artisan boost:update` yourself and review the diff before committing. That rewrites only the `<laravel-boost-guidelines>` block at the top of `AGENTS.md`; the house rules below it are preserved. Do not run `php artisan boost:install` — Boost is already installed, and the installer rewrites `AGENTS.md` and the skills under `.cursor/skills/` from scratch.

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
