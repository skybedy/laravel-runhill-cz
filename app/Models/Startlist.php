<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Startlist extends Model
{


    public function startlist($eventId){
        return  User::select('users.lastname', 'users.firstname', 'categories.name')
        ->join('registrations', 'users.id', '=', 'registrations.user_id')
        ->join('events', 'events.id', '=', 'registrations.event_id')
        ->join('categories', 'registrations.category_id', '=', 'categories.id')
        ->where('events.id', $eventId)
        ->orderBy('users.lastname', 'asc')
        ->get();
    }

}
