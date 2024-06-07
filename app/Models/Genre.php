<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Genre extends Model
{
    use HasFactory;

    public $fillable = [
        'value',
    ];

    protected $hidden = [
        'pivot',
    ];

    public $timestamps = false;

    public function books(): BelongsToMany
    {
        return $this->BelongsToMany(Book::class);
    }
}
