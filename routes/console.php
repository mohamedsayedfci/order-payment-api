<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

Artisan::command('jwt:secret {--force : Replace an existing signing secret and invalidate all tokens}', function (): int {
    $path = base_path('.env');
    if (! is_file($path)) {
        $this->error('Copy .env.example to .env first.');
        return 1;
    }
    $contents = file_get_contents($path);
    if (preg_match('/^JWT_SECRET=(.+)$/m', $contents) && ! $this->option('force')) {
        $this->error('JWT_SECRET is already set. Use --force to rotate it.');
        return 1;
    }
    $line = 'JWT_SECRET='.bin2hex(random_bytes(32));
    $updated = preg_match('/^JWT_SECRET=.*$/m', $contents)
        ? preg_replace('/^JWT_SECRET=.*$/m', $line, $contents)
        : $contents.PHP_EOL.$line.PHP_EOL;
    if (file_put_contents($path, $updated, LOCK_EX) === false) {
        $this->error('Unable to write .env.');
        return 1;
    }
    $this->info('JWT secret generated. Run php artisan config:clear if configuration was cached.');
    return 0;
})->purpose('Generate a cryptographically random JWT signing secret');
