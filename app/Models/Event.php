<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;



class Event extends Model
{
    use HasFactory;



    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'registrations','event_id','user_id');
    }





}
