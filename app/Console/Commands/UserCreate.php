<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class UserCreate extends Command
{
    protected $signature = 'user:create {email : Login email} {--name= : Display name, defaults to the part of the email before @}';

    protected $description = 'Create the user who logs into the web UI, or reset their password';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) $this->secret('Password');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:8']],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $existed = $user->exists;
        $user->name = $this->option('name') ?: ($user->name ?? strstr($email, '@', true));
        $user->password = $password;
        $user->save();

        $this->info($existed ? "Password updated for {$email}." : "User {$email} created.");

        return self::SUCCESS;
    }
}
