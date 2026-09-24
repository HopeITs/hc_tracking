<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Each user's inbox is pushed on their own private channel. Nobody else may
| subscribe to it.
*/
Broadcast::channel('App.Models.User.{id}', fn (User $user, int|string $id): bool => $user->id === (int) $id);
