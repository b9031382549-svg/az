<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * Set a user's password from the command line — how a deployment replaces the seeded
 * admin / admin. --stdin reads it from a pipe, for a job where nobody can type into a
 * prompt: echo "$ADMIN_PASSWORD" | php artisan user:password admin --stdin
 */
class SetUserPassword extends Command
{
    protected $signature = 'user:password
        {login : The login, e.g. admin}
        {--stdin : Read the password from standard input instead of asking}';

    protected $description = 'Set a user\'s password (e.g. replace the seeded admin / admin)';

    public function handle(): int
    {
        $login = (string) $this->argument('login');
        $user = User::where('email', $login)->first(); // the login is stored in `email`
        if ($user === null) {
            $this->error("No user with the login \"{$login}\".");

            return self::FAILURE;
        }

        $password = $this->option('stdin')
            ? rtrim((string) stream_get_contents(STDIN), "\r\n")
            : (string) $this->secret('New password');

        if (mb_strlen($password) < 12) {
            $this->error('The password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user->password = $password; // hashed by the model's cast
        $user->save();
        Audit::log('auth.password_set', ['login' => $login, 'via' => 'console'], $user);

        $this->info("Password set for {$login}.");

        return self::SUCCESS;
    }
}
