<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountStatusService
{
    public function deactivate(User|array $accounts): array
    {
        return $this->changeStatus($accounts, true);
    }

    public function activate(User|array $accounts): array
    {
        return $this->changeStatus($accounts, false);
    }

    private function changeStatus(User|array $accounts, bool $deactivate): array
    {
        return DB::transaction(function () use ($accounts, $deactivate): array {
            $ids = $accounts instanceof User ? [$accounts->getKey()] : array_values(array_unique($accounts));
            $users = User::whereKey($ids)->lockForUpdate()->get();
            $changed = 0;
            $skipped = count($ids) - $users->count();

            foreach ($users as $user) {
                if (! in_array($user->role, [User::ROLE_STUDENT, User::ROLE_FACULTY], true)
                    || $user->isDeactivated() === $deactivate) {
                    $skipped++;

                    continue;
                }

                $user->deactivated_at = $deactivate ? now() : null;
                if ($deactivate) {
                    $user->remember_token = Str::random(60);
                }
                $user->save();

                if ($deactivate && config('session.driver') === 'database') {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }

                $changed++;
            }

            return ['changed' => $changed, 'skipped' => $skipped];
        });
    }
}
