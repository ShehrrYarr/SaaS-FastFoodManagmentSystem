<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleShopsSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Creates the first Super Admin on a production install (never seed sample data there),
| or resets an existing Super Admin's password.
*/
Artisan::command('sfms:super-admin {email? : Sign-in email} {--name= : Display name}', function () {
    $email = Str::lower(trim((string) ($this->argument('email') ?: $this->ask('Email'))));

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Enter a valid email address.');

        return 1;
    }

    $existing = User::query()->where('email', $email)->whereNull('tenant_id')->first();

    if (User::query()->where('email', $email)->whereNotNull('tenant_id')->exists()) {
        $this->error('That email belongs to shop staff. Use another email for the Super Admin.');

        return 1;
    }

    $name = $this->option('name') ?: ($existing?->name ?? $this->ask('Name', 'Super Admin'));
    $password = (string) $this->secret('Password (at least 8 characters)');

    if (mb_strlen($password) < 8 || $password !== (string) $this->secret('Password again')) {
        $this->error('The passwords must match and be at least 8 characters.');

        return 1;
    }

    User::query()->updateOrCreate(
        ['email' => $email, 'tenant_id' => null],
        ['name' => $name, 'password' => $password, 'is_super_admin' => true, 'is_active' => true],
    );

    $this->info(($existing ? 'Updated' : 'Created').' Super Admin '.$email.'. Sign in at '.url('/super-admin/login'));

    return 0;
})->purpose('Create a Super Admin (or reset one\'s password)');

/*
| Public demo: wipes the database and builds fresh sample data (two weeks of history,
| today open). Runs nightly from cron on the demo server. Only allowed when
| SFMS_DEMO_SHOP is set. Real Super Admins are kept; every sample login gets a random
| password (the demo is entered with the one-tap buttons), so the README logins never work.
*/
Artisan::command('sfms:demo-reset {--force : Do not ask for confirmation}', function () {
    if (blank(config('sfms.demo_shop'))) {
        $this->error('This deletes the whole database, so it only runs on a demo install (set SFMS_DEMO_SHOP in .env).');

        return 1;
    }

    $database = config('database.connections.'.config('database.default').'.database');

    if (! $this->option('force') && ! $this->confirm("Delete ALL data in [{$database}] and build fresh demo data?")) {
        return 1;
    }

    $admins = Schema::hasTable('users')
        ? DB::table('users')->whereNull('tenant_id')->where('is_super_admin', true)->where('email', '!=', 'admin@sfms.test')
            ->get()->map(fn ($row) => Arr::except((array) $row, ['id']))->all()
        : [];

    $this->call('down', ['--retry' => 60]);

    try {
        $this->call('migrate:fresh', ['--force' => true]);

        DatabaseSeeder::$withSampleSuperAdmin = false;
        SampleShopsSeeder::$password = null;
        $this->call('db:seed', ['--force' => true]);

        foreach ($admins as $row) {
            DB::table('users')->insert($row);
        }
    } finally {
        $this->call('up');
    }

    $this->info('Demo rebuilt. Kept '.count($admins).' Super Admin(s).');

    return 0;
})->purpose('Rebuild the public demo (deletes all data; demo installs only)');

/*
| Fills in REVERB_APP_ID / REVERB_APP_KEY / REVERB_APP_SECRET in .env (like key:generate),
| so live updates work after `copy .env.example .env`. Existing values are kept unless --force.
*/
Artisan::command('sfms:live-keys {--force : Replace keys that are already set}', function () {
    $path = base_path('.env');

    if (! is_file($path)) {
        $this->error('No .env file. Copy .env.example to .env first.');

        return 1;
    }

    $env = file_get_contents($path);
    $values = [
        'REVERB_APP_ID' => (string) random_int(100000, 999999),
        'REVERB_APP_KEY' => Str::lower(Str::random(20)),
        'REVERB_APP_SECRET' => Str::lower(Str::random(32)),
    ];
    $changed = [];

    foreach ($values as $name => $value) {
        if (preg_match("/^{$name}=(.*)$/m", $env, $match)) {
            if (trim($match[1], " \"'") !== '' && ! $this->option('force')) {
                continue;
            }
            $env = preg_replace("/^{$name}=.*$/m", "{$name}={$value}", $env);
        } else {
            $env = rtrim($env)."\n{$name}={$value}\n";
        }
        $changed[] = $name;
    }

    if ($changed === []) {
        $this->info('Live update keys are already set (use --force to replace them).');

        return 0;
    }

    file_put_contents($path, $env);
    $this->info('Set '.implode(', ', $changed).'. Restart `php artisan reverb:start` if it is running.');

    return 0;
})->purpose('Generate the Reverb keys for live updates in .env');
