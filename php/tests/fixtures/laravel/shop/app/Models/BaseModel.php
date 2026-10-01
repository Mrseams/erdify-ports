<?php

namespace App\Models;

use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    use HasTags;
}
